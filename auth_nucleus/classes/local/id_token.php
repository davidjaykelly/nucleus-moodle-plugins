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

namespace auth_nucleus\local;

use Firebase\JWT\JWT;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/authlib.php');

/**
 * Verifies the hub's ID tokens.
 *
 * - RS256 only, with the key picked by kid from the hub's JWKS. An
 *   unknown kid refetches the key set once (the hub rotated its key).
 * - iss must equal the configured issuer and aud the client id.
 * - exp is checked with 60 seconds' leeway; exp and iat must be present.
 * - nonce must equal the one this session sent.
 * - sub must be present and safe to use in a username.
 *
 * Failures never carry the token or its claims.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class id_token {
    /** @var int Clock skew allowed on exp, iat and nbf, in seconds. */
    public const LEEWAY = 60;

    /** @var string Expected issuer. */
    private string $issuer;

    /** @var string Expected audience (this spoke's client id). */
    private string $clientid;

    /** @var jwks The hub's keys. */
    private jwks $jwks;

    /**
     * Constructor.
     *
     * @param string $issuer Expected issuer.
     * @param string $clientid Expected audience.
     * @param jwks $jwks The hub's keys.
     */
    public function __construct(string $issuer, string $clientid, jwks $jwks) {
        $this->issuer = rtrim($issuer, '/');
        $this->clientid = $clientid;
        $this->jwks = $jwks;
    }

    /**
     * Verify an ID token and return its claims.
     *
     * @param string $jwt The ID token.
     * @param string $nonce The nonce this session sent with the request.
     * @return \stdClass The verified claims.
     * @throws signin_exception If anything about the token is wrong.
     */
    public function verify(string $jwt, string $nonce): \stdClass {
        if ($this->issuer === '' || $this->clientid === '' || $nonce === '') {
            self::fail('verifier not configured or no nonce');
        }

        // Read the header first to insist on RS256 and find the key.
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            self::fail('not a signed JWT');
        }
        try {
            $header = json_decode(JWT::urlsafeB64Decode($parts[0]), false, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            self::fail('unreadable header');
        }
        if (!is_object($header) || ($header->alg ?? null) !== 'RS256') {
            self::fail('algorithm is not RS256');
        }
        $kid = $header->kid ?? null;
        if (!is_string($kid) || $kid === '') {
            self::fail('no kid');
        }

        $keys = $this->jwks->keys();
        if (!isset($keys[$kid])) {
            // The hub may have rotated its key since the set was cached.
            $keys = $this->jwks->keys(true);
            if (!isset($keys[$kid])) {
                self::fail('unknown kid');
            }
        }

        $previousleeway = JWT::$leeway;
        JWT::$leeway = self::LEEWAY;
        try {
            // The key is bound to RS256, so decode() refuses any other alg.
            $claims = JWT::decode($jwt, [$kid => $keys[$kid]]);
        } catch (\Firebase\JWT\ExpiredException $e) {
            self::fail('expired');
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            self::fail('bad signature');
        } catch (\Throwable $e) {
            self::fail('rejected by decoder');
        } finally {
            JWT::$leeway = $previousleeway;
        }

        $this->check_claims($claims, $nonce);
        return $claims;
    }

    /**
     * Check the claims of a token whose signature and times are good.
     *
     * @param \stdClass $claims
     * @param string $nonce
     * @throws signin_exception
     */
    private function check_claims(\stdClass $claims, string $nonce): void {
        if (!is_string($claims->iss ?? null) || $claims->iss !== $this->issuer) {
            self::fail('wrong issuer');
        }

        $aud = $claims->aud ?? null;
        if (is_string($aud)) {
            if ($aud !== $this->clientid) {
                self::fail('wrong audience');
            }
        } else if (is_array($aud)) {
            if (!in_array($this->clientid, $aud, true)) {
                self::fail('wrong audience');
            }
            // Several audiences: the token must be issued to this client.
            if (count($aud) > 1 && ($claims->azp ?? null) !== $this->clientid) {
                self::fail('wrong authorised party');
            }
        } else {
            self::fail('no audience');
        }

        if (!is_numeric($claims->exp ?? null) || !is_numeric($claims->iat ?? null)) {
            self::fail('no exp or iat');
        }
        // decode() has already checked exp and iat against the leeway.

        if (!is_string($claims->nonce ?? null) || !hash_equals($nonce, $claims->nonce)) {
            self::fail('wrong nonce');
        }

        if (!accounts::valid_sub($claims->sub ?? null)) {
            self::fail('missing or unusable sub');
        }
    }

    /**
     * Refuse the token.
     *
     * @param string $why For the logs. Never the token or its claims.
     * @return never
     * @throws signin_exception
     */
    private static function fail(string $why): never {
        throw new signin_exception('badtoken', AUTH_LOGIN_FAILED, 'id token refused: ' . $why);
    }
}
