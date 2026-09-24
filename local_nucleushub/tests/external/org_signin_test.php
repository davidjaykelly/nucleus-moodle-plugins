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

namespace local_nucleushub\external;

use core\oauth2\api;
use core\oauth2\endpoint;
use core\oauth2\issuer;
use core\oauth2\user_field_mapping;
use core_external\external_api;
use local_nucleushub\local\org_signin;

/**
 * Tests for the organisation sign-in web services (ADR-023 section 5).
 *
 * Discovery is never fetched from the network here: every fetch is a
 * mocked curl response. A good set-up fetches the discovery document
 * twice (Nucleus's own check, then core's discovery); a failed check
 * fetches it once.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(configure_org_signin::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(disable_org_signin::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(signin_status::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(org_signin::class)]
final class org_signin_test extends \advanced_testcase {
    /** @var string An Entra tenant. */
    private const TENANT = '0f5c1a6e-2b3d-4c5e-8f90-a1b2c3d4e5f6';

    /** @var string A client id. */
    private const CLIENTID = '6a1e9c3b-7d2f-4e8a-9b1c-2d3e4f5a6b7c';

    /** @var string A client secret, which must never show up in an error. */
    private const SECRET = 'Zx8Q~s3cr3t.Value-that_must~never~leak';

    /**
     * A discovery document.
     *
     * @param string $issuer
     * @param array $overrides Keys to change; null removes one.
     * @return string JSON
     */
    private static function document(string $issuer, array $overrides = []): string {
        $doc = [
            'issuer' => $issuer,
            'authorization_endpoint' => 'https://idp.example.com/oauth2/v2.0/authorize',
            'token_endpoint' => 'https://idp.example.com/oauth2/v2.0/token',
            'userinfo_endpoint' => 'https://idp.example.com/oidc/userinfo',
            'end_session_endpoint' => 'https://idp.example.com/oauth2/v2.0/logout',
            'jwks_uri' => 'https://idp.example.com/discovery/v2.0/keys',
            'scopes_supported' => ['openid', 'profile', 'email', 'offline_access'],
        ];
        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($doc[$key]);
            } else {
                $doc[$key] = $value;
            }
        }
        return json_encode($doc);
    }

    /**
     * Queue the two discovery fetches of a good set-up.
     *
     * @param string $provider
     */
    private static function mock_good_discovery(string $provider = 'microsoft'): void {
        $issuer = match ($provider) {
            'microsoft' => 'https://login.microsoftonline.com/' . self::TENANT . '/v2.0',
            'google' => 'https://accounts.google.com',
            default => 'https://idp.example.com',
        };
        \curl::mock_response(self::document($issuer));
        \curl::mock_response(self::document($issuer));
    }

    /**
     * Good parameters for Microsoft.
     *
     * @param array $overrides
     * @return array
     */
    private static function params(array $overrides = []): array {
        return $overrides + [
            'provider' => 'microsoft',
            'clientid' => self::CLIENTID,
            'clientsecret' => self::SECRET,
            'tenantid' => self::TENANT,
            'discoveryurl' => '',
            'domains' => 'contoso.org',
            'displayname' => 'Contoso',
        ];
    }

    /**
     * Call configure_org_signin with named parameters.
     *
     * @param array $params
     * @return array The cleaned result.
     */
    private static function configure(array $params): array {
        return external_api::clean_returnvalue(
            configure_org_signin::execute_returns(),
            configure_org_signin::execute(
                $params['provider'],
                $params['clientid'],
                $params['clientsecret'],
                $params['tenantid'],
                $params['discoveryurl'],
                $params['domains'],
                $params['displayname']
            )
        );
    }

    /**
     * Assert a call fails with an error code, leaves no issuer, and never
     * shows the secret.
     *
     * @param array $params
     * @param string $errorcode
     */
    private function assert_refused(array $params, string $errorcode): void {
        try {
            self::configure($params);
            $this->fail('Expected ' . $errorcode);
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage() . ' ' . ($e->debuginfo ?? ''));
        }
    }

    /**
     * Microsoft: one custom OpenID Connect issuer on the tenant's own
     * authority, set up as the spec says, and OAuth 2 sign-in turned on.
     */
    public function test_microsoft_creates_one_tenant_issuer(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('auth', '');
        self::mock_good_discovery();

        $result = self::configure(self::params(['domains' => ' Contoso.org, @fabrikam.org ,contoso.org']));

        $this->assertTrue($result['ok']);
        $this->assertSame($CFG->wwwroot . '/admin/oauth2callback.php', $result['redirecturi']);
        $issuer = new issuer($result['issuerid']);
        $this->assertSame('https://login.microsoftonline.com/' . self::TENANT . '/v2.0', $issuer->get('baseurl'));
        $this->assertStringNotContainsString('common', $issuer->get('baseurl'));
        $this->assertEmpty($issuer->get('servicetype'));
        $this->assertEquals(issuer::LOGINONLY, $issuer->get('showonloginpage'));
        $this->assertEmpty($issuer->get('requireconfirmation'));
        $this->assertNotEmpty($issuer->get('enabled'));
        $this->assertSame('openid profile email', $issuer->get('loginscopes'));
        $this->assertSame('openid profile email', $issuer->get('loginscopesoffline'));
        $this->assertSame('contoso.org,fabrikam.org', $issuer->get('alloweddomains'));
        $this->assertSame('Contoso', $issuer->get('name'));
        $this->assertSame('Sign in with Contoso', $issuer->get_display_name());
        $this->assertSame(self::CLIENTID, $issuer->get('clientid'));
        $this->assertSame(self::SECRET, $issuer->get('clientsecret'));

        // Endpoints and the standard OpenID Connect field mappings, from core's discovery.
        $this->assertSame('https://idp.example.com/oauth2/v2.0/authorize', $issuer->get_endpoint_url('authorization'));
        $this->assertNotEmpty($issuer->get_endpoint_url('token'));
        $this->assertNotEmpty($issuer->get_endpoint_url('userinfo'));
        $this->assertSame(
            'https://login.microsoftonline.com/' . self::TENANT . '/v2.0/.well-known/openid-configuration',
            $issuer->get_endpoint_url('discovery')
        );
        $map = [];
        foreach (user_field_mapping::get_records(['issuerid' => $issuer->get('id')]) as $mapping) {
            $map[$mapping->get('externalfield')] = $mapping->get('internalfield');
        }
        $this->assertSame('email', $map['email']);
        $this->assertSame('firstname', $map['given_name']);
        $this->assertSame('lastname', $map['family_name']);
        $this->assertArrayNotHasKey('sub', $map);

        $this->assertTrue($issuer->is_available_for_login());
        $this->assertTrue(is_enabled_auth('oauth2'));
        $this->assertEquals($issuer->get('id'), get_config('local_nucleushub', org_signin::CONFIG_ISSUERID));
        $this->assertSame('microsoft', get_config('local_nucleushub', org_signin::CONFIG_PROVIDER));
        $this->assertCount(1, api::get_all_issuers(true));
    }

    /**
     * Calling again updates the same issuer, secret included, whatever the
     * provider, and never makes a second one.
     */
    public function test_configure_again_updates_in_place(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        self::mock_good_discovery();
        $first = self::configure(self::params());

        self::mock_good_discovery();
        $second = self::configure(self::params(['clientsecret' => 'N3w~secret', 'domains' => 'contoso.org,contoso.co.uk']));
        $this->assertSame($first['issuerid'], $second['issuerid']);
        $issuer = new issuer($second['issuerid']);
        $this->assertSame('N3w~secret', $issuer->get('clientsecret'));
        $this->assertSame('contoso.org,contoso.co.uk', $issuer->get('alloweddomains'));

        self::mock_good_discovery('google');
        $google = self::configure(self::params(['provider' => 'google', 'tenantid' => '', 'displayname' => 'Contoso Google']));
        $this->assertSame($first['issuerid'], $google['issuerid']);
        $issuer = new issuer($google['issuerid']);
        $this->assertSame('google', $issuer->get('servicetype'));
        $this->assertSame('https://accounts.google.com/', $issuer->get('baseurl'));
        $this->assertSame('google', get_config('local_nucleushub', org_signin::CONFIG_PROVIDER));

        self::mock_good_discovery('oidc');
        $oidc = self::configure(self::params([
            'provider' => 'oidc',
            'tenantid' => '',
            'domains' => '',
            'discoveryurl' => 'https://IDP.example.com/realms/contoso/.well-known/openid-configuration',
        ]));
        $this->assertSame($first['issuerid'], $oidc['issuerid']);
        $issuer = new issuer($oidc['issuerid']);
        $this->assertEmpty($issuer->get('servicetype'));
        $this->assertSame('https://idp.example.com/realms/contoso', $issuer->get('baseurl'));
        $this->assertSame('', $issuer->get('alloweddomains'));
        $this->assertTrue($issuer->is_available_for_login());

        $this->assertCount(1, api::get_all_issuers(true));
    }

    /**
     * An issuer another admin made is left alone; if Nucleus's own issuer
     * was deleted, a new one is made and remembered.
     */
    public function test_only_the_managed_issuer_is_touched(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $other = new issuer(0, (object) ['name' => 'Other', 'clientid' => 'x', 'clientsecret' => 'y', 'baseurl' => '']);
        $other->create();

        self::mock_good_discovery();
        $first = self::configure(self::params());
        $this->assertNotEquals($other->get('id'), $first['issuerid']);
        api::delete_issuer($first['issuerid']);

        self::mock_good_discovery();
        $second = self::configure(self::params());
        $this->assertNotEquals($first['issuerid'], $second['issuerid']);
        $this->assertEquals($second['issuerid'], get_config('local_nucleushub', org_signin::CONFIG_ISSUERID));
        $this->assertSame('Other', (new issuer($other->get('id')))->get('name'));
        $this->assertCount(2, api::get_all_issuers(true));
    }

    /**
     * Bad input.
     *
     * @return array
     */
    public static function bad_input_provider(): array {
        $guid = '0f5c1a6e-2b3d-4c5e-8f90-a1b2c3d4e5f6';
        return [
            'unknown provider' => [['provider' => 'facebook'], 'orgsignin_badprovider'],
            'no provider' => [['provider' => ''], 'orgsignin_badprovider'],
            'no tenant' => [['tenantid' => ''], 'orgsignin_badtenant'],
            'common' => [['tenantid' => 'common'], 'orgsignin_badtenant'],
            'organizations' => [['tenantid' => 'organizations'], 'orgsignin_badtenant'],
            'consumers' => [['tenantid' => 'consumers'], 'orgsignin_badtenant'],
            'personal accounts tenant' => [['tenantid' => '9188040d-6c67-4c5b-b112-36a304b66dad'], 'orgsignin_badtenant'],
            'tenant with a path' => [['tenantid' => 'contoso.org/v2.0'], 'orgsignin_badtenant'],
            'tenant as a URL' => [['tenantid' => 'https://login.microsoftonline.com/' . $guid], 'orgsignin_badtenant'],
            'tenant with a space' => [['tenantid' => 'contoso org'], 'orgsignin_badtenant'],
            'short GUID' => [['tenantid' => '0f5c1a6e-2b3d-4c5e-8f90'], 'orgsignin_badtenant'],
            'microsoft without domains' => [['domains' => ''], 'orgsignin_baddomain'],
            'microsoft with only commas' => [['domains' => ' , ,'], 'orgsignin_baddomain'],
            'google without domains' => [['provider' => 'google', 'domains' => ''], 'orgsignin_baddomain'],
            'wildcard' => [['domains' => '*.contoso.org'], 'orgsignin_baddomain'],
            'no dot' => [['domains' => 'contoso'], 'orgsignin_baddomain'],
            'address' => [['domains' => 'ada@contoso.org'], 'orgsignin_baddomain'],
            'public email service' => [['domains' => 'contoso.org,gmail.com'], 'orgsignin_baddomain'],
            'semicolons' => [['domains' => 'contoso.org;fabrikam.org'], 'orgsignin_baddomain'],
            'oidc without URL' => [['provider' => 'oidc', 'discoveryurl' => ''], 'orgsignin_badurl'],
            'oidc over http' => [['provider' => 'oidc', 'discoveryurl' => 'http://idp.example.com'], 'orgsignin_badurl'],
            'oidc with a query' => [
                ['provider' => 'oidc', 'discoveryurl' => 'https://idp.example.com/.well-known/openid-configuration?p=x'],
                'orgsignin_badurl',
            ],
            'oidc with a fragment' => [['provider' => 'oidc', 'discoveryurl' => 'https://idp.example.com/#x'], 'orgsignin_badurl'],
            'oidc with credentials' => [
                ['provider' => 'oidc', 'discoveryurl' => 'https://a:b@idp.example.com'],
                'orgsignin_badurl',
            ],
            'oidc other well-known' => [
                ['provider' => 'oidc', 'discoveryurl' => 'https://idp.example.com/.well-known/oauth-authorization-server'],
                'orgsignin_badurl',
            ],
            'oidc not a URL' => [['provider' => 'oidc', 'discoveryurl' => 'idp.example.com'], 'orgsignin_badurl'],
            'no client id' => [['clientid' => ''], 'orgsignin_missingfield'],
            'client id with a space' => [['clientid' => 'abc def'], 'orgsignin_missingfield'],
            'no secret' => [['clientsecret' => '  '], 'orgsignin_missingfield'],
            'secret with a space' => [['clientsecret' => 'abc def'], 'orgsignin_missingfield'],
            'no name' => [['displayname' => ''], 'orgsignin_missingfield'],
            'name with markup' => [['displayname' => '<b>Contoso</b>'], 'orgsignin_missingfield'],
            'name too long' => [['displayname' => str_repeat('x', 101)], 'orgsignin_missingfield'],
            'Entra secret ID instead of its value' => [['clientsecret' => $guid], 'orgsignin_secretlooksid'],
        ];
    }

    /**
     * Bad input is refused before anything is fetched or saved, and the
     * error never shows the secret.
     *
     * @param array $overrides
     * @param string $errorcode
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_input_provider')]
    public function test_bad_input_is_refused(array $overrides, string $errorcode): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('auth', '');

        $this->assert_refused(self::params($overrides), $errorcode);
        $this->assertCount(0, api::get_all_issuers(true));
        $this->assertEmpty(get_config('local_nucleushub', org_signin::CONFIG_ISSUERID));
        $this->assertFalse(is_enabled_auth('oauth2'));
    }

    /**
     * Discovery documents that must be refused.
     *
     * @return array
     */
    public static function bad_document_provider(): array {
        $tenant = 'https://login.microsoftonline.com/0f5c1a6e-2b3d-4c5e-8f90-a1b2c3d4e5f6/v2.0';
        return [
            'empty' => [''],
            'not JSON' => ['<html>Not found</html>'],
            'Entra error for a wrong tenant' => [json_encode([
                'error' => 'invalid_tenant',
                'error_description' => 'AADSTS90002: Tenant not found.',
            ])],
            'no userinfo endpoint' => [self::document($tenant, ['userinfo_endpoint' => null])],
            'no token endpoint' => [self::document($tenant, ['token_endpoint' => null])],
            'endpoint over http' => [self::document($tenant, ['authorization_endpoint' => 'http://idp.example.com/authorize'])],
            'multi-tenant issuer' => [self::document('https://login.microsoftonline.com/{tenantid}/v2.0')],
            'issuer elsewhere' => [self::document('https://evil.example.com/v2.0')],
        ];
    }

    /**
     * A wrong tenant or URL fails with orgsignin_discoveryfailed and changes nothing.
     *
     * @param string $document
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_document_provider')]
    public function test_bad_discovery_is_refused(string $document): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('auth', '');
        \curl::mock_response($document);

        $this->assert_refused(self::params(), 'orgsignin_discoveryfailed');
        $this->assertCount(0, api::get_all_issuers(true));
        $this->assertEmpty(get_config('local_nucleushub', org_signin::CONFIG_ISSUERID));
        $this->assertFalse(is_enabled_auth('oauth2'));
    }

    /**
     * If core's own discovery then fails, the new issuer is removed.
     */
    public function test_core_discovery_failure_leaves_no_issuer(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        // Responses are served last in, first out: Nucleus's check gets
        // the good document, core's discovery the empty one.
        \curl::mock_response('');
        \curl::mock_response(self::document('https://login.microsoftonline.com/' . self::TENANT . '/v2.0'));

        $this->assert_refused(self::params(), 'orgsignin_discoveryfailed');
        $this->assertCount(0, api::get_all_issuers(true));
        $this->assertEquals(0, endpoint::count_records());
        $this->assertEmpty(get_config('local_nucleushub', org_signin::CONFIG_ISSUERID));
    }

    /**
     * A failed change leaves the working set-up exactly as it was.
     */
    public function test_failed_change_keeps_working_issuer(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        self::mock_good_discovery();
        $first = self::configure(self::params());

        \curl::mock_response('');
        $this->assert_refused(self::params([
            'tenantid' => 'fabrikam.onmicrosoft.com',
            'clientsecret' => 'Other~secret',
        ]), 'orgsignin_discoveryfailed');

        $issuer = new issuer($first['issuerid']);
        $this->assertSame('https://login.microsoftonline.com/' . self::TENANT . '/v2.0', $issuer->get('baseurl'));
        $this->assertSame(self::SECRET, $issuer->get('clientsecret'));
        $this->assertTrue($issuer->is_available_for_login());
    }

    /**
     * Disabling keeps the issuer, turns OAuth 2 sign-in off when nothing
     * else uses it, and configuring again turns both back on.
     */
    public function test_disable_and_enable_again(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('auth', 'email');
        self::mock_good_discovery();
        $first = self::configure(self::params());
        $this->assertTrue(is_enabled_auth('oauth2'));

        $this->assertTrue(disable_org_signin::execute()['ok']);
        $issuer = new issuer($first['issuerid']);
        $this->assertEmpty($issuer->get('enabled'));
        $this->assertFalse(is_enabled_auth('oauth2'));
        $this->assertTrue(is_enabled_auth('email'));
        $status = signin_status::execute();
        $this->assertTrue($status['org']['configured']);
        $this->assertFalse($status['org']['enabled']);

        // Idempotent.
        $this->assertTrue(disable_org_signin::execute()['ok']);

        self::mock_good_discovery();
        $again = self::configure(self::params());
        $this->assertSame($first['issuerid'], $again['issuerid']);
        $this->assertNotEmpty((new issuer($again['issuerid']))->get('enabled'));
        $this->assertTrue(is_enabled_auth('oauth2'));
        $this->assertTrue(signin_status::execute()['org']['enabled']);
    }

    /**
     * OAuth 2 sign-in stays on while another issuer shows on the login page.
     */
    public function test_disable_keeps_oauth2_for_other_login_issuers(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        self::mock_good_discovery();
        self::configure(self::params());
        $other = new issuer(0, (object) [
            'name' => 'Other',
            'clientid' => 'x',
            'clientsecret' => 'y',
            'baseurl' => '',
            'showonloginpage' => issuer::EVERYWHERE,
        ]);
        $other->create();
        (new endpoint(0, (object) [
            'issuerid' => $other->get('id'),
            'name' => 'userinfo_endpoint',
            'url' => 'https://other.example.com/userinfo',
        ]))->create();

        disable_org_signin::execute();
        $this->assertTrue(is_enabled_auth('oauth2'));
    }

    /**
     * Disabling with nothing set up changes nothing.
     */
    public function test_disable_without_setup(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('auth', 'email,oauth2');
        $this->assertTrue(disable_org_signin::execute()['ok']);
        $this->assertTrue(is_enabled_auth('oauth2'));
    }

    /**
     * Status before and after set-up, and self-registration.
     */
    public function test_status(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('registerauth', '');

        $status = external_api::clean_returnvalue(signin_status::execute_returns(), signin_status::execute());
        $this->assertSame([
            'configured' => false,
            'enabled' => false,
            'provider' => '',
            'displayname' => '',
            'domains' => '',
            'issuerid' => 0,
        ], $status['org']);
        $this->assertFalse($status['selfregistration']);

        set_config('registerauth', 'email');
        self::mock_good_discovery();
        $result = self::configure(self::params(['domains' => 'contoso.org,fabrikam.org']));
        $status = external_api::clean_returnvalue(signin_status::execute_returns(), signin_status::execute());
        $this->assertSame([
            'configured' => true,
            'enabled' => true,
            'provider' => 'microsoft',
            'displayname' => 'Contoso',
            'domains' => 'contoso.org,fabrikam.org',
            'issuerid' => $result['issuerid'],
        ], $status['org']);
        $this->assertTrue($status['selfregistration']);
    }

    /**
     * Only a site administrator (the control plane's account) may call these.
     */
    public function test_capability_is_required(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        foreach ([
            fn() => self::configure(self::params()),
            fn() => disable_org_signin::execute(),
            fn() => signin_status::execute(),
        ] as $call) {
            try {
                $call();
                $this->fail('A user without moodle/site:config must be refused.');
            } catch (\required_capability_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
            }
        }
        $this->assertCount(0, api::get_all_issuers(true));
    }

    /**
     * The functions are on the control-plane service and never on the
     * federation service spokes call.
     */
    public function test_functions_are_on_the_control_plane_service_only(): void {
        global $DB;
        $this->resetAfterTest();
        $sql = "SELECT sf.functionname
                  FROM {external_services_functions} sf
                  JOIN {external_services} s ON s.id = sf.externalserviceid
                 WHERE s.shortname = :shortname";
        $cp = $DB->get_fieldset_sql($sql, ['shortname' => 'nucleus_cp_hub']);
        $federation = $DB->get_fieldset_sql($sql, ['shortname' => 'nucleus_federation']);
        foreach (['local_nucleushub_configure_org_signin', 'local_nucleushub_disable_org_signin',
                'local_nucleushub_signin_status'] as $function) {
            $this->assertContains($function, $cp);
            $this->assertNotContains($function, $federation);
        }
    }
}
