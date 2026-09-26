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

use auth_nucleus\local\accounts;
use auth_nucleus\local\config;
use auth_nucleus\local\flow;
use auth_nucleus\local\hub;
use auth_nucleus\local\id_token;
use auth_nucleus\local\jwks;
use auth_nucleus\local\signin_exception;
use Firebase\JWT\JWT;
use local_nucleuscommon\transport\hub_http;
use local_nucleusspoke\external\configure_hub;
use local_nucleusspoke\external\configure_signin;

/**
 * Sign in with a hub that has moved to a new address (a custom domain)
 * while keeping its pinned issuer.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(hub::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(configure_signin::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(configure_hub::class)]
final class moved_hub_test extends \advanced_testcase {
    /** @var string The hub's original address. */
    private const ORIGINAL = 'https://acme-hub.n.example.com';

    /** @var string The hub's issuer, pinned at its original address. */
    private const ISSUER = self::ORIGINAL . '/local/nucleushub/oidc';

    /** @var string The hub's new address. */
    private const MOVED = 'https://training.example.org';

    /** @var string The spoke's cluster-internal way to the hub. */
    private const CONNECT = 'http://hub.internal:8080';

    /** @var string */
    private const CLIENTID = 'client-1';

    /** @var array|null [private PEM, public JWK] generated once for the class. */
    private static ?array $key = null;

    /**
     * An admin caller on a spoke; no hub connection yet.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        \cache::make('auth_nucleus', 'jwks')->purge();
    }

    /**
     * Point this spoke at its hub, as the control plane does.
     *
     * @param string $hubwwwroot
     * @param string|null $hubissuer Null leaves the parameter out.
     */
    private static function configure_hub(string $hubwwwroot, ?string $hubissuer = self::ISSUER): void {
        $params = ['hubwwwroot' => $hubwwwroot, 'hubtoken' => 'hub-token', 'hubconnecturl' => self::CONNECT];
        if ($hubissuer !== null) {
            $params['hubissuer'] = $hubissuer;
        }
        $params = \core_external\external_api::validate_parameters(configure_hub::execute_parameters(), $params);
        configure_hub::execute(...array_values($params));
    }

    /**
     * Set up sign-in with the hub, as the control plane does.
     *
     * @param string $issuer
     */
    private static function configure_signin(string $issuer = self::ISSUER): void {
        configure_signin::execute($issuer, self::CLIENTID, 'the-client-secret', 'Acme Hub', 1, 1);
    }

    /**
     * A hub sub: 32 lower-case hex characters.
     *
     * @param int|string $n
     * @return string
     */
    private static function sub($n): string {
        return md5('sub-' . $n);
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
     * An ID token as the moved hub issues it: iss is the pinned issuer.
     *
     * @param string $sub
     * @param string $nonce
     * @return string
     */
    private static function id_token(string $sub, string $nonce): string {
        $now = time();
        return JWT::encode([
            'iss' => self::ISSUER,
            'sub' => $sub,
            'aud' => self::CLIENTID,
            'exp' => $now + 300,
            'iat' => $now,
            'auth_time' => $now,
            'nonce' => $nonce,
            'email' => 'pat@example.com',
            'email_verified' => true,
            'given_name' => 'Pat',
            'family_name' => 'Example',
            'name' => 'Pat Example',
            'locale' => 'en',
        ], self::key()[0], 'RS256', 'k1');
    }

    /**
     * A spoke whose hub sign-in was set up at the hub's original address,
     * with one linked account, after Nucleus has sent it the pinned issuer.
     *
     * @return \stdClass The linked account.
     */
    private function spoke_with_a_link(): \stdClass {
        self::configure_hub(self::ORIGINAL);
        self::configure_signin();
        $user = $this->getDataGenerator()->create_user(['auth' => 'manual', 'email' => 'pat@example.com']);
        $this->assertSame(1, accounts::apply_links([['userid' => $user->id, 'sub' => self::sub(1)]])['applied']);
        return $user;
    }

    /**
     * The links, as [userid => issuer].
     *
     * @return array
     */
    private static function links(): array {
        global $DB;
        return $DB->get_records_menu('auth_nucleus_link', null, 'userid', 'userid, issuer');
    }

    /**
     * After the move, the browser goes to the new address, the server
     * calls are named under the pinned issuer and go to the hub
     * connection, and the issuer still counts as this spoke's hub's.
     */
    public function test_browser_endpoints_follow_the_hub(): void {
        $this->spoke_with_a_link();
        $this->assertStringStartsWith(self::ORIGINAL . '/local/nucleushub/oidc/authorize.php?',
            hub::authorize_url(flow::start(''))->out(false));

        self::configure_hub(self::MOVED);

        $this->assertSame(self::ISSUER, config::issuer());
        $this->assertTrue(hub::issuer_is_on_hub());

        $flow = flow::start('');
        $authorize = hub::authorize_url($flow);
        $this->assertSame(self::MOVED . '/local/nucleushub/oidc/authorize.php', $authorize->out_omit_querystring());
        $this->assertSame(self::CLIENTID, $authorize->get_param('client_id'));
        $this->assertSame($flow->state, $authorize->get_param('state'));

        $endsession = hub::end_session_url('the.id.token');
        $this->assertSame(self::MOVED . '/local/nucleushub/oidc/end_session.php', $endsession->out_omit_querystring());
        $this->assertSame('the.id.token', $endsession->get_param('id_token_hint'));

        $this->assertSame(self::ISSUER . '/token.php', hub::endpoint('token.php'));
        $http = hub_http::from_spoke_config();
        $this->assertSame(self::CONNECT . '/local/nucleushub/oidc/token.php', $http->internal_url(hub::endpoint('token.php')));
        $this->assertSame(self::CONNECT . '/local/nucleushub/oidc/jwks.php', $http->internal_url(hub::endpoint('jwks.php')));
        $this->assertSame(['Host: training.example.org'], $http->host_headers());

        // Without a hub connection, sign-in is off and signing out goes
        // under the issuer, as it always did.
        set_config('hubwwwroot', '', 'local_nucleusspoke');
        $this->assertFalse(hub::issuer_is_on_hub());
        $this->assertSame(self::ISSUER . '/end_session.php', hub::end_session_url(null)->out_omit_querystring());
    }

    /**
     * Saving the sign-in choice again after the move, with the pinned
     * issuer, keeps every link and hub account. The new address's issuer
     * is refused, and that changes nothing either.
     */
    public function test_configure_signin_with_the_pinned_issuer_keeps_links(): void {
        global $DB;

        $user = $this->spoke_with_a_link();
        $hubmade = $this->getDataGenerator()->create_user(['auth' => 'nucleus']);
        $links = self::links();
        $this->assertSame([$user->id => self::ISSUER], $links);

        self::configure_hub(self::MOVED);
        self::configure_signin();

        $this->assertSame($links, self::links());
        $this->assertSame('nucleus', $DB->get_field('user', 'auth', ['id' => $user->id]));
        $this->assertSame('nucleus', $DB->get_field('user', 'auth', ['id' => $hubmade->id]));
        $this->assertSame(self::ISSUER, config::issuer());
        $this->assertTrue(is_enabled_auth('nucleus'));

        try {
            self::configure_signin(self::MOVED . '/local/nucleushub/oidc');
            $this->fail('Accepted the new address\'s issuer');
        } catch (\moodle_exception $e) {
            $this->assertSame('signin_issuernotonhub', $e->errorcode);
            $this->assertStringContainsString(self::ISSUER, $e->getMessage());
        }
        $this->assertSame($links, self::links());
        $this->assertSame('nucleus', $DB->get_field('user', 'auth', ['id' => $hubmade->id]));
        $this->assertSame(self::ISSUER, config::issuer());
    }

    /**
     * A whole sign-in after the move: the code is exchanged through the
     * hub connection, the keys cached under the issuer before the move
     * still verify the token, and the linked account signs in.
     */
    public function test_sign_in_after_the_move_uses_the_existing_link(): void {
        $user = $this->spoke_with_a_link();
        $links = self::links();

        // The keys, fetched before the move and cached under the issuer.
        \curl::mock_response(json_encode(['keys' => [self::key()[1]]]));
        $this->assertArrayHasKey('k1', (new jwks(config::issuer()))->keys());

        self::configure_hub(self::MOVED);

        $flow = flow::start('');
        \curl::mock_response(json_encode([
            'access_token' => 'access-token',
            'token_type' => 'Bearer',
            'expires_in' => 300,
            'id_token' => self::id_token(self::sub(1), $flow->nonce),
        ]));
        $idtoken = hub::exchange_code(str_repeat('c', 43), $flow->verifier);

        // As callback.php does. No key fetch is queued: a cache miss
        // would try the connect address and fail.
        $verifier = new id_token(config::issuer(), config::clientid(), new jwks(config::issuer()));
        $claims = $verifier->verify($idtoken, $flow->nonce);
        $this->assertSame(self::ISSUER, $claims->iss);
        $signedin = accounts::sign_in($claims);

        $this->assertEquals($user->id, $signedin->id);
        $this->assertSame($links, self::links());
    }

    /**
     * If a hub move reached this spoke without the pinned issuer, sign-in
     * is refused rather than trusting the new address's issuer, and
     * nothing is unlinked.
     */
    public function test_move_without_the_pinned_issuer_refuses_sign_in(): void {
        $this->spoke_with_a_link();
        $links = self::links();

        self::configure_hub(self::MOVED, null);

        $this->assertFalse(hub::issuer_is_on_hub());
        try {
            hub::exchange_code(str_repeat('c', 43), str_repeat('v', 43));
            $this->fail('Exchanged a code with an issuer that isn\'t the hub\'s');
        } catch (signin_exception $e) {
            $this->assertSame('notenabled', $e->reasoncode);
        }
        $this->assertSame($links, self::links());
        $this->assertSame(self::ISSUER, config::issuer());
    }
}
