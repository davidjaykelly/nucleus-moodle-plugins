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

namespace local_nucleushub\local\oidc;

/**
 * Tests for `prompt=none` (silent sign-in): a code or an error, never a page.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(authorize_request::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_nucleushub\hook_callbacks::class)]
final class prompt_none_test extends \advanced_testcase {
    /** @var string A valid PKCE verifier. */
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    /**
     * Each test starts with a fresh session and database.
     */
    protected function setUp(): void {
        global $SESSION;
        parent::setUp();
        $this->resetAfterTest();
        unset($SESSION->tool_mfa_authenticated, $SESSION->fullysetupstrict);
    }

    /**
     * The generator.
     *
     * @return \local_nucleushub_generator
     */
    private function generator(): \local_nucleushub_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_nucleushub');
    }

    /**
     * A good `prompt=none` request's parameters for a client.
     *
     * @param array $registered From the generator's create_oidc_client().
     * @param array $overrides Values to change; null removes a parameter.
     * @return array
     */
    private function params(array $registered, array $overrides = []): array {
        $params = [
            'client_id' => $registered['clientid'],
            'redirect_uri' => $registered['client']->redirecturi,
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'state' => 'silent.state-123',
            'nonce' => 'nonce-456',
            'code_challenge' => tokens::pkce_challenge(self::VERIFIER),
            'code_challenge_method' => 'S256',
            'prompt' => 'none',
        ];
        foreach ($overrides as $name => $value) {
            if ($value === null) {
                unset($params[$name]);
            } else {
                $params[$name] = $value;
            }
        }
        return $params;
    }

    /**
     * Query parameters of a URL.
     *
     * @param \moodle_url $url
     * @return array
     */
    private function query(\moodle_url $url): array {
        parse_str((string) parse_url($url->out(false), PHP_URL_QUERY), $query);
        return $query;
    }

    /**
     * Run a `prompt=none` request for the session's user ($USER).
     *
     * @param array $registered
     * @param bool $loggedinas
     * @return array The redirect's query parameters.
     */
    private function authorize(array $registered, bool $loggedinas = false): array {
        global $USER;
        $request = provider::parse_authorize_request($this->params($registered));
        $this->assertNull($request->error);
        $this->assertTrue($request->promptnone);
        $url = provider::authorize_prompt_none($request, $USER, $loggedinas);
        $this->assertStringStartsWith($registered['client']->redirecturi . '?', $url->out(false));
        return $this->query($url);
    }

    /**
     * Assert the redirect is this error, with state and iss and no code, and
     * no code was stored.
     *
     * @param array $query
     * @param string $error
     */
    private function assert_error(array $query, string $error): void {
        global $DB;
        $this->assertSame($error, $query['error']);
        $this->assertNotEmpty($query['error_description']);
        $this->assertSame('silent.state-123', $query['state']);
        $this->assertSame(provider::issuer(), $query['iss']);
        $this->assertArrayNotHasKey('code', $query);
        $this->assertSame(0, $DB->count_records(tokens::CODE_TABLE));
    }

    /**
     * Nobody signed in to the hub: login_required, with state and iss.
     */
    public function test_not_signed_in_gets_login_required(): void {
        $registered = $this->generator()->create_oidc_client();
        $this->setUser(null);
        $this->assert_error($this->authorize($registered), 'login_required');
    }

    /**
     * The guest counts as not signed in.
     */
    public function test_guest_gets_login_required(): void {
        $registered = $this->generator()->create_oidc_client();
        $this->setGuestUser();
        $this->assert_error($this->authorize($registered), 'login_required');
    }

    /**
     * Someone signed in gets a code, exactly as the normal flow issues it.
     */
    public function test_signed_in_gets_a_code(): void {
        global $DB, $USER;
        $registered = $this->generator()->create_oidc_client();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $USER->currentlogin = 1234;

        $query = $this->authorize($registered);

        $this->assertMatchesRegularExpression(tokens::TOKEN_PATTERN, $query['code']);
        $this->assertSame('silent.state-123', $query['state']);
        $this->assertSame(provider::issuer(), $query['iss']);
        $this->assertArrayNotHasKey('error', $query);
        $row = $DB->get_record(tokens::CODE_TABLE, ['codehash' => hash('sha256', $query['code'])], '*', MUST_EXIST);
        $this->assertSame($registered['clientid'], $row->clientid);
        $this->assertEquals($user->id, $row->userid);
        $this->assertSame($registered['client']->redirecturi, $row->redirecturi);
        $this->assertSame('nonce-456', $row->nonce);
        $this->assertSame(tokens::pkce_challenge(self::VERIFIER), $row->codechallenge);
        $this->assertEquals(1234, $row->authtime);
        $this->assertMatchesRegularExpression(subjects::PATTERN, subjects::find_for_user((int) $user->id));
    }

    /**
     * `none` with any other value is invalid_request; repeating `none` is fine.
     */
    public function test_none_with_another_value_is_invalid(): void {
        $registered = $this->generator()->create_oidc_client();

        foreach (['none login', 'login none', 'none consent', 'none select_account', 'none unknown'] as $prompt) {
            $request = provider::parse_authorize_request($this->params($registered, ['prompt' => $prompt]));
            $this->assertSame('invalid_request', $request->error, $prompt);
            $query = $this->query(provider::authorize_error_url($request));
            $this->assertSame('invalid_request', $query['error']);
            $this->assertSame('silent.state-123', $query['state']);
            $this->assertSame(provider::issuer(), $query['iss']);
        }

        $request = provider::parse_authorize_request($this->params($registered, ['prompt' => 'none  none']));
        $this->assertNull($request->error);
        $this->assertTrue($request->promptnone);
    }

    /**
     * Other prompt values are ignored, as before: the normal flow.
     */
    public function test_other_prompt_values_are_ignored(): void {
        $registered = $this->generator()->create_oidc_client();

        foreach ([null, '', 'login', 'consent', 'login consent', 'select_account', 'None', ['none']] as $prompt) {
            $params = $prompt === null ? $this->params($registered, ['prompt' => null])
                : $this->params($registered, ['prompt' => $prompt]);
            $request = provider::parse_authorize_request($params);
            $this->assertNull($request->error);
            $this->assertFalse($request->promptnone);
        }
    }

    /**
     * Everything else is checked first: a bad request with prompt=none gets
     * the same error as without it.
     */
    public function test_other_errors_come_first(): void {
        $registered = $this->generator()->create_oidc_client();

        $cases = [
            'no nonce' => [['nonce' => null], 'invalid_request'],
            'implicit' => [['response_type' => 'token'], 'unsupported_response_type'],
            'no openid scope' => [['scope' => 'profile'], 'invalid_scope'],
            'plain PKCE' => [['code_challenge_method' => 'plain'], 'invalid_request'],
            'request object' => [['request' => 'eyJ.x.y'], 'request_not_supported'],
        ];
        foreach ($cases as $name => [$overrides, $error]) {
            $request = provider::parse_authorize_request($this->params($registered, $overrides));
            $this->assertSame($error, $request->error, $name);
        }
    }

    /**
     * A wrong client or redirect URI is still an error page, never a
     * redirect, with prompt=none too.
     */
    public function test_bad_client_or_redirect_uri_still_throws(): void {
        $registered = $this->generator()->create_oidc_client();
        $other = $this->generator()->create_oidc_client();

        foreach ([
            ['client_id' => 'nucleus-unknown'],
            ['redirect_uri' => 'https://evil.example.com/auth/nucleus/callback.php'],
            ['redirect_uri' => $other['client']->redirecturi],
            ['redirect_uri' => null],
        ] as $overrides) {
            try {
                provider::parse_authorize_request($this->params($registered, $overrides));
                $this->fail('Accepted ' . json_encode($overrides));
            } catch (\moodle_exception $e) {
                $this->assertSame('oidc_badclient', $e->errorcode);
            }
        }
    }

    /**
     * Accounts refused by the normal flow get access_denied, as there.
     */
    public function test_refused_accounts_get_access_denied(): void {
        global $DB;
        $registered = $this->generator()->create_oidc_client();

        $suspended = $this->getDataGenerator()->create_user();
        $DB->set_field('user', 'suspended', 1, ['id' => $suspended->id]);
        $this->setUser($suspended);
        $this->assert_error($this->authorize($registered), 'access_denied');

        $nologin = $this->getDataGenerator()->create_user(['auth' => 'nologin']);
        $this->setUser($nologin);
        $this->assert_error($this->authorize($registered), 'access_denied');

        // "Log in as" is never passed on.
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->assert_error($this->authorize($registered, true), 'access_denied');
        $this->assertNull(subjects::find_for_user((int) $user->id));
    }

    /**
     * A forced password change would show a page: interaction_required.
     */
    public function test_forced_password_change_needs_interaction(): void {
        $registered = $this->generator()->create_oidc_client();
        $user = $this->getDataGenerator()->create_user();
        set_user_preference('auth_forcepasswordchange', 1, $user);
        $this->setUser($user);
        $this->assert_error($this->authorize($registered), 'interaction_required');
    }

    /**
     * An incomplete profile would show the profile form: interaction_required.
     */
    public function test_incomplete_profile_needs_interaction(): void {
        global $DB;
        $registered = $this->generator()->create_oidc_client();
        $user = $this->getDataGenerator()->create_user();
        $DB->set_field('user', 'firstname', '', ['id' => $user->id]);
        $this->setUser($DB->get_record('user', ['id' => $user->id]));
        $this->assert_error($this->authorize($registered), 'interaction_required');
    }

    /**
     * A site policy still to accept would show it: interaction_required.
     * Site admins aren't asked, as in require_login().
     */
    public function test_site_policy_needs_interaction(): void {
        $registered = $this->generator()->create_oidc_client();
        set_config('sitepolicy', 'https://example.com/policy.html');
        $user = $this->getDataGenerator()->create_user(['policyagreed' => 0]);
        $this->setUser($user);
        $this->assert_error($this->authorize($registered), 'interaction_required');

        $this->setAdminUser();
        $query = $this->authorize($registered);
        $this->assertArrayHasKey('code', $query);
    }

    /**
     * Multi-factor authentication not yet passed: login_required. Once
     * passed, a code.
     */
    public function test_pending_mfa_needs_login(): void {
        global $SESSION;
        $registered = $this->generator()->create_oidc_client();
        set_config('enabled', 1, 'factor_nosetup');
        set_config('enabled', 0, 'factor_email');
        set_config('enabled', 1, 'tool_mfa');
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assert_error($this->authorize($registered), 'login_required');

        $SESSION->tool_mfa_authenticated = true;
        $this->assertArrayHasKey('code', $this->authorize($registered));
    }

    /**
     * tool_mfa would send a session that hasn't finished MFA to its page as
     * soon as config.php has loaded, so the hub answers first, and only
     * ever with an error.
     */
    public function test_pending_mfa_is_answered_before_tool_mfa(): void {
        global $SESSION;
        $registered = $this->generator()->create_oidc_client();
        set_config('enabled', 1, 'factor_nosetup');
        set_config('enabled', 0, 'factor_email');
        set_config('enabled', 1, 'tool_mfa');
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assert_error($this->query(provider::prompt_none_before_mfa($this->params($registered))), 'login_required');

        // A bad prompt=none request gets its own error, still never a page.
        $query = $this->query(provider::prompt_none_before_mfa($this->params($registered, ['nonce' => null])));
        $this->assertSame('invalid_request', $query['error']);
        $this->assertArrayNotHasKey('code', $query);

        // Left to tool_mfa and authorize.php: no prompt=none, a wrong client
        // or redirect URI, "Log in as", MFA done, nobody signed in.
        $this->assertNull(provider::prompt_none_before_mfa($this->params($registered, ['prompt' => null])));
        $this->assertNull(provider::prompt_none_before_mfa($this->params($registered, ['prompt' => 'login'])));
        $this->assertNull(provider::prompt_none_before_mfa($this->params($registered, ['client_id' => 'nucleus-unknown'])));
        $this->assertNull(provider::prompt_none_before_mfa(
            $this->params($registered, ['redirect_uri' => 'https://evil.example.com/auth/nucleus/callback.php'])));
        $SESSION->tool_mfa_authenticated = true;
        $this->assertNull(provider::prompt_none_before_mfa($this->params($registered)));
        unset($SESSION->tool_mfa_authenticated);
        $this->setUser(null);
        $this->assertNull(provider::prompt_none_before_mfa($this->params($registered)));
        $this->setGuestUser();
        $this->assertNull(provider::prompt_none_before_mfa($this->params($registered)));
    }

    /**
     * The hub's after_config callback runs before tool_mfa's.
     */
    public function test_after_config_runs_before_tool_mfa(): void {
        $callbacks = \core\di::get(\core\hook\manager::class)->get_callbacks_for_hook(\core\hook\after_config::class);
        $components = array_column($callbacks, 'component');
        $this->assertContains('local_nucleushub', $components);
        $this->assertContains('tool_mfa', $components);
        $this->assertLessThan(array_search('tool_mfa', $components), array_search('local_nucleushub', $components));
    }

    /**
     * Maintenance mode would show its message: temporarily_unavailable.
     */
    public function test_maintenance_mode_is_temporarily_unavailable(): void {
        $registered = $this->generator()->create_oidc_client();
        set_config('maintenance_enabled', 1);
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->assert_error($this->authorize($registered), 'temporarily_unavailable');
    }

    /**
     * Only a good prompt=none request can be answered this way.
     */
    public function test_needs_a_good_prompt_none_request(): void {
        global $USER;
        $registered = $this->generator()->create_oidc_client();
        $this->setAdminUser();

        foreach ([['prompt' => null], ['nonce' => null]] as $overrides) {
            $request = provider::parse_authorize_request($this->params($registered, $overrides));
            try {
                provider::authorize_prompt_none($request, $USER, false);
                $this->fail('Answered ' . json_encode($overrides));
            } catch (\coding_exception $e) {
                $this->assertStringContainsString('prompt=none', $e->getMessage());
            }
        }
    }

    /**
     * Discovery says prompt=none is supported, so spokes may use it.
     */
    public function test_discovery_lists_prompt_none(): void {
        $this->assertSame(['none'], provider::discovery()['prompt_values_supported']);
    }
}
