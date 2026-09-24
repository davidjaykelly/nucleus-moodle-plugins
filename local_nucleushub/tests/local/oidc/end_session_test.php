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
 * Tests for the end_session decision: never an unregistered redirect,
 * and no sign-out by a bare link.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class end_session_test extends \advanced_testcase {
    /**
     * A client and a user with an ID token for it.
     *
     * @param int $age Seconds since the ID token was issued.
     * @return array{registered: array, user: \stdClass, idtoken: string}
     */
    private function setup_session(int $age = 0): array {
        /** @var \local_nucleushub_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nucleushub');
        $registered = $generator->create_oidc_client();
        $user = $this->getDataGenerator()->create_user();
        $now = time() - $age;
        $idtoken = keys::sign([
            'iss' => provider::issuer(),
            'sub' => subjects::for_user((int) $user->id),
            'aud' => $registered['clientid'],
            'exp' => $now + 300,
            'iat' => $now,
        ]);
        return ['registered' => $registered, 'user' => $user, 'idtoken' => $idtoken];
    }

    /**
     * A valid hint for the signed-in user signs out at once and goes back
     * to the client's post-logout URI with state, even when the hint has expired.
     */
    public function test_valid_hint_signs_out_and_redirects(): void {
        $this->resetAfterTest();
        foreach ([0, 3600] as $age) {
            $setup = $this->setup_session($age);
            $decision = provider::end_session([
                'post_logout_redirect_uri' => $setup['registered']['client']->postlogouturi,
                'state' => 'bye',
                'id_token_hint' => $setup['idtoken'],
            ], $setup['user'], false, false);

            $this->assertTrue($decision['logout']);
            $this->assertFalse($decision['confirm']);
            $this->assertSame($setup['registered']['client']->postlogouturi . '?state=bye', $decision['redirect']->out(false));
        }
    }

    /**
     * Post-logout URIs that must never be redirected to.
     *
     * @return array
     */
    public static function bad_uri_provider(): array {
        return [
            'another site' => ['https://evil.example.com/'],
            'registered plus a path' => ['SUFFIX:x'],
            'registered plus a query' => ['SUFFIX:?next=https://evil.example.com'],
            'the redirect URI, not the post-logout URI' => ['CALLBACK'],
            'javascript' => ['javascript:alert(1)'],
        ];
    }

    /**
     * An unregistered URI is never redirected to; the user is still signed out.
     *
     * @param string $uri
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_uri_provider')]
    public function test_unregistered_uri_is_not_redirected_to(string $uri): void {
        $this->resetAfterTest();
        $setup = $this->setup_session();
        if (str_starts_with($uri, 'SUFFIX:')) {
            $uri = $setup['registered']['client']->postlogouturi . substr($uri, 7);
        } else if ($uri === 'CALLBACK') {
            $uri = $setup['registered']['client']->redirecturi;
        }
        $decision = provider::end_session([
            'post_logout_redirect_uri' => $uri,
            'id_token_hint' => $setup['idtoken'],
        ], $setup['user'], false, false);
        $this->assertTrue($decision['logout']);
        $this->assertNull($decision['redirect']);
    }

    /**
     * With a valid hint, only that client's own post-logout URI is used.
     */
    public function test_hint_limits_redirect_to_its_client(): void {
        $this->resetAfterTest();
        $setup = $this->setup_session();
        /** @var \local_nucleushub_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nucleushub');
        $other = $generator->create_oidc_client();
        $decision = provider::end_session([
            'post_logout_redirect_uri' => $other['client']->postlogouturi,
            'id_token_hint' => $setup['idtoken'],
        ], $setup['user'], false, false);
        $this->assertNull($decision['redirect']);
    }

    /**
     * Without a hint, a signed-in user is asked first, and the registered
     * URI is carried through the confirmation.
     */
    public function test_no_hint_asks_first(): void {
        $this->resetAfterTest();
        $setup = $this->setup_session();
        $uri = $setup['registered']['client']->postlogouturi;
        $decision = provider::end_session(['post_logout_redirect_uri' => $uri, 'state' => 's'], $setup['user'], false, false);
        $this->assertFalse($decision['logout']);
        $this->assertTrue($decision['confirm']);
        $this->assertSame(['post_logout_redirect_uri' => $uri, 'state' => 's'], $decision['params']);

        // Once confirmed (the endpoint checks the sesskey), out they go.
        $decision = provider::end_session(['post_logout_redirect_uri' => $uri, 'state' => 's'], $setup['user'], false, true);
        $this->assertTrue($decision['logout']);
        $this->assertSame($uri . '?state=s', $decision['redirect']->out(false));
    }

    /**
     * Hints that don't count: someone else's, tampered, wrong issuer, unknown
     * client, not a JWT. The user is asked first.
     */
    public function test_bad_hints_ask_first(): void {
        $this->resetAfterTest();
        $setup = $this->setup_session();
        $otheruser = $this->getDataGenerator()->create_user();
        [$h, $p, $s] = explode('.', $setup['idtoken']);
        $payload = json_decode(\Firebase\JWT\JWT::urlsafeB64Decode($p), true);
        $payload['sub'] = subjects::for_user((int) $otheruser->id);
        $tampered = $h . '.' . \Firebase\JWT\JWT::urlsafeB64Encode(json_encode($payload)) . '.' . $s;
        $sub = subjects::for_user((int) $setup['user']->id);
        $wrongiss = keys::sign(['iss' => 'https://evil.example.com', 'sub' => $sub,
            'aud' => $setup['registered']['clientid'], 'iat' => time(), 'exp' => time() + 300]);
        $unknownaud = keys::sign(['iss' => provider::issuer(), 'sub' => $sub,
            'aud' => 'nucleus-unknown', 'iat' => time(), 'exp' => time() + 300]);
        // A validly signed token that carries the user id as sub (the old
        // format) doesn't count.
        $numericsub = keys::sign(['iss' => provider::issuer(), 'sub' => (string) $setup['user']->id,
            'aud' => $setup['registered']['clientid'], 'iat' => time(), 'exp' => time() + 300]);

        foreach ([$tampered, $wrongiss, $unknownaud, $numericsub, 'not-a-jwt', str_repeat('a', 9000)] as $hint) {
            $this->assertNull(provider::verify_id_token_hint($hint));
            $decision = provider::end_session(['id_token_hint' => $hint], $setup['user'], false, false);
            $this->assertTrue($decision['confirm']);
            $this->assertFalse($decision['logout']);
        }

        // A valid hint for someone else isn't enough either.
        $decision = provider::end_session(['id_token_hint' => $setup['idtoken']], $otheruser, false, false);
        $this->assertTrue($decision['confirm']);
    }

    /**
     * A "Log in as" session is always asked first.
     */
    public function test_loggedinas_asks_first(): void {
        $this->resetAfterTest();
        $setup = $this->setup_session();
        $decision = provider::end_session(['id_token_hint' => $setup['idtoken']], $setup['user'], true, false);
        $this->assertTrue($decision['confirm']);
    }

    /**
     * Nobody signed in: nothing to confirm; a registered URI is still used.
     */
    public function test_signed_out_user_goes_straight_back(): void {
        $this->resetAfterTest();
        $setup = $this->setup_session();
        $uri = $setup['registered']['client']->postlogouturi;
        foreach ([(object) ['id' => 0], guest_user()] as $user) {
            $decision = provider::end_session(['post_logout_redirect_uri' => $uri], $user, false, false);
            $this->assertTrue($decision['logout']);
            $this->assertFalse($decision['confirm']);
            $this->assertSame($uri, $decision['redirect']->out(false));
        }
    }

    /**
     * An inactive spoke's post-logout URI isn't used.
     */
    public function test_inactive_spoke_uri_is_not_used(): void {
        $this->resetAfterTest();
        $setup = $this->setup_session();
        /** @var \local_nucleushub_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nucleushub');
        $generator->set_spoke_status($setup['registered']['spoke'], 'removed');
        $decision = provider::end_session([
            'post_logout_redirect_uri' => $setup['registered']['client']->postlogouturi,
        ], (object) ['id' => 0], false, false);
        $this->assertNull($decision['redirect']);
    }

    /**
     * A state that isn't plain visible ASCII is dropped.
     */
    public function test_bad_state_is_dropped(): void {
        $this->resetAfterTest();
        $setup = $this->setup_session();
        $uri = $setup['registered']['client']->postlogouturi;
        $params = ['post_logout_redirect_uri' => $uri, 'state' => "a\r\nb"];
        $decision = provider::end_session($params, (object) ['id' => 0], false, false);
        $this->assertSame($uri, $decision['redirect']->out(false));
    }
}
