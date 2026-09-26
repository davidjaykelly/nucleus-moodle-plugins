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

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

/**
 * The pinned issuer: a hub that moves to a new address keeps the issuer
 * it had, and publishes its endpoints on the new address.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(client_registry::class)]
final class pinned_issuer_test extends \advanced_testcase {
    /** @var string The hub's original address. */
    private const ORIGINAL = 'https://acme-hub.n.example.com';

    /** @var string The hub's issuer, pinned at its original address. */
    private const ISSUER = self::ORIGINAL . '/local/nucleushub/oidc';

    /** @var string The hub's new address (a custom domain). */
    private const MOVED = 'https://training.example.org';

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
     * A hub that got the pinning version at its original address and has
     * since moved to a new one.
     */
    private function move_hub(): void {
        global $CFG;
        unset_config(provider::ISSUER_CONFIG, 'local_nucleushub');
        $CFG->wwwroot = self::ORIGINAL;
        $this->assertSame(self::ISSUER, provider::pin_issuer());
        $CFG->wwwroot = self::MOVED;
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
     * Installing the plugin pins the issuer to the address it's installed at.
     */
    public function test_install_pins_the_issuer(): void {
        global $CFG;
        $this->resetAfterTest();

        // PHPUnit installs every plugin, so this site already has it.
        $this->assertSame($CFG->wwwroot . '/local/nucleushub/oidc',
            get_config('local_nucleushub', provider::ISSUER_CONFIG));
        $this->assertSame($CFG->wwwroot . '/local/nucleushub/oidc', provider::issuer());

        require_once($CFG->dirroot . '/local/nucleushub/db/install.php');
        unset_config(provider::ISSUER_CONFIG, 'local_nucleushub');
        $CFG->wwwroot = self::ORIGINAL;
        $this->assertTrue(xmldb_local_nucleushub_install());
        $this->assertSame(self::ISSUER, get_config('local_nucleushub', provider::ISSUER_CONFIG));

        // Installing again (or anything else calling it) never replaces it.
        $CFG->wwwroot = self::MOVED;
        $this->assertTrue(xmldb_local_nucleushub_install());
        $this->assertSame(self::ISSUER, get_config('local_nucleushub', provider::ISSUER_CONFIG));
    }

    /**
     * The upgrade step pins the issuer to the wwwroot the hub has when it
     * runs, and a later wwwroot change doesn't move it.
     */
    public function test_upgrade_pins_the_issuer_once(): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/nucleushub/db/upgrade.php');

        unset_config(provider::ISSUER_CONFIG, 'local_nucleushub');
        $CFG->wwwroot = self::ORIGINAL;
        set_config('version', 2026092700, 'local_nucleushub');
        $this->assertTrue(xmldb_local_nucleushub_upgrade(2026092700));
        $this->assertSame(self::ISSUER, get_config('local_nucleushub', provider::ISSUER_CONFIG));
        $this->assertEquals(2026092800, get_config('local_nucleushub', 'version'));

        // The hub moves. The issuer stays where it was.
        $CFG->wwwroot = self::MOVED;
        $this->assertSame(self::ISSUER, provider::issuer());
        $this->assertSame(self::MOVED . '/local/nucleushub/oidc', provider::wwwroot_issuer());

        // Running the step again (a restored database, say) keeps it too.
        set_config('version', 2026092700, 'local_nucleushub');
        $this->assertTrue(xmldb_local_nucleushub_upgrade(2026092700));
        $this->assertSame(self::ISSUER, get_config('local_nucleushub', provider::ISSUER_CONFIG));
        $this->assertSame(self::ISSUER, provider::pin_issuer());
    }

    /**
     * Without a pinned issuer (which install and upgrade always set), the
     * issuer is worked out from the wwwroot, as it was before pinning.
     */
    public function test_unpinned_issuer_follows_wwwroot(): void {
        global $CFG;
        $this->resetAfterTest();

        unset_config(provider::ISSUER_CONFIG, 'local_nucleushub');
        $CFG->wwwroot = self::MOVED;
        $this->assertSame(self::MOVED . '/local/nucleushub/oidc', provider::issuer());
        // Reading it doesn't pin it.
        $this->assertFalse(get_config('local_nucleushub', provider::ISSUER_CONFIG));
    }

    /**
     * After a move, discovery names the pinned issuer and puts every
     * endpoint on the new address, whatever the request's host.
     */
    public function test_moved_hub_discovery(): void {
        $this->resetAfterTest();
        $this->move_hub();
        $_SERVER['HTTP_HOST'] = 'acme-hub.n.example.com';

        $discovery = provider::discovery();
        $this->assertSame(self::ISSUER, $discovery['issuer']);
        $base = self::MOVED . '/local/nucleushub/oidc/';
        $this->assertSame($base . 'authorize.php', $discovery['authorization_endpoint']);
        $this->assertSame($base . 'token.php', $discovery['token_endpoint']);
        $this->assertSame($base . 'userinfo.php', $discovery['userinfo_endpoint']);
        $this->assertSame($base . 'jwks.php', $discovery['jwks_uri']);
        $this->assertSame($base . 'end_session.php', $discovery['end_session_endpoint']);
        $this->assertSame($base . 'token.php', provider::endpoint('token.php'));
    }

    /**
     * After a move, the authorisation response's iss, the ID token's iss
     * and the issuer returned to Nucleus are all the pinned issuer.
     */
    public function test_moved_hub_states_the_pinned_issuer(): void {
        $this->resetAfterTest();
        $this->move_hub();
        $registered = $this->generator()->create_oidc_client();
        $user = $this->getDataGenerator()->create_user(['email' => 'ada@example.com']);

        // The authorisation response (RFC 9207), success and error.
        $request = provider::parse_authorize_request([
            'client_id' => $registered['clientid'],
            'redirect_uri' => $registered['client']->redirecturi,
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'state' => 'state-123',
            'nonce' => 'nonce-456',
            'code_challenge' => tokens::pkce_challenge(self::VERIFIER),
            'code_challenge_method' => 'S256',
        ]);
        $this->assertNull($request->error);
        $this->assertSame(self::ISSUER, $this->query(provider::complete_authorize($request, $user, time(), false))['iss']);
        $this->assertSame(self::ISSUER, $this->query(provider::authorize_error_url($request, 'access_denied'))['iss']);

        // The ID token.
        $code = $this->generator()->create_code($registered['client'], (int) $user->id, self::VERIFIER, 'nonce-abc');
        $response = provider::token('POST', provider::FORM_TYPE, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $registered['client']->redirecturi,
            'code_verifier' => self::VERIFIER,
            'client_id' => $registered['clientid'],
            'client_secret' => $registered['secret'],
        ], null);
        $this->assertSame(200, $response->status);
        $claims = JWT::decode($response->body['id_token'], JWK::parseKeySet(keys::jwks()));
        $this->assertSame(self::ISSUER, $claims->iss);

        // What oidc_register_client gives Nucleus for the spoke.
        $this->assertSame(self::ISSUER, client_registry::register($registered['spoke']->cpspokeid)['issuer']);
    }

    /**
     * After a move, a logout hint issued with the pinned issuer (before or
     * after the move) is still good, and one naming the new address as
     * its issuer is refused: the check isn't loosened.
     */
    public function test_moved_hub_logout_hint_checks_the_pinned_issuer(): void {
        $this->resetAfterTest();
        $this->move_hub();
        $registered = $this->generator()->create_oidc_client();
        $user = $this->getDataGenerator()->create_user();
        $claims = [
            'sub' => subjects::for_user((int) $user->id),
            'aud' => $registered['clientid'],
            'exp' => time() + 300,
            'iat' => time(),
        ];

        $hinted = provider::verify_id_token_hint(keys::sign(['iss' => self::ISSUER] + $claims));
        $this->assertNotNull($hinted);
        $this->assertSame($registered['clientid'], $hinted['clientid']);

        $this->assertNull(provider::verify_id_token_hint(
            keys::sign(['iss' => self::MOVED . '/local/nucleushub/oidc'] + $claims)));
    }
}
