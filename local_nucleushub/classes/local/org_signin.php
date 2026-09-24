<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_nucleushub\local;

use core\oauth2\api;
use core\oauth2\issuer;

/**
 * Sign in with your organisation (ADR-023 section 5, phase 3 spec section 1).
 *
 * The hub signs people in with the organisation's own provider through
 * Moodle's core `auth_oauth2`. Nucleus manages exactly one issuer, whose
 * id is kept in `local_nucleushub/org_issuerid`, and updates it in place.
 *
 * - `microsoft`: a custom OpenID Connect issuer on the tenant's own
 *   authority, `https://login.microsoftonline.com/{tenant}/v2.0`. Not
 *   core's Microsoft template, which uses the multi-tenant `common`
 *   authority and maps `sub` to idnumber.
 * - `google`: core's standard Google issuer (`servicetype` google).
 * - `oidc`: a custom issuer from the provider's discovery URL.
 *
 * Core only discovers endpoints when an issuer is created. So this class
 * checks the provider's discovery document itself first (nothing
 * changes if that fails), then saves the issuer and runs core's
 * discovery every time, and checks the endpoints core stored.
 *
 * The client secret is never put in an error, a log or a return value.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class org_signin {
    /** @var string Config holding the managed issuer's id. */
    public const CONFIG_ISSUERID = 'org_issuerid';

    /** @var string Config holding the provider the issuer was set up for. */
    public const CONFIG_PROVIDER = 'org_provider';

    /** @var string[] Providers. */
    public const PROVIDERS = ['microsoft', 'google', 'oidc'];

    /** @var string Scopes asked for at sign-in. */
    public const SCOPES = 'openid profile email';

    /** @var string Microsoft's sign-in authority. */
    public const MICROSOFT_AUTHORITY = 'https://login.microsoftonline.com/';

    /** @var string The personal Microsoft accounts tenant, never an organisation. */
    private const MICROSOFT_CONSUMERS_TENANT = '9188040d-6c67-4c5b-b112-36a304b66dad';

    /** @var string A GUID. */
    private const GUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** @var string Suffix of an OpenID Connect discovery URL. */
    private const WELL_KNOWN = '/.well-known/openid-configuration';

    /**
     * @var string[] Public email services: as an allowed domain they would
     *      let anyone with a free account sign in.
     */
    private const PUBLIC_EMAIL_DOMAINS = [
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'hotmail.co.uk', 'live.com', 'live.co.uk',
        'msn.com', 'yahoo.com', 'yahoo.co.uk', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me',
        'protonmail.com', 'gmx.com', 'mail.com', 'yandex.com', 'zoho.com',
    ];

    /** @var string[] Endpoints sign-in needs from discovery. */
    private const REQUIRED_ENDPOINTS = ['authorization', 'token', 'userinfo'];

    /** @var int Most email domains accepted. */
    private const MAX_DOMAINS = 50;

    /**
     * Set up (or change) the organisation's sign-in on the hub.
     *
     * @param string $provider microsoft, google or oidc.
     * @param string $clientid
     * @param string $clientsecret
     * @param string $tenantid Entra tenant (GUID or verified domain); microsoft only.
     * @param string $discoveryurl Discovery URL or its base; oidc only.
     * @param string $domains Comma-separated email domains; required for microsoft and google.
     * @param string $displayname Name on the login button.
     * @return array{ok: bool, issuerid: int, redirecturi: string}
     * @throws \moodle_exception orgsignin_* codes.
     */
    public static function configure(
        string $provider,
        string $clientid,
        string $clientsecret,
        string $tenantid,
        string $discoveryurl,
        string $domains,
        string $displayname
    ): array {
        require_capability('moodle/site:config', \context_system::instance());
        $settings = self::settings($provider, $clientid, $clientsecret, $tenantid, $discoveryurl, $domains, $displayname);
        $provider = $settings['provider'];

        // Check the provider answers before changing anything, so a wrong
        // tenant or URL never breaks a working set-up.
        self::check_discovery_document($settings['baseurl'], $provider);

        $existing = self::managed_issuer();
        try {
            if ($existing) {
                $issuer = $existing;
                foreach ($settings['fields'] as $name => $value) {
                    $issuer->set($name, $value);
                }
                $issuer->update();
            } else {
                $issuer = new issuer(0, (object) $settings['fields']);
                $issuer->create();
            }
        } catch (\Throwable $e) {
            // Core's message could quote what it was given: never pass it on.
            self::log_failure('save', $e);
            throw new \moodle_exception('orgsignin_savefailed', 'local_nucleushub');
        }

        try {
            // Core deletes the issuer's endpoints, fetches the discovery
            // document again and stores the endpoints and field mappings.
            api::create_endpoints_for_standard_issuer((string) ($settings['fields']['servicetype'] ?? ''), $issuer);
            foreach (self::REQUIRED_ENDPOINTS as $endpoint) {
                if (!$issuer->get_endpoint_url($endpoint)) {
                    throw new \moodle_exception('orgsignin_discoveryfailed', 'local_nucleushub');
                }
            }
        } catch (\Throwable $e) {
            self::log_failure('discovery', $e);
            if (!$existing) {
                // Nothing half-made is left behind.
                try {
                    api::delete_issuer($issuer->get('id'));
                } catch (\Throwable $ignored) {
                    self::log_failure('cleanup', $ignored);
                }
            }
            // An existing issuer without endpoints doesn't show on the
            // login page; configuring again fixes it.
            throw new \moodle_exception('orgsignin_discoveryfailed', 'local_nucleushub');
        }

        set_config(self::CONFIG_ISSUERID, (int) $issuer->get('id'), 'local_nucleushub');
        set_config(self::CONFIG_PROVIDER, $provider, 'local_nucleushub');
        self::enable_auth_plugin(true);

        return [
            'ok' => true,
            'issuerid' => (int) $issuer->get('id'),
            'redirecturi' => self::redirect_uri(),
        ];
    }

    /**
     * Turn the organisation's sign-in off, keeping the issuer so it can be
     * turned on again.
     *
     * `auth_oauth2` is turned off too, unless another enabled issuer still
     * shows on the login page. Turning it off ends the sessions of every
     * account that signs in with it (core does this). With no issuer
     * managed by Nucleus, nothing changes.
     *
     * @return void
     */
    public static function disable(): void {
        require_capability('moodle/site:config', \context_system::instance());
        $issuer = self::managed_issuer();
        if (!$issuer) {
            return;
        }
        if ($issuer->get('enabled')) {
            api::disable_issuer($issuer->get('id'));
        }
        if (!self::other_login_issuer_exists((int) $issuer->get('id'))) {
            self::enable_auth_plugin(false);
        }
    }

    /**
     * The organisation's sign-in as it stands, and whether the hub lets
     * anyone create an account.
     *
     * @return array{org: array, selfregistration: bool}
     */
    public static function status(): array {
        global $CFG;

        $issuer = self::managed_issuer();
        $org = [
            'configured' => false,
            'enabled' => false,
            'provider' => '',
            'displayname' => '',
            'domains' => '',
            'issuerid' => 0,
        ];
        if ($issuer) {
            $org = [
                'configured' => true,
                'enabled' => (bool) $issuer->get('enabled') && $issuer->is_available_for_login() && is_enabled_auth('oauth2'),
                'provider' => (string) (get_config('local_nucleushub', self::CONFIG_PROVIDER) ?: ''),
                'displayname' => (string) $issuer->get('name'),
                'domains' => (string) $issuer->get('alloweddomains'),
                'issuerid' => (int) $issuer->get('id'),
            ];
        }
        return [
            'org' => $org,
            'selfregistration' => !empty($CFG->registerauth),
        ];
    }

    /**
     * The URI the organisation registers with its provider.
     *
     * @return string
     */
    public static function redirect_uri(): string {
        global $CFG;
        return $CFG->wwwroot . '/admin/oauth2callback.php';
    }

    /**
     * The issuer Nucleus manages, if it still exists.
     *
     * @return issuer|null
     */
    public static function managed_issuer(): ?issuer {
        $id = (int) get_config('local_nucleushub', self::CONFIG_ISSUERID);
        if ($id <= 0) {
            return null;
        }
        $issuer = issuer::get_record(['id' => $id]);
        return $issuer ?: null;
    }

    /**
     * Check and normalise the input, and work out the issuer's fields.
     *
     * @param string $provider
     * @param string $clientid
     * @param string $clientsecret
     * @param string $tenantid
     * @param string $discoveryurl
     * @param string $domains
     * @param string $displayname
     * @return array{provider: string, baseurl: string, fields: array}
     * @throws \moodle_exception orgsignin_* codes.
     */
    public static function settings(
        string $provider,
        string $clientid,
        string $clientsecret,
        string $tenantid,
        string $discoveryurl,
        string $domains,
        string $displayname
    ): array {
        $provider = trim($provider);
        if (!in_array($provider, self::PROVIDERS, true)) {
            throw new \moodle_exception('orgsignin_badprovider', 'local_nucleushub');
        }
        $displayname = trim($displayname);
        if (
            $displayname === '' || \core_text::strlen($displayname) > 100
            || $displayname !== clean_param($displayname, PARAM_TEXT)
        ) {
            throw new \moodle_exception('orgsignin_missingfield', 'local_nucleushub', '', 'displayname');
        }
        $clientid = trim($clientid);
        if (!self::is_credential($clientid)) {
            throw new \moodle_exception('orgsignin_missingfield', 'local_nucleushub', '', 'clientid');
        }
        $clientsecret = trim($clientsecret);
        if (!self::is_credential($clientsecret)) {
            throw new \moodle_exception('orgsignin_missingfield', 'local_nucleushub', '', 'clientsecret');
        }

        $alloweddomains = self::normalise_domains($domains);
        if (!$alloweddomains && $provider !== 'oidc') {
            throw new \moodle_exception('orgsignin_baddomain', 'local_nucleushub');
        }

        $fields = [
            'name' => $displayname,
            'loginpagename' => get_string('orgsignin_button', 'local_nucleushub', $displayname),
            'clientid' => $clientid,
            'clientsecret' => $clientsecret,
            'enabled' => 1,
            'showonloginpage' => issuer::LOGINONLY,
            'requireconfirmation' => 0,
            'basicauth' => 0,
            'loginscopes' => self::SCOPES,
            'loginscopesoffline' => self::SCOPES,
            'loginparams' => '',
            'loginparamsoffline' => '',
            'alloweddomains' => implode(',', $alloweddomains),
            'servicetype' => null,
        ];

        switch ($provider) {
            case 'microsoft':
                $tenant = self::normalise_tenant($tenantid);
                // Entra's secret "Value" never looks like a GUID; its
                // "Secret ID" does, and is the usual mistake.
                if (preg_match(self::GUID_PATTERN, $clientsecret)) {
                    throw new \moodle_exception('orgsignin_secretlooksid', 'local_nucleushub');
                }
                $baseurl = self::MICROSOFT_AUTHORITY . $tenant . '/v2.0';
                $fields['image'] = 'https://www.microsoft.com/favicon.ico';
                break;
            case 'google':
                $google = \core\oauth2\service\google::init();
                $baseurl = (string) $google->get('baseurl');
                $fields['image'] = (string) $google->get('image');
                $fields['servicetype'] = 'google';
                $fields['loginparamsoffline'] = (string) $google->get('loginparamsoffline');
                break;
            default:
                $baseurl = self::normalise_discovery_url($discoveryurl);
                $parts = parse_url($baseurl);
                $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                $fields['image'] = 'https://' . $parts['host'] . $port . '/favicon.ico';
        }
        $fields['baseurl'] = $baseurl;

        return ['provider' => $provider, 'baseurl' => $baseurl, 'fields' => $fields];
    }

    /**
     * An Entra tenant: a GUID or a verified domain, lower case.
     *
     * The multi-tenant authorities (`common`, `organizations`,
     * `consumers`) and the personal-accounts tenant are refused: they
     * would let people from any organisation sign in.
     *
     * @param string $tenantid
     * @return string
     * @throws \moodle_exception orgsignin_badtenant
     */
    public static function normalise_tenant(string $tenantid): string {
        $tenant = \core_text::strtolower(trim($tenantid));
        $isguid = (bool) preg_match(self::GUID_PATTERN, $tenant);
        $isdomain = !$isguid && strpos($tenant, '.') !== false && \core\ip_utils::is_domain_name($tenant)
            && substr($tenant, -1) !== '.';
        if ((!$isguid && !$isdomain) || $tenant === self::MICROSOFT_CONSUMERS_TENANT) {
            throw new \moodle_exception('orgsignin_badtenant', 'local_nucleushub');
        }
        return $tenant;
    }

    /**
     * Email domains as core's `alloweddomains` wants them: lower case,
     * no spaces (core doesn't trim), no duplicates.
     *
     * Plain domain names only: no wildcards, no addresses, no public
     * email services.
     *
     * @param string $domains Comma-separated; spaces and a leading @ are tolerated.
     * @return string[]
     * @throws \moodle_exception orgsignin_baddomain
     */
    public static function normalise_domains(string $domains): array {
        $result = [];
        foreach (explode(',', $domains) as $domain) {
            $domain = \core_text::strtolower(trim($domain));
            if ($domain === '') {
                continue;
            }
            $domain = ltrim($domain, '@');
            if (
                strpos($domain, '.') === false || substr($domain, -1) === '.'
                || !\core\ip_utils::is_domain_name($domain)
                || in_array($domain, self::PUBLIC_EMAIL_DOMAINS, true)
            ) {
                throw new \moodle_exception('orgsignin_baddomain', 'local_nucleushub');
            }
            $result[$domain] = $domain;
        }
        if (count($result) > self::MAX_DOMAINS) {
            throw new \moodle_exception('orgsignin_baddomain', 'local_nucleushub');
        }
        return array_values($result);
    }

    /**
     * The issuer base URL from a discovery URL (or its base).
     *
     * Core appends `/.well-known/openid-configuration` to the base, so a
     * full discovery URL loses that suffix. https only, with no query,
     * fragment or credentials, since core can't carry them.
     *
     * @param string $url
     * @return string Base URL, no trailing slash.
     * @throws \moodle_exception orgsignin_badurl
     */
    public static function normalise_discovery_url(string $url): string {
        global $CFG;
        require_once($CFG->dirroot . '/lib/validateurlsyntax.php');

        $url = trim($url);
        $parts = $url === '' ? false : parse_url($url);
        if (
            !$parts || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])
            || preg_match('/[\s<>"\'\\\\]/', $url) || strlen($url) > 1000
        ) {
            throw new \moodle_exception('orgsignin_badurl', 'local_nucleushub');
        }
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        if (str_ends_with(strtolower($path), self::WELL_KNOWN)) {
            $path = substr($path, 0, -strlen(self::WELL_KNOWN));
        }
        if (stripos($path, '/.well-known/') !== false) {
            // Some other well-known document: not one core can use.
            throw new \moodle_exception('orgsignin_badurl', 'local_nucleushub');
        }
        $base = 'https://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . $path;
        // The check core's issuer applies to its base URL (https only).
        if (!validateUrlSyntax($base, 'S+')) {
            throw new \moodle_exception('orgsignin_badurl', 'local_nucleushub');
        }
        return $base;
    }

    /**
     * Fetch and check the provider's discovery document.
     *
     * Core's own discovery doesn't look at the HTTP status, and accepts
     * any JSON: Entra answers a wrong tenant with a JSON error, which core
     * would take as a provider with no endpoints. So: status 200, JSON,
     * and https authorisation, token and userinfo endpoints. For Entra,
     * also a tenant-specific issuer. Fetched with Moodle's curl and its
     * usual URL security checks, as core's discovery is.
     *
     * @param string $baseurl
     * @param string $provider
     * @return array The document.
     * @throws \moodle_exception orgsignin_discoveryfailed
     */
    public static function check_discovery_document(string $baseurl, string $provider): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $url = rtrim($baseurl, '/') . self::WELL_KNOWN;
        $curl = new \curl();
        // Moodle's curl doesn't verify TLS certificates by default; the
        // endpoints this returns are trusted from here on, so it must.
        $curl->setopt([
            'CURLOPT_TIMEOUT' => 15,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
        ]);
        $body = $curl->get($url);
        $info = $curl->get_info();
        $fail = new \moodle_exception('orgsignin_discoveryfailed', 'local_nucleushub');
        if ($curl->get_errno() || !empty($curl->error) || (int) ($info['http_code'] ?? 0) !== 200) {
            throw $fail;
        }
        $doc = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($doc)) {
            throw $fail;
        }
        foreach (self::REQUIRED_ENDPOINTS as $endpoint) {
            $value = $doc[$endpoint . '_endpoint'] ?? null;
            if (!is_string($value) || !preg_match('#^https://[^\s]+$#i', $value)) {
                throw $fail;
            }
        }
        if ($provider === 'microsoft') {
            $iss = $doc['issuer'] ?? null;
            if (!is_string($iss) || !str_starts_with($iss, self::MICROSOFT_AUTHORITY) || str_contains($iss, '{')) {
                throw $fail;
            }
        }
        return $doc;
    }

    /**
     * Is there another enabled issuer that shows on the login page?
     *
     * @param int $exceptid The managed issuer's id.
     * @return bool
     */
    private static function other_login_issuer_exists(int $exceptid): bool {
        foreach (api::get_all_issuers(true) as $issuer) {
            if ((int) $issuer->get('id') !== $exceptid && $issuer->is_available_for_login()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Turn `auth_oauth2` on or off the way core's admin page does.
     *
     * @param bool $enabled
     * @return void
     */
    private static function enable_auth_plugin(bool $enabled): void {
        $class = \core_plugin_manager::resolve_plugininfo_class('auth');
        $class::enable_plugin('oauth2', $enabled ? 1 : 0);
    }

    /**
     * A client id or secret: 1 to 1024 visible characters, no spaces.
     *
     * @param string $value
     * @return bool
     */
    private static function is_credential(string $value): bool {
        return $value !== '' && strlen($value) <= 1024 && (bool) preg_match('/^[\x21-\x7E]+$/', $value);
    }

    /**
     * Note a failure from core in the error log: the exception's class
     * only, since its message could quote the secret.
     *
     * @param string $stage
     * @param \Throwable $e
     * @return void
     */
    private static function log_failure(string $stage, \Throwable $e): void {
        if ($e instanceof \moodle_exception && $e->module === 'local_nucleushub') {
            return;
        }
        // Always logged, whatever the debug level.
        // phpcs:ignore
        error_log('local_nucleushub org sign-in ' . $stage . ' failed: ' . get_class($e));
    }
}
