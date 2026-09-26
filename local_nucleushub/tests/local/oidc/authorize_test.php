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
 * Tests for the authorisation endpoint logic: every refusal path.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(tokens::class)]
final class authorize_test extends \advanced_testcase {
    /** @var string A valid PKCE verifier. */
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    /**
     * The generator.
     *
     * @return \local_nucleushub_generator
     */
    private function generator(): \local_nucleushub_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_nucleushub');
    }

    /**
     * A good set of authorisation parameters for a client.
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
            'state' => 'state-123',
            'nonce' => 'nonce-456',
            'code_challenge' => tokens::pkce_challenge(self::VERIFIER),
            'code_challenge_method' => 'S256',
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
     * A good request goes back with a code, state and iss, and stores only
     * the code's hash, bound to everything it should be.
     */
    public function test_success_issues_bound_single_use_code(): void {
        global $DB;
        $this->resetAfterTest();
        $registered = $this->generator()->create_oidc_client();
        $user = $this->getDataGenerator()->create_user();

        $request = provider::parse_authorize_request($this->params($registered));
        $this->assertNull($request->error);
        $url = provider::complete_authorize($request, $user, 1000, false);

        $this->assertStringStartsWith($registered['client']->redirecturi . '?', $url->out(false));
        $query = $this->query($url);
        $this->assertMatchesRegularExpression(tokens::TOKEN_PATTERN, $query['code']);
        $this->assertSame('state-123', $query['state']);
        $this->assertSame(provider::issuer(), $query['iss']);

        $this->assertFalse($DB->record_exists(tokens::CODE_TABLE, ['codehash' => $query['code']]));
        $row = $DB->get_record(tokens::CODE_TABLE, ['codehash' => hash('sha256', $query['code'])], '*', MUST_EXIST);
        $this->assertSame($registered['clientid'], $row->clientid);
        $this->assertEquals($user->id, $row->userid);
        $this->assertSame($registered['client']->redirecturi, $row->redirecturi);
        $this->assertSame('nonce-456', $row->nonce);
        $this->assertSame(tokens::pkce_challenge(self::VERIFIER), $row->codechallenge);
        $this->assertEquals(1000, $row->authtime);
        $this->assertLessThanOrEqual(time() + 60, (int) $row->expires);
        $this->assertGreaterThan(time() + 50, (int) $row->expires);

        // The user's opaque subject is made here, ready for the token endpoint.
        $this->assertMatchesRegularExpression(subjects::PATTERN, subjects::find_for_user((int) $user->id));
    }

    /**
     * Requests that must show an error page and never redirect.
     *
     * @return array
     */
    public static function bad_client_provider(): array {
        return [
            'no client_id' => [['client_id' => null]],
            'unknown client_id' => [['client_id' => 'nucleus-unknown']],
            'client_id in the wrong case' => [['client_id' => 'UPPER']],
            'repeated client_id' => [['client_id' => ['a', 'b']]],
            'no redirect_uri' => [['redirect_uri' => null]],
            'empty redirect_uri' => [['redirect_uri' => '']],
            'another redirect_uri' => [['redirect_uri' => 'https://evil.example.com/auth/nucleus/callback.php']],
            'trailing slash' => [['redirect_uri' => 'SUFFIX:/']],
            'extra query' => [['redirect_uri' => 'SUFFIX:?x=1']],
            'fragment' => [['redirect_uri' => 'SUFFIX:#x']],
            'upper-case host' => [['redirect_uri' => 'UPPERHOST']],
            'http instead of https' => [['redirect_uri' => 'HTTP']],
        ];
    }

    /**
     * An unknown client or a redirect URI that isn't exactly the registered one
     * is an error page, not a redirect.
     *
     * @param array $overrides
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_client_provider')]
    public function test_bad_client_or_redirect_uri_throws(array $overrides): void {
        $this->resetAfterTest();
        $registered = $this->generator()->create_oidc_client();
        $registered2 = $this->generator()->create_oidc_client();
        $uri = $registered['client']->redirecturi;

        if (($overrides['client_id'] ?? '') === 'UPPER') {
            $overrides['client_id'] = strtoupper($registered['clientid']);
        }
        $redirect = $overrides['redirect_uri'] ?? null;
        if (is_string($redirect) && str_starts_with($redirect, 'SUFFIX:')) {
            $overrides['redirect_uri'] = $uri . substr($redirect, 7);
        } else if ($redirect === 'UPPERHOST') {
            $host = (string) parse_url($uri, PHP_URL_HOST);
            $overrides['redirect_uri'] = str_replace($host, strtoupper($host), $uri);
        } else if ($redirect === 'HTTP') {
            $overrides['redirect_uri'] = str_replace('https://', 'http://', $uri);
        }
        // Another client's own redirect URI isn't good enough either.
        $this->assertNotSame($uri, $registered2['client']->redirecturi);

        $this->expectException(\moodle_exception::class);
        provider::parse_authorize_request($this->params($registered, $overrides));
    }

    /**
     * Another registered client's redirect URI is refused.
     */
    public function test_other_clients_redirect_uri_throws(): void {
        $this->resetAfterTest();
        $registered = $this->generator()->create_oidc_client();
        $other = $this->generator()->create_oidc_client();

        $this->expectException(\moodle_exception::class);
        provider::parse_authorize_request($this->params($registered, ['redirect_uri' => $other['client']->redirecturi]));
    }

    /**
     * A client whose spoke isn't active is unknown.
     *
     * @return array
     */
    public static function inactive_spoke_provider(): array {
        return [['suspended'], ['removed']];
    }

    /**
     * Suspending or removing the spoke stops its sign-in at once.
     *
     * @param string $status
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('inactive_spoke_provider')]
    public function test_inactive_spoke_throws(string $status): void {
        $this->resetAfterTest();
        $registered = $this->generator()->create_oidc_client();
        $this->generator()->set_spoke_status($registered['spoke'], $status);

        $this->expectException(\moodle_exception::class);
        provider::parse_authorize_request($this->params($registered));
    }

    /**
     * Requests that go back to the client with an error.
     *
     * @return array
     */
    public static function bad_request_provider(): array {
        return [
            'no response_type' => [['response_type' => null], 'invalid_request'],
            'implicit' => [['response_type' => 'token'], 'unsupported_response_type'],
            'id_token' => [['response_type' => 'id_token'], 'unsupported_response_type'],
            'hybrid' => [['response_type' => 'code id_token'], 'unsupported_response_type'],
            'repeated response_type' => [['response_type' => ['code', 'code']], 'invalid_request'],
            'request object' => [['request' => 'eyJ.x.y'], 'request_not_supported'],
            'request_uri' => [['request_uri' => 'https://evil.example.com/r'], 'request_uri_not_supported'],
            'fragment response mode' => [['response_mode' => 'fragment'], 'invalid_request'],
            'form_post response mode' => [['response_mode' => 'form_post'], 'invalid_request'],
            'no scope' => [['scope' => null], 'invalid_scope'],
            'no openid scope' => [['scope' => 'profile email'], 'invalid_scope'],
            'openid as a substring only' => [['scope' => 'openidx profile'], 'invalid_scope'],
            'no state' => [['state' => null], 'invalid_request'],
            'empty state' => [['state' => ''], 'invalid_request'],
            'state with a newline' => [['state' => "a\nb"], 'invalid_request'],
            'state too long' => [['state' => str_repeat('s', 513)], 'invalid_request'],
            'no nonce' => [['nonce' => null], 'invalid_request'],
            'empty nonce' => [['nonce' => ''], 'invalid_request'],
            'nonce too long' => [['nonce' => str_repeat('n', 256)], 'invalid_request'],
            'no code_challenge' => [['code_challenge' => null], 'invalid_request'],
            'short code_challenge' => [['code_challenge' => 'abc'], 'invalid_request'],
            'code_challenge with padding' => [['code_challenge' => str_repeat('a', 42) . '='], 'invalid_request'],
            'no code_challenge_method' => [['code_challenge_method' => null], 'invalid_request'],
            'plain code_challenge_method' => [['code_challenge_method' => 'plain'], 'invalid_request'],
            'lower-case s256' => [['code_challenge_method' => 's256'], 'invalid_request'],
        ];
    }

    /**
     * Bad parameters go back to the registered redirect URI with the error,
     * the state (when usable) and iss, and never issue a code.
     *
     * @param array $overrides
     * @param string $error
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_request_provider')]
    public function test_bad_request_redirects_with_error(array $overrides, string $error): void {
        global $DB;
        $this->resetAfterTest();
        $registered = $this->generator()->create_oidc_client();

        $request = provider::parse_authorize_request($this->params($registered, $overrides));
        $this->assertSame($error, $request->error);

        $url = provider::authorize_error_url($request);
        $this->assertStringStartsWith($registered['client']->redirecturi . '?', $url->out(false));
        $query = $this->query($url);
        $this->assertSame($error, $query['error']);
        $this->assertNotEmpty($query['error_description']);
        $this->assertSame(provider::issuer(), $query['iss']);
        if (!array_key_exists('state', $overrides)) {
            $this->assertSame('state-123', $query['state']);
        } else {
            $this->assertArrayNotHasKey('state', $query);
        }
        $this->assertArrayNotHasKey('code', $query);
        $this->assertSame(0, $DB->count_records(tokens::CODE_TABLE));
    }

    /**
     * A request with an error can't be completed.
     */
    public function test_complete_refuses_request_with_error(): void {
        $this->resetAfterTest();
        $registered = $this->generator()->create_oidc_client();
        $user = $this->getDataGenerator()->create_user();
        $request = provider::parse_authorize_request($this->params($registered, ['nonce' => null]));

        $this->expectException(\coding_exception::class);
        provider::complete_authorize($request, $user, time(), false);
    }

    /**
     * Accounts that must be refused.
     *
     * @return array
     */
    public static function refused_user_provider(): array {
        return [
            'suspended' => [['suspended' => 1]],
            'unconfirmed' => [['confirmed' => 0]],
            'deleted' => [['deleted' => 1]],
            'webservice account' => [['auth' => 'webservice']],
            'nologin account' => [['auth' => 'nologin']],
            'auth plugin turned off' => [['auth' => 'email']],
        ];
    }

    /**
     * Refused accounts get access_denied and no code.
     *
     * @param array $record
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refused_user_provider')]
    public function test_refused_user_gets_access_denied(array $record): void {
        global $DB;
        $this->resetAfterTest();
        set_config('auth', '');
        $registered = $this->generator()->create_oidc_client();
        $user = $this->getDataGenerator()->create_user($record);
        // The generator may not honour every flag, so set them directly.
        foreach ($record as $field => $value) {
            $DB->set_field('user', $field, $value, ['id' => $user->id]);
        }
        $user = $DB->get_record('user', ['id' => $user->id]);

        $this->assert_access_denied($registered, $user, false);
    }

    /**
     * The guest is refused.
     */
    public function test_guest_gets_access_denied(): void {
        $this->resetAfterTest();
        $registered = $this->generator()->create_oidc_client();
        $this->assert_access_denied($registered, guest_user(), false);
    }

    /**
     * A "Log in as" session is refused, even for an account that could sign in.
     */
    public function test_loggedinas_gets_access_denied(): void {
        $this->resetAfterTest();
        $registered = $this->generator()->create_oidc_client();
        $user = $this->getDataGenerator()->create_user();
        $this->assert_access_denied($registered, $user, true);
    }

    /**
     * No user at all is refused.
     */
    public function test_missing_user_gets_access_denied(): void {
        $this->resetAfterTest();
        $registered = $this->generator()->create_oidc_client();
        $this->assert_access_denied($registered, (object) ['id' => 0], false);
    }

    /**
     * Assert the request ends in access_denied with state and iss, and no code.
     *
     * @param array $registered
     * @param \stdClass $user
     * @param bool $loggedinas
     */
    private function assert_access_denied(array $registered, \stdClass $user, bool $loggedinas): void {
        global $DB;
        $request = provider::parse_authorize_request($this->params($registered));
        $url = provider::complete_authorize($request, $user, time(), $loggedinas);
        $query = $this->query($url);
        $this->assertSame('access_denied', $query['error']);
        $this->assertSame('state-123', $query['state']);
        $this->assertSame(provider::issuer(), $query['iss']);
        $this->assertArrayNotHasKey('code', $query);
        $this->assertSame(0, $DB->count_records(tokens::CODE_TABLE));
        // A refused account gets no subject.
        $this->assertNull(subjects::find_for_user((int) ($user->id ?? 0)));
    }

    /**
     * The issuer (pinned from the wwwroot at install) and the endpoints
     * never come from the request's host.
     */
    public function test_issuer_is_built_from_wwwroot(): void {
        global $CFG;
        $_SERVER['HTTP_HOST'] = 'internal.cluster.local';
        $this->assertSame($CFG->wwwroot . '/local/nucleushub/oidc', provider::issuer());
        $discovery = provider::discovery();
        $this->assertSame(provider::issuer(), $discovery['issuer']);
        $this->assertSame(['code'], $discovery['response_types_supported']);
        $this->assertSame(['S256'], $discovery['code_challenge_methods_supported']);
        $this->assertSame(['RS256'], $discovery['id_token_signing_alg_values_supported']);
        $this->assertSame(['authorization_code'], $discovery['grant_types_supported']);
        $this->assertSame(['client_secret_basic', 'client_secret_post'], $discovery['token_endpoint_auth_methods_supported']);
        $this->assertStringStartsWith($CFG->wwwroot . '/', $discovery['token_endpoint']);
    }
}
