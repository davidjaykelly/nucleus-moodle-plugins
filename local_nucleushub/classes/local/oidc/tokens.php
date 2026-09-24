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

use Firebase\JWT\JWT;

/**
 * Authorisation codes and access tokens (ADR-023 section 3).
 *
 * Both are 32 random bytes, base64url, handed out once and stored only
 * as a hex SHA-256. Codes last 60 seconds and are single use; access
 * tokens last 300 seconds and only open `userinfo.php`.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tokens {
    /** @var int Seconds an authorisation code lasts. */
    public const CODE_TTL = 60;

    /** @var int Seconds an access token lasts. */
    public const ACCESS_TOKEN_TTL = 300;

    /** @var int Seconds an ID token lasts. */
    public const ID_TOKEN_TTL = 300;

    /** @var string Code table. */
    public const CODE_TABLE = 'local_nucleushub_oidc_code';

    /** @var string Access token table. */
    public const TOKEN_TABLE = 'local_nucleushub_oidc_token';

    /** @var string Shape of a code or access token: 32 bytes, base64url, no padding. */
    public const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    /**
     * A new random code or token.
     *
     * @return string 43 base64url characters.
     */
    public static function random(): string {
        return JWT::urlsafeB64Encode(random_bytes(32));
    }

    /**
     * The stored form of a code or token.
     *
     * @param string $value
     * @return string Hex SHA-256.
     */
    public static function hash(string $value): string {
        return hash('sha256', $value);
    }

    /**
     * The PKCE S256 challenge for a verifier (RFC 7636 section 4.2).
     *
     * @param string $verifier
     * @return string base64url(sha256(verifier))
     */
    public static function pkce_challenge(string $verifier): string {
        return JWT::urlsafeB64Encode(hash('sha256', $verifier, true));
    }

    /**
     * Issue an authorisation code.
     *
     * @param \stdClass $client Active client.
     * @param int $userid
     * @param string $redirecturi
     * @param string $nonce
     * @param string $codechallenge
     * @param int $authtime When the user signed in to the hub.
     * @return string The code, which is not stored.
     */
    public static function issue_code(
        \stdClass $client,
        int $userid,
        string $redirecturi,
        string $nonce,
        string $codechallenge,
        int $authtime
    ): string {
        global $DB;

        $code = self::random();
        $now = time();
        $DB->insert_record(self::CODE_TABLE, (object) [
            'codehash' => self::hash($code),
            'clientid' => (string) $client->clientid,
            'userid' => $userid,
            'redirecturi' => $redirecturi,
            'nonce' => $nonce,
            'codechallenge' => $codechallenge,
            'authtime' => $authtime,
            'expires' => $now + self::CODE_TTL,
            'timecreated' => $now,
        ]);
        return $code;
    }

    /**
     * Take a code out of the table, once.
     *
     * The row is claimed with a single conditional UPDATE (its hash
     * swapped for a random marker), read back by the marker and deleted,
     * all in one transaction. Of two requests racing with the same code,
     * only the one whose UPDATE matched finds the marker, so the code
     * can't be redeemed twice. Nothing about the row is checked here:
     * the caller checks expiry and bindings, and a code that fails them
     * is gone all the same.
     *
     * @param string $code
     * @return \stdClass|null The code row, or null if there is none.
     */
    public static function redeem_code(string $code): ?\stdClass {
        global $DB;

        if (!preg_match(self::TOKEN_PATTERN, $code)) {
            return null;
        }
        // Never a valid hex hash, so it can't collide with a real code.
        $claim = 'redeemed-' . bin2hex(random_bytes(27));

        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->set_field(self::CODE_TABLE, 'codehash', $claim, ['codehash' => self::hash($code)]);
            $row = $DB->get_record(self::CODE_TABLE, ['codehash' => $claim]);
            if ($row) {
                $DB->delete_records(self::CODE_TABLE, ['id' => $row->id]);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        return $row ?: null;
    }

    /**
     * Issue an access token for userinfo.
     *
     * @param \stdClass $client
     * @param int $userid
     * @return string The token, which is not stored.
     */
    public static function issue_access_token(\stdClass $client, int $userid): string {
        global $DB;

        $token = self::random();
        $DB->insert_record(self::TOKEN_TABLE, (object) [
            'tokenhash' => self::hash($token),
            'clientid' => (string) $client->clientid,
            'userid' => $userid,
            'expires' => time() + self::ACCESS_TOKEN_TTL,
        ]);
        return $token;
    }

    /**
     * An access token that hasn't expired.
     *
     * @param string $token
     * @return \stdClass|null Token row.
     */
    public static function find_access_token(string $token): ?\stdClass {
        global $DB;

        if (!preg_match(self::TOKEN_PATTERN, $token)) {
            return null;
        }
        $row = $DB->get_record(self::TOKEN_TABLE, ['tokenhash' => self::hash($token)]);
        if (!$row || (int) $row->expires <= time()) {
            return null;
        }
        return $row;
    }

    /**
     * Delete a client's codes and access tokens.
     *
     * @param string $clientid
     * @return void
     */
    public static function delete_for_client(string $clientid): void {
        global $DB;

        $DB->delete_records(self::CODE_TABLE, ['clientid' => $clientid]);
        $DB->delete_records(self::TOKEN_TABLE, ['clientid' => $clientid]);
    }

    /**
     * Delete every expired code and access token.
     *
     * @param int|null $now
     * @return void
     */
    public static function delete_expired(?int $now = null): void {
        global $DB;

        $params = ['now' => $now ?? time()];
        $DB->delete_records_select(self::CODE_TABLE, 'expires <= :now', $params);
        $DB->delete_records_select(self::TOKEN_TABLE, 'expires <= :now', $params);
    }
}
