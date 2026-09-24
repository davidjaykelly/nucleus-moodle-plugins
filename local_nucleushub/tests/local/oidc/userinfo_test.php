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
 * Tests for the userinfo endpoint logic: every refusal path.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(tokens::class)]
final class userinfo_test extends \advanced_testcase {
    /** @var string The form media type. */
    private const FORM = 'application/x-www-form-urlencoded';

    /**
     * A client, a user and an access token for them.
     *
     * @return array{registered: array, user: \stdClass, token: string}
     */
    private function setup_token(): array {
        /** @var \local_nucleushub_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nucleushub');
        $registered = $generator->create_oidc_client();
        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Grace',
            'lastname' => 'Hopper',
            'email' => 'grace@example.com',
        ]);
        $token = tokens::issue_access_token($registered['client'], (int) $user->id);
        return ['registered' => $registered, 'user' => $user, 'token' => $token];
    }

    /**
     * Check a response is a Bearer error.
     *
     * @param response $response
     * @param int $status
     * @param string $error
     */
    private function assert_error(response $response, int $status, string $error): void {
        $this->assertSame($status, $response->status);
        $this->assertSame($error, $response->body['error']);
        $this->assertArrayNotHasKey('sub', $response->body);
        $this->assertArrayNotHasKey('email', $response->body);
        $this->assertStringStartsWith('Bearer', $response->headers['WWW-Authenticate']);
        $this->assertSame('no-store', $response->headers['Cache-Control']);
    }

    /**
     * A good token in the header returns sub and the profile claims.
     */
    public function test_success_with_header(): void {
        $this->resetAfterTest();
        $setup = $this->setup_token();
        foreach (['GET', 'POST'] as $method) {
            $response = provider::userinfo($method, '', [], [], 'Bearer ' . $setup['token']);
            $this->assertSame(200, $response->status);
            $this->assertMatchesRegularExpression(subjects::PATTERN, $response->body['sub']);
            $this->assertSame(subjects::find_for_user((int) $setup['user']->id), $response->body['sub']);
            $this->assertSame('grace@example.com', $response->body['email']);
            $this->assertTrue($response->body['email_verified']);
            $this->assertSame('Grace', $response->body['given_name']);
            $this->assertSame('Hopper', $response->body['family_name']);
            $this->assertSame('Grace Hopper', $response->body['name']);
            $this->assertArrayHasKey('locale', $response->body);
            $this->assertSame('no-store', $response->headers['Cache-Control']);
        }
    }

    /**
     * A good token in a POSTed form body works too.
     */
    public function test_success_with_form_body(): void {
        $this->resetAfterTest();
        $setup = $this->setup_token();
        $response = provider::userinfo('POST', self::FORM, [], ['access_token' => $setup['token']], null);
        $this->assertSame(200, $response->status);
    }

    /**
     * No token at all.
     */
    public function test_no_token_is_refused(): void {
        $this->resetAfterTest();
        $this->setup_token();
        $response = provider::userinfo('GET', '', [], [], null);
        $this->assert_error($response, 401, 'invalid_token');
        $this->assertStringNotContainsString('error=', $response->headers['WWW-Authenticate']);

        $this->assert_error(provider::userinfo('GET', '', [], [], 'Basic abc'), 401, 'invalid_token');
        $this->assert_error(provider::userinfo('GET', '', [], [], 'Bearer'), 401, 'invalid_token');
    }

    /**
     * A token in the query string is refused, even a good one.
     */
    public function test_token_in_query_is_refused(): void {
        $this->resetAfterTest();
        $setup = $this->setup_token();
        $response = provider::userinfo('GET', '', ['access_token' => $setup['token']], [], null);
        $this->assert_error($response, 400, 'invalid_request');
    }

    /**
     * A token sent twice is refused.
     */
    public function test_two_tokens_are_refused(): void {
        $this->resetAfterTest();
        $setup = $this->setup_token();
        $response = provider::userinfo('POST', self::FORM, [], ['access_token' => $setup['token']], 'Bearer ' . $setup['token']);
        $this->assert_error($response, 400, 'invalid_request');
    }

    /**
     * A body token in a GET, or in a body that isn't a form, doesn't count.
     */
    public function test_body_token_needs_a_form_post(): void {
        $this->resetAfterTest();
        $setup = $this->setup_token();
        $this->assert_error(
            provider::userinfo('GET', self::FORM, [], ['access_token' => $setup['token']], null),
            401,
            'invalid_token'
        );
        $this->assert_error(
            provider::userinfo('POST', 'application/json', [], ['access_token' => $setup['token']], null),
            401,
            'invalid_token'
        );
    }

    /**
     * Only GET and POST.
     */
    public function test_other_methods_are_refused(): void {
        $this->resetAfterTest();
        $setup = $this->setup_token();
        $this->assert_error(provider::userinfo('DELETE', '', [], [], 'Bearer ' . $setup['token']), 400, 'invalid_request');
    }

    /**
     * Unknown, malformed and hashed tokens are refused.
     */
    public function test_unknown_token_is_refused(): void {
        $this->resetAfterTest();
        $setup = $this->setup_token();
        foreach ([tokens::random(), 'short', hash('sha256', $setup['token'])] as $token) {
            $this->assert_error(provider::userinfo('GET', '', [], [], 'Bearer ' . $token), 401, 'invalid_token');
        }
    }

    /**
     * An expired token is refused.
     */
    public function test_expired_token_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $setup = $this->setup_token();
        $DB->set_field(tokens::TOKEN_TABLE, 'expires', time(), []);
        $this->assert_error(provider::userinfo('GET', '', [], [], 'Bearer ' . $setup['token']), 401, 'invalid_token');
    }

    /**
     * A token for a spoke that is no longer active, or whose client was deleted, is refused.
     */
    public function test_inactive_client_token_is_refused(): void {
        $this->resetAfterTest();
        $setup = $this->setup_token();
        /** @var \local_nucleushub_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nucleushub');
        $generator->set_spoke_status($setup['registered']['spoke'], 'suspended');
        $this->assert_error(provider::userinfo('GET', '', [], [], 'Bearer ' . $setup['token']), 401, 'invalid_token');

        $generator->set_spoke_status($setup['registered']['spoke'], 'active');
        $this->assertSame(200, provider::userinfo('GET', '', [], [], 'Bearer ' . $setup['token'])->status);
        client_registry::delete($setup['registered']['spoke']->cpspokeid);
        $this->assert_error(provider::userinfo('GET', '', [], [], 'Bearer ' . $setup['token']), 401, 'invalid_token');
    }

    /**
     * A token for an account that stopped being allowed is refused.
     */
    public function test_user_no_longer_allowed_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $setup = $this->setup_token();
        $DB->set_field('user', 'suspended', 1, ['id' => $setup['user']->id]);
        $this->assert_error(provider::userinfo('GET', '', [], [], 'Bearer ' . $setup['token']), 401, 'invalid_token');
    }
}
