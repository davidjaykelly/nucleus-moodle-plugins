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

namespace auth_nucleus\local;

use local_nucleuscommon\transport\hub_http;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/authlib.php');

/**
 * The hub's sign-in endpoints.
 *
 * The browser goes to authorize.php and end_session.php on the hub's
 * current address (local_nucleusspoke hubwwwroot). That's the issuer's
 * host until the hub moves to a new address; its issuer stays pinned to
 * the original one, so links and tokens don't change. The code exchange
 * and the key fetch are named under the issuer and go server to server
 * over the hub connection this spoke already has (hubwwwroot and
 * hubconnecturl), so they reach the hub even where its public address
 * doesn't resolve from here, and only ever the hub.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hub {
    /** @var string Scopes asked for. */
    public const SCOPE = 'openid profile email';

    /**
     * An endpoint under the issuer, for the server-to-server calls
     * (token.php and jwks.php). {@see hub_http::internal_url()} sends them
     * to the hub connection.
     *
     * @param string $file e.g. 'token.php'.
     * @return string
     */
    public static function endpoint(string $file): string {
        return config::issuer() . '/' . $file;
    }

    /**
     * An endpoint the browser is sent to (authorize.php, end_session.php),
     * on the hub's current address: {hubwwwroot}/local/nucleushub/oidc/{file}.
     *
     * Without a hub connection, it's under the issuer as before. Sign-in
     * can't start then ({@see issuer_is_on_hub()}), but signing out of the
     * hub still goes where it always did.
     *
     * @param string $file e.g. 'authorize.php'.
     * @return string
     */
    public static function browser_endpoint(string $file): string {
        try {
            return hub_http::from_spoke_config()->endpoint($file);
        } catch (\moodle_exception $e) {
            return self::endpoint($file);
        }
    }

    /**
     * The transport to the hub, from the spoke's hub connection.
     *
     * @return hub_http
     * @throws signin_exception If the spoke has no hub connection.
     */
    private static function http(): hub_http {
        try {
            return hub_http::from_spoke_config(15);
        } catch (\moodle_exception $e) {
            throw new signin_exception('notenabled', AUTH_LOGIN_FAILED, 'this spoke has no hub connection');
        }
    }

    /**
     * Is the configured issuer exactly the issuer of the hub this spoke is
     * connected to (its pinned hubissuer, or {hubwwwroot}/local/nucleushub/oidc
     * when there isn't one)?
     *
     * Sign-in only ever talks to that hub.
     *
     * @return bool
     */
    public static function issuer_is_on_hub(): bool {
        $issuer = config::issuer();
        if ($issuer === '') {
            return false;
        }
        try {
            return hub_http::from_spoke_config()->is_issuer($issuer);
        } catch (\moodle_exception $e) {
            return false;
        }
    }

    /**
     * Where to send the browser to sign in.
     *
     * @param \stdClass $flow From {@see flow::start()}.
     * @return \moodle_url
     */
    public static function authorize_url(\stdClass $flow): \moodle_url {
        return new \moodle_url(self::browser_endpoint('authorize.php'), [
            'client_id' => config::clientid(),
            'redirect_uri' => config::redirect_uri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'state' => $flow->state,
            'nonce' => $flow->nonce,
            'code_challenge' => $flow->challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Where to send the browser to sign out of the hub.
     *
     * @param string|null $idtoken The ID token from sign-in, if the session has it.
     * @return \moodle_url
     */
    public static function end_session_url(?string $idtoken): \moodle_url {
        global $CFG;
        $params = ['post_logout_redirect_uri' => $CFG->wwwroot . '/'];
        if ($idtoken !== null && $idtoken !== '') {
            $params['id_token_hint'] = $idtoken;
        }
        return new \moodle_url(self::browser_endpoint('end_session.php'), $params);
    }

    /**
     * Exchange an authorisation code for an ID token.
     *
     * Client authentication is client_secret_post. The secret is sent
     * only here, and only to the hub.
     *
     * @param string $code The code from the callback.
     * @param string $verifier The flow's PKCE verifier.
     * @return string The ID token (not yet verified).
     * @throws signin_exception
     */
    public static function exchange_code(string $code, string $verifier): string {
        $secret = config::clientsecret();
        if ($secret === '' || !self::issuer_is_on_hub()) {
            throw new signin_exception('notenabled', AUTH_LOGIN_FAILED,
                'client secret missing or undecryptable, or issuer not on this spoke\'s hub');
        }

        try {
            $response = self::http()->post_form(self::endpoint('token.php'), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => config::redirect_uri(),
                'code_verifier' => $verifier,
                'client_id' => config::clientid(),
                'client_secret' => $secret,
            ]);
        } catch (signin_exception $e) {
            throw $e;
        } catch (\moodle_exception $e) {
            throw new signin_exception('hubunreachable', AUTH_LOGIN_FAILED, 'token endpoint unreachable');
        }

        if ($response['status'] !== 200) {
            $error = clean_param((string) ($response['json']['error'] ?? ''), PARAM_ALPHANUMEXT);
            throw new signin_exception('hubrefused', AUTH_LOGIN_FAILED,
                'token endpoint returned HTTP ' . $response['status'] . ($error !== '' ? ' ' . $error : ''));
        }
        $idtoken = $response['json']['id_token'] ?? null;
        if (!is_string($idtoken) || $idtoken === '') {
            throw new signin_exception('badtoken', AUTH_LOGIN_FAILED, 'token response had no id_token');
        }
        return $idtoken;
    }

    /**
     * Fetch the hub's public keys (jwks.php).
     *
     * @return array The JWKS document.
     * @throws signin_exception
     */
    public static function fetch_jwks(): array {
        try {
            return self::http()->get_json(self::endpoint('jwks.php'));
        } catch (signin_exception $e) {
            throw $e;
        } catch (\moodle_exception $e) {
            throw new signin_exception('hubunreachable', AUTH_LOGIN_FAILED, 'jwks endpoint unreachable');
        }
    }
}
