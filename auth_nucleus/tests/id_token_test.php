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

use auth_nucleus\local\flow;
use auth_nucleus\local\id_token;
use auth_nucleus\local\jwks;
use auth_nucleus\local\signin_exception;
use Firebase\JWT\JWT;

/**
 * ID token verification: every rejection path.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \auth_nucleus\local\id_token
 * @covers     \auth_nucleus\local\jwks
 */
final class id_token_test extends \advanced_testcase {
    /** @var string */
    private const ISSUER = 'https://hub.example.com/local/nucleushub/oidc';

    /** @var string */
    private const CLIENTID = 'spoke-client-1';

    /** @var string */
    private const NONCE = 'nonce-for-this-session-0123456789';

    /** @var string A hub sub: 32 lower-case hex characters. */
    private const SUB = '0123456789abcdef0123456789abcdef';

    /** @var array|null [private PEM, public JWK] generated once for the class. */
    private static ?array $key = null;

    /** @var array|null A second key pair, for bad signatures. */
    private static ?array $otherkey = null;

    /** @var int How many times the key set was fetched. */
    private int $fetches = 0;

    /**
     * Fresh cache for each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        \cache::make('auth_nucleus', 'jwks')->purge();
        $this->fetches = 0;
    }

    /**
     * An RSA-2048 key pair as [private PEM, public JWK without kid].
     *
     * @return array
     */
    private static function make_key(): array {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $privatepem);
        $details = openssl_pkey_get_details($resource);
        return [$privatepem, [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => flow::base64url($details['rsa']['n']),
            'e' => flow::base64url($details['rsa']['e']),
        ]];
    }

    /**
     * The shared key pairs.
     *
     * @return array [key, otherkey]
     */
    private static function keys(): array {
        self::$key ??= self::make_key();
        self::$otherkey ??= self::make_key();
        return [self::$key, self::$otherkey];
    }

    /**
     * A JWKS document with the given public JWKs.
     *
     * @param array $jwks kid => public JWK.
     * @return array
     */
    private static function document(array $jwks): array {
        $keys = [];
        foreach ($jwks as $kid => $jwk) {
            $keys[] = $jwk + ['kid' => $kid];
        }
        return ['keys' => $keys];
    }

    /**
     * A verifier whose key set comes from the given documents in turn.
     *
     * @param array ...$documents One JWKS document per fetch; the last repeats.
     * @return id_token
     */
    private function verifier(array ...$documents): id_token {
        $fetcher = function () use ($documents): array {
            $index = min($this->fetches, count($documents) - 1);
            $this->fetches++;
            return $documents[$index];
        };
        return new id_token(self::ISSUER, self::CLIENTID, new jwks(self::ISSUER, $fetcher));
    }

    /**
     * Good claims, with overrides.
     *
     * @param array $overrides
     * @return array
     */
    private static function claims(array $overrides = []): array {
        $now = time();
        return array_merge([
            'iss' => self::ISSUER,
            'sub' => self::SUB,
            'aud' => self::CLIENTID,
            'exp' => $now + 300,
            'iat' => $now,
            'auth_time' => $now,
            'nonce' => self::NONCE,
            'email' => 'person@example.com',
            'email_verified' => true,
            'given_name' => 'Pat',
            'family_name' => 'Example',
            'name' => 'Pat Example',
            'locale' => 'en',
        ], $overrides);
    }

    /**
     * Sign claims with the main key.
     *
     * @param array $claims
     * @param string $kid
     * @return string
     */
    private static function sign(array $claims, string $kid = 'k1'): string {
        [[$privatepem]] = self::keys();
        return JWT::encode($claims, $privatepem, 'RS256', $kid);
    }

    /**
     * Assert that verification is refused.
     *
     * @param id_token $verifier
     * @param string $jwt
     * @param string $nonce
     */
    private function assert_refused(id_token $verifier, string $jwt, string $nonce = self::NONCE): void {
        try {
            $verifier->verify($jwt, $nonce);
            $this->fail('The token was accepted');
        } catch (signin_exception $e) {
            $this->assertSame('badtoken', $e->reasoncode);
            // The reason is logged; the token must not be.
            $this->assertStringNotContainsString($jwt, (string) $e->debuginfo);
        }
    }

    /**
     * A correctly signed token with the right claims is accepted.
     */
    public function test_valid_token_is_accepted(): void {
        [[, $public]] = self::keys();
        $claims = $this->verifier(self::document(['k1' => $public]))->verify(self::sign(self::claims()), self::NONCE);
        $this->assertSame(self::SUB, $claims->sub);
        $this->assertSame('person@example.com', $claims->email);
    }

    /**
     * A token signed with another key is refused.
     */
    public function test_bad_signature_is_refused(): void {
        [[, $public], [$otherprivate]] = self::keys();
        // Signed with a different key under the same kid.
        $jwt = JWT::encode(self::claims(), $otherprivate, 'RS256', 'k1');
        $this->assert_refused($this->verifier(self::document(['k1' => $public])), $jwt);
    }

    /**
     * A token whose claims were changed after signing is refused.
     */
    public function test_tampered_payload_is_refused(): void {
        [[, $public]] = self::keys();
        [$header, , $signature] = explode('.', self::sign(self::claims()));
        $payload = JWT::urlsafeB64Encode(json_encode(self::claims(['sub' => 'ffffffffffffffffffffffffffffffff'])));
        $this->assert_refused($this->verifier(self::document(['k1' => $public])), "$header.$payload.$signature");
    }

    /**
     * A token from another issuer is refused.
     */
    public function test_wrong_issuer_is_refused(): void {
        [[, $public]] = self::keys();
        $jwt = self::sign(self::claims(['iss' => 'https://evil.example.com/local/nucleushub/oidc']));
        $this->assert_refused($this->verifier(self::document(['k1' => $public])), $jwt);
    }

    /**
     * A token for another client is refused.
     */
    public function test_wrong_audience_is_refused(): void {
        [[, $public]] = self::keys();
        $jwt = self::sign(self::claims(['aud' => 'another-spoke']));
        $this->assert_refused($this->verifier(self::document(['k1' => $public])), $jwt);
    }

    /**
     * A token with several audiences needs azp set to this client.
     */
    public function test_several_audiences_need_azp(): void {
        [[, $public]] = self::keys();
        $verifier = $this->verifier(self::document(['k1' => $public]));
        $jwt = self::sign(self::claims(['aud' => [self::CLIENTID, 'another-spoke']]));
        $this->assert_refused($verifier, $jwt);

        $jwt = self::sign(self::claims(['aud' => [self::CLIENTID, 'another-spoke'], 'azp' => self::CLIENTID]));
        $this->assertSame(self::SUB, $verifier->verify($jwt, self::NONCE)->sub);
    }

    /**
     * A token whose nonce isn't this session's is refused.
     */
    public function test_wrong_nonce_is_refused(): void {
        [[, $public]] = self::keys();
        $verifier = $this->verifier(self::document(['k1' => $public]));
        $this->assert_refused($verifier, self::sign(self::claims()), 'a-different-nonce-for-another-session');
        $this->assert_refused($verifier, self::sign(self::claims(['nonce' => 'attacker-chosen-nonce-value-000000'])));
    }

    /**
     * A token without a nonce is refused.
     */
    public function test_missing_nonce_is_refused(): void {
        [[, $public]] = self::keys();
        $claims = self::claims();
        unset($claims['nonce']);
        $this->assert_refused($this->verifier(self::document(['k1' => $public])), self::sign($claims));
    }

    /**
     * A token expired for longer than the leeway is refused.
     */
    public function test_expired_token_is_refused(): void {
        [[, $public]] = self::keys();
        $jwt = self::sign(self::claims(['exp' => time() - 61, 'iat' => time() - 400]));
        $this->assert_refused($this->verifier(self::document(['k1' => $public])), $jwt);
    }

    /**
     * A token expired for less than the leeway is accepted.
     */
    public function test_expiry_within_leeway_is_accepted(): void {
        [[, $public]] = self::keys();
        $jwt = self::sign(self::claims(['exp' => time() - 30, 'iat' => time() - 330]));
        $this->assertSame(self::SUB, $this->verifier(self::document(['k1' => $public]))->verify($jwt, self::NONCE)->sub);
    }

    /**
     * A token without exp is refused.
     */
    public function test_missing_exp_is_refused(): void {
        [[, $public]] = self::keys();
        $claims = self::claims();
        unset($claims['exp']);
        $this->assert_refused($this->verifier(self::document(['k1' => $public])), self::sign($claims));
    }

    /**
     * A token issued in the future (beyond the leeway) is refused.
     */
    public function test_token_from_the_future_is_refused(): void {
        [[, $public]] = self::keys();
        $jwt = self::sign(self::claims(['iat' => time() + 600, 'exp' => time() + 900]));
        $this->assert_refused($this->verifier(self::document(['k1' => $public])), $jwt);
    }

    /**
     * An HS256 token keyed with the public key (algorithm confusion) is refused.
     */
    public function test_hs256_with_the_public_key_is_refused(): void {
        [[, $public]] = self::keys();
        // Algorithm confusion: HMAC keyed with the (public) key material.
        $jwt = JWT::encode(self::claims(), json_encode($public), 'HS256', 'k1');
        $this->assert_refused($this->verifier(self::document(['k1' => $public])), $jwt);
    }

    /**
     * An unsigned (alg none) token is refused.
     */
    public function test_unsigned_token_is_refused(): void {
        [[, $public]] = self::keys();
        $header = JWT::urlsafeB64Encode(json_encode(['alg' => 'none', 'kid' => 'k1', 'typ' => 'JWT']));
        $payload = JWT::urlsafeB64Encode(json_encode(self::claims()));
        $this->assert_refused($this->verifier(self::document(['k1' => $public])), "$header.$payload.");
    }

    /**
     * A token without a sub of exactly 32 lower-case hex characters is refused.
     */
    public function test_missing_sub_is_refused(): void {
        [[, $public]] = self::keys();
        $claims = self::claims();
        unset($claims['sub']);
        $this->assert_refused($this->verifier(self::document(['k1' => $public])), self::sign($claims));
        foreach ([
            '../../admin',
            '42',
            '0123456789ABCDEF0123456789ABCDEF',
            '0123456789abcdef0123456789abcde',
            '0123456789abcdef0123456789abcdef0',
            '0123456789abcdef0123456789abcdeg',
        ] as $sub) {
            $this->assert_refused($this->verifier(self::document(['k1' => $public])),
                self::sign(self::claims(['sub' => $sub])));
        }
    }

    /**
     * The key set is fetched once and then cached.
     */
    public function test_key_set_is_cached(): void {
        [[, $public]] = self::keys();
        $verifier = $this->verifier(self::document(['k1' => $public]));
        $verifier->verify(self::sign(self::claims()), self::NONCE);
        $verifier->verify(self::sign(self::claims()), self::NONCE);
        $this->assertSame(1, $this->fetches);
    }

    /**
     * An unknown kid refetches the key set once, which finds a rotated key.
     */
    public function test_unknown_kid_refetches_once(): void {
        [[, $public]] = self::keys();
        // The cached set predates a key rotation; the refetch has the new key.
        $verifier = $this->verifier(self::document(['old' => $public]), self::document(['k1' => $public]));
        $this->assertSame(self::SUB, $verifier->verify(self::sign(self::claims()), self::NONCE)->sub);
        $this->assertSame(2, $this->fetches);
    }

    /**
     * A kid still unknown after one refetch is refused.
     */
    public function test_kid_unknown_after_refetch_is_refused(): void {
        [[, $public]] = self::keys();
        $verifier = $this->verifier(self::document(['old' => $public]));
        $this->assert_refused($verifier, self::sign(self::claims()));
        $this->assertSame(2, $this->fetches);
    }

    /**
     * Symmetric and encryption keys in the key set are never used.
     */
    public function test_non_rsa_and_non_signing_keys_are_ignored(): void {
        [[, $public]] = self::keys();
        $document = ['keys' => [
            ['kty' => 'oct', 'kid' => 'k1', 'k' => 'c2VjcmV0', 'alg' => 'HS256'],
            array_merge($public, ['kid' => 'enc', 'use' => 'enc']),
        ]];
        $this->assertSame([], jwks::parse($document));
        $this->assert_refused($this->verifier($document), self::sign(self::claims()));
    }

    /**
     * The library's global leeway is put back after verifying.
     */
    public function test_leeway_is_restored(): void {
        [[, $public]] = self::keys();
        $before = JWT::$leeway;
        $this->verifier(self::document(['k1' => $public]))->verify(self::sign(self::claims()), self::NONCE);
        $this->assertSame($before, JWT::$leeway);
    }
}
