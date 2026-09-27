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

namespace auth_nucleus;

use auth_nucleus\local\callback;
use auth_nucleus\local\config;
use auth_nucleus\local\flow;
use auth_nucleus\local\jwks;
use auth_nucleus\local\silent;
use Firebase\JWT\JWT;

/**
 * callback.php: the hub's answer to a silent check, and to the Log in button.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(callback::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(silent::class)]
final class callback_test extends \advanced_testcase {
    /** @var string The hub's address. */
    private const HUB = 'https://hub.example.com';

    /** @var string The hub's issuer. */
    private const ISSUER = self::HUB . '/local/nucleushub/oidc';

    /** @var string */
    private const CLIENTID = 'client-1';

    /** @var string A well-formed code. */
    private const CODE = 'code-0123456789-abcdefghijklmnopqrstuvwxyz';

    /** @var array|null [private PEM, public JWK] generated once for the class. */
    private static ?array $key = null;

    /**
     * A spoke with hub sign-in set up, the hub's keys cached, and a visitor
     * who isn't signed in.
     */
    protected function setUp(): void {
        global $SESSION;
        parent::setUp();
        $this->resetAfterTest();
        unset($SESSION->auth_nucleus_flows, $SESSION->{silent::CHECKEDKEY}, $SESSION->loginerrormsg,
            $SESSION->{flow::IDTOKENKEY}, $_COOKIE[silent::signed_out_cookie()]);

        set_config('hubwwwroot', self::HUB, 'local_nucleusspoke');
        config::save(self::ISSUER, self::CLIENTID, 'the-client-secret', 'Acme Hub', true, true);
        config::set_enabled(true);
        \curl::mock_response(json_encode(['keys' => [self::key()[1]]]));
        $this->assertArrayHasKey('k1', (new jwks(self::ISSUER))->keys());
        $this->setUser(null);
    }

    /**
     * The hub's signing key: [private PEM, public JWK].
     *
     * @return array
     */
    private static function key(): array {
        if (self::$key === null) {
            $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($resource, $privatepem);
            $details = openssl_pkey_get_details($resource);
            self::$key = [$privatepem, [
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => 'k1',
                'n' => flow::base64url($details['rsa']['n']),
                'e' => flow::base64url($details['rsa']['e']),
            ]];
        }
        return self::$key;
    }

    /**
     * Queue the hub's token response: an ID token for a person.
     *
     * @param string $nonce
     * @param string $email
     * @param array $overrides Claims to change.
     */
    private static function mock_token(string $nonce, string $email = 'pat@example.com', array $overrides = []): void {
        $now = time();
        $idtoken = JWT::encode(array_merge([
            'iss' => self::ISSUER,
            'sub' => md5('sub-1'),
            'aud' => self::CLIENTID,
            'exp' => $now + 300,
            'iat' => $now,
            'auth_time' => $now,
            'nonce' => $nonce,
            'email' => $email,
            'email_verified' => true,
            'given_name' => 'Pat',
            'family_name' => 'Example',
            'name' => 'Pat Example',
            'locale' => 'en',
        ], $overrides), self::key()[0], 'RS256', 'k1');
        \curl::mock_response(json_encode([
            'access_token' => 'access-token',
            'token_type' => 'Bearer',
            'expires_in' => 300,
            'id_token' => $idtoken,
        ]));
    }

    /**
     * Start a silent check from a course page, as the hook does.
     *
     * @return \stdClass The flow.
     */
    private function start_silent(): \stdClass {
        global $CFG, $SESSION;
        silent::start($CFG->wwwroot . '/course/view.php?id=2');
        $this->assertTrue(silent::was_checked());
        return end($SESSION->auth_nucleus_flows);
    }

    /**
     * The quiet way back to the course page.
     *
     * @return string
     */
    private static function quiet_url(): string {
        global $CFG;
        return $CFG->wwwroot . '/course/view.php?id=2&' . silent::MARKER . '=0';
    }

    /**
     * Assert nobody was signed in and no message is waiting on the login page.
     */
    private function assert_not_signed_in(): void {
        global $SESSION;
        $this->assertFalse(isloggedin());
        $this->assertObjectNotHasProperty('loginerrormsg', $SESSION);
        $this->assertObjectNotHasProperty(flow::IDTOKENKEY, $SESSION);
    }

    /**
     * The URL as a string.
     *
     * @param \moodle_url|string $url
     * @return string
     */
    private static function out($url): string {
        return $url instanceof \moodle_url ? $url->out(false) : $url;
    }

    /**
     * The hub's "not without a click" errors.
     *
     * @return array
     */
    public static function quiet_error_provider(): array {
        return [['login_required'], ['interaction_required'], ['consent_required']];
    }

    /**
     * A silent check the hub answers with login_required (and the like)
     * goes quietly back to the page with the marker: no error, no failed
     * login logged, the session noted as checked, the state used up.
     *
     * @param string $error
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('quiet_error_provider')]
    public function test_silent_hub_error_goes_back_quietly(string $error): void {
        $flow = $this->start_silent();
        $sink = $this->redirectEvents();

        $url = callback::handle($flow->state, '', $error, self::ISSUER);

        $this->assertSame(self::quiet_url(), self::out($url));
        $this->assert_not_signed_in();
        $this->assertCount(0, $sink->get_events());
        $this->assertTrue(silent::was_checked());
        $this->assertNull(flow::consume($flow->state));
    }

    /**
     * Any other error from the hub also goes back quietly, but is logged
     * as a failed sign-in, as for the Log in button.
     */
    public function test_silent_other_error_goes_back_quietly_and_is_logged(): void {
        foreach (['access_denied', 'temporarily_unavailable', 'server_error', 'invalid_request'] as $error) {
            $flow = $this->start_silent();
            $sink = $this->redirectEvents();
            $url = callback::handle($flow->state, '', $error, self::ISSUER);
            $this->assertSame(self::quiet_url(), self::out($url), $error);
            $this->assert_not_signed_in();
            $events = $sink->get_events();
            $this->assertCount(1, $events, $error);
            $this->assertInstanceOf(\core\event\user_login_failed::class, $events[0]);
            $sink->close();
        }
    }

    /**
     * The same checks as for the Log in button: a wrong iss, a malformed
     * code or an ID token for another nonce signs nobody in, quietly.
     */
    public function test_silent_checks_are_not_loosened(): void {
        // Another issuer named in the answer.
        $flow = $this->start_silent();
        $this->assertSame(self::quiet_url(), self::out(callback::handle($flow->state, self::CODE, '', 'https://evil.example.com')));
        $this->assert_not_signed_in();

        // No code.
        $flow = $this->start_silent();
        $this->assertSame(self::quiet_url(), self::out(callback::handle($flow->state, '', '', self::ISSUER)));
        $this->assert_not_signed_in();

        // A token for another sign-in's nonce.
        $flow = $this->start_silent();
        self::mock_token('another-nonce');
        $this->assertSame(self::quiet_url(), self::out(callback::handle($flow->state, self::CODE, '', self::ISSUER)));
        $this->assert_not_signed_in();

        // A token from another issuer.
        $flow = $this->start_silent();
        self::mock_token($flow->nonce, 'pat@example.com', ['iss' => 'https://evil.example.com/local/nucleushub/oidc']);
        $this->assertSame(self::quiet_url(), self::out(callback::handle($flow->state, self::CODE, '', self::ISSUER)));
        $this->assert_not_signed_in();

        // A state this session never started: nothing to sign in with.
        $this->start_silent();
        $url = callback::handle(flow::SILENT_STATE_PREFIX . str_repeat('x', 43), self::CODE, '', self::ISSUER);
        $this->assertSame((new \moodle_url('/', [silent::MARKER => 0]))->out(false), self::out($url));
        $this->assert_not_signed_in();
    }

    /**
     * A silent check the hub answers with a code signs the person in
     * exactly as the Log in button does, and goes back to the page.
     */
    public function test_silent_success_signs_in(): void {
        global $CFG, $SESSION, $USER;
        $_COOKIE[silent::signed_out_cookie()] = '1';
        $flow = $this->start_silent();
        self::mock_token($flow->nonce);

        $url = @callback::handle($flow->state, self::CODE, '', self::ISSUER);

        $this->assertTrue(isloggedin());
        $this->assertSame('nucleus', $USER->auth);
        $this->assertSame('hub-' . md5('sub-1'), $USER->username);
        $this->assertSame('pat@example.com', $USER->email);
        $this->assertSame($CFG->wwwroot . '/course/view.php?id=2', self::out($url));
        $this->assertIsString($SESSION->{flow::IDTOKENKEY});
        $this->assertObjectNotHasProperty('loginerrormsg', $SESSION);
        // Signed in with the hub again: silent sign-in is back on.
        $this->assertArrayNotHasKey(silent::signed_out_cookie(), $_COOKIE);
        $this->assertNull(flow::consume($flow->state));
    }

    /**
     * An account problem (here, an existing account with the same email
     * that hasn't been linked) fails quietly, logged, and signs nobody in.
     */
    public function test_silent_account_failure_is_quiet(): void {
        global $DB;
        $existing = $this->getDataGenerator()->create_user(['email' => 'pat@example.com']);
        $flow = $this->start_silent();
        self::mock_token($flow->nonce);
        $sink = $this->redirectEvents();

        $url = callback::handle($flow->state, self::CODE, '', self::ISSUER);

        $this->assertSame(self::quiet_url(), self::out($url));
        $this->assert_not_signed_in();
        $events = array_values(array_filter($sink->get_events(),
            fn($event) => $event instanceof \core\event\user_login_failed));
        $this->assertCount(1, $events);
        $this->assertSame('manual', $DB->get_field('user', 'auth', ['id' => $existing->id]));
        $this->assertSame(0, $DB->count_records('auth_nucleus_link'));
    }

    /**
     * A silent state this session doesn't have (a browser that doesn't
     * keep cookies) goes to the front page with the marker: nothing to
     * sign in, nothing logged.
     */
    public function test_silent_state_without_a_flow_goes_to_the_front_page(): void {
        global $CFG;
        $sink = $this->redirectEvents();

        $url = callback::handle(flow::SILENT_STATE_PREFIX . str_repeat('a', 43), '', 'login_required', self::ISSUER);

        $this->assertSame($CFG->wwwroot . '/?' . silent::MARKER . '=0', self::out($url));
        $this->assert_not_signed_in();
        $this->assertCount(0, $sink->get_events());
        $this->assertTrue(silent::was_checked());
    }

    /**
     * The Log in button's flow is unchanged: the hub's errors go to the
     * login page with a message, and so does an unknown state.
     */
    public function test_normal_flow_still_shows_errors(): void {
        global $CFG, $SESSION;

        $flow = flow::start($CFG->wwwroot . '/course/view.php?id=2');
        $url = callback::handle($flow->state, '', 'login_required', self::ISSUER);
        $this->assertSame($CFG->wwwroot . '/login/index.php', self::out($url));
        $this->assertSame(get_string('error_hubrefused', 'auth_nucleus'), $SESSION->loginerrormsg);
        $this->assertFalse(silent::was_checked());

        unset($SESSION->loginerrormsg);
        $url = callback::handle(str_repeat('b', 43), '', 'login_required', self::ISSUER);
        $this->assertSame($CFG->wwwroot . '/login/index.php', self::out($url));
        $this->assertSame(get_string('error_state', 'auth_nucleus'), $SESSION->loginerrormsg);
    }

    /**
     * Someone already signed in here stays who they are.
     */
    public function test_signed_in_person_is_not_changed(): void {
        global $USER;
        $user = $this->getDataGenerator()->create_user();
        // setUser() gives a new session, so the flow is started after it
        // (as when someone signs in another tab while a check is out).
        $this->setUser($user);
        $flow = $this->start_silent();

        $url = callback::handle($flow->state, self::CODE, '', self::ISSUER);

        $this->assertStringEndsWith('/course/view.php?id=2', self::out($url));
        $this->assertEquals($user->id, $USER->id);
    }
}
