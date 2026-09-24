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
 * Tests for the signing keys.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(keys::class)]
final class keys_test extends \advanced_testcase {
    /**
     * The first use makes one RSA-2048 key, private half encrypted.
     */
    public function test_first_use_makes_an_encrypted_key(): void {
        $this->resetAfterTest();
        $this->assertSame([], keys::all());

        $current = keys::current();
        $this->assertCount(1, keys::all());
        $this->assertSame($current['kid'], keys::current()['kid']);

        $stored = (string) get_config('local_nucleushub', keys::CONFIG);
        $this->assertStringNotContainsString('PRIVATE KEY', $stored);
        $this->assertStringContainsString('PUBLIC KEY', $current['publicpem']);
        $pem = \core\encryption::decrypt($current['privatepemenc']);
        $this->assertStringContainsString('PRIVATE KEY', $pem);
        $details = openssl_pkey_get_details(openssl_pkey_get_private($pem));
        $this->assertSame(OPENSSL_KEYTYPE_RSA, $details['type']);
        $this->assertSame(2048, $details['bits']);
    }

    /**
     * The JWKS lists public keys only, in the RS256 shape.
     */
    public function test_jwks_shape(): void {
        $this->resetAfterTest();
        $jwks = keys::jwks();
        $this->assertCount(1, $jwks['keys']);
        $key = $jwks['keys'][0];
        $this->assertSame(['kty', 'use', 'alg', 'kid', 'n', 'e'], array_keys($key));
        $this->assertSame('RSA', $key['kty']);
        $this->assertSame('sig', $key['use']);
        $this->assertSame('RS256', $key['alg']);
        $this->assertSame(keys::current()['kid'], $key['kid']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $key['n']);
        $this->assertSame('AQAB', $key['e']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($jwks));
    }

    /**
     * Rotation signs with the new key, keeps the previous one in the JWKS,
     * and drops anything older.
     */
    public function test_rotation_keeps_only_the_previous_key(): void {
        $this->resetAfterTest();
        $first = keys::current();
        $tokenfirst = keys::sign(['sub' => '1']);

        $second = keys::rotate();
        $this->assertSame($second['kid'], keys::current()['kid']);
        $this->assertSame([$second['kid'], $first['kid']], array_column(keys::jwks()['keys'], 'kid'));

        // A token signed before the rotation still verifies.
        $this->assertSame('1', JWT::decode($tokenfirst, JWK::parseKeySet(keys::jwks()))->sub);
        // New tokens use the new key.
        [$header] = explode('.', keys::sign(['sub' => '2']));
        $this->assertSame($second['kid'], json_decode(JWT::urlsafeB64Decode($header))->kid);

        $third = keys::rotate();
        $this->assertSame([$third['kid'], $second['kid']], array_column(keys::jwks()['keys'], 'kid'));
        $this->expectException(\UnexpectedValueException::class);
        JWT::decode($tokenfirst, JWK::parseKeySet(keys::jwks()));
    }

    /**
     * A damaged key list is an error, not silently replaced; reset replaces it.
     */
    public function test_damaged_list_is_refused_until_reset(): void {
        $this->resetAfterTest();
        set_config(keys::CONFIG, '{"not": "a list"}', 'local_nucleushub');
        try {
            keys::current();
            $this->fail('A damaged key list must not be used.');
        } catch (\moodle_exception $e) {
            $this->assertSame('oidc_keysdamaged', $e->errorcode);
        }
        $new = keys::rotate(true);
        $this->assertSame([$new['kid']], array_column(keys::all(), 'kid'));
    }
}
