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
 * The spokes' OpenID Connect clients (ADR-023 section 3).
 *
 * Each active spoke row can have one client, registered by the control
 * plane. A client has exactly one redirect URI,
 * `{spoke wwwroot}/auth/nucleus/callback.php`, and one post-logout URI,
 * `{spoke wwwroot}/`, both derived from the spoke row, never from a
 * request. Its secret (32 random bytes) is returned once and stored
 * only as a hex SHA-256. A slow password hash buys nothing for a
 * 256-bit random secret, and would let anyone make the unauthenticated
 * token endpoint burn CPU.
 *
 * A client only counts while its spoke row is `active`: suspending or
 * removing a spoke stops its sign-in at once.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client_registry {
    /** @var string Client table. */
    public const TABLE = 'local_nucleushub_oidc_client';

    /** @var string Path of the spoke's callback, under its wwwroot. */
    public const CALLBACK_PATH = '/auth/nucleus/callback.php';

    /**
     * @var string A fixed SHA-256 that no secret hashes to in practice,
     *      compared for unknown clients so a wrong client id takes the
     *      same work as a wrong secret.
     */
    private const DUMMY_HASH = '5e3f0b6c1a9d4e27b8c0f19a6d2e4b7c3f8a1d5e9b2c6f0a4d8e1b7c3a9f2d6e';

    /**
     * Register the spoke's client, or give an existing one a new secret.
     *
     * The client id stays the same for a spoke. Every call makes a new
     * secret, which is returned here and nowhere else, and refreshes the
     * two URIs from the spoke's current address.
     *
     * @param string $cpspokeid Nucleus spoke ID.
     * @return array{issuer: string, clientid: string, clientsecret: string}
     * @throws \moodle_exception If there is no active spoke with that ID, or its address can't be used.
     */
    public static function register(string $cpspokeid): array {
        global $DB;

        $spoke = self::active_spoke($cpspokeid);
        if (!$spoke) {
            throw new \moodle_exception('oidc_nospoke', 'local_nucleushub');
        }
        $uris = self::uris_for((string) $spoke->wwwroot);

        $secret = self::new_secret();
        $now = time();
        $client = $DB->get_record(self::TABLE, ['spokeid' => $spoke->id]);
        if ($client) {
            $client->secrethash = self::hash_secret($secret);
            $client->redirecturi = $uris['redirecturi'];
            $client->postlogouturi = $uris['postlogouturi'];
            $client->timemodified = $now;
            $DB->update_record(self::TABLE, $client);
        } else {
            $client = (object) [
                'spokeid' => (int) $spoke->id,
                'clientid' => self::new_clientid(),
                'secrethash' => self::hash_secret($secret),
                'redirecturi' => $uris['redirecturi'],
                'postlogouturi' => $uris['postlogouturi'],
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $client->id = $DB->insert_record(self::TABLE, $client);
        }

        return [
            'issuer' => provider::issuer(),
            'clientid' => (string) $client->clientid,
            'clientsecret' => $secret,
        ];
    }

    /**
     * Delete every client of a Nucleus spoke, with its codes and tokens.
     *
     * Covers every spoke row with that ID, whatever its status.
     * Idempotent.
     *
     * @param string $cpspokeid Nucleus spoke ID.
     * @return void
     */
    public static function delete(string $cpspokeid): void {
        global $DB;

        $spokeids = $DB->get_fieldset_select(
            'local_nucleushub_spokes',
            'id',
            'cpspokeid = :cpspokeid',
            ['cpspokeid' => $cpspokeid]
        );
        foreach ($spokeids as $spokeid) {
            self::delete_for_spoke_row((int) $spokeid);
        }
    }

    /**
     * Delete a spoke row's client, with its codes and tokens.
     *
     * @param int $spokeid local_nucleushub_spokes.id
     * @return void
     */
    public static function delete_for_spoke_row(int $spokeid): void {
        global $DB;

        $client = $DB->get_record(self::TABLE, ['spokeid' => $spokeid]);
        if (!$client) {
            return;
        }
        $transaction = $DB->start_delegated_transaction();
        tokens::delete_for_client((string) $client->clientid);
        $DB->delete_records(self::TABLE, ['id' => $client->id]);
        $transaction->allow_commit();
    }

    /**
     * A spoke moved to a new address and so to a new spoke row: move its
     * client too, with URIs for the new address, so sign-in keeps
     * working without registering again.
     *
     * If the new row already has a client, the old row's client is
     * deleted instead. Outstanding codes are dropped either way, since
     * they are bound to the old redirect URI.
     *
     * @param int $fromspokeid The old local_nucleushub_spokes.id.
     * @param \stdClass $tospoke The new spoke row (needs id and wwwroot).
     * @return void
     */
    public static function move(int $fromspokeid, \stdClass $tospoke): void {
        global $DB;

        $client = $DB->get_record(self::TABLE, ['spokeid' => $fromspokeid]);
        if (!$client) {
            return;
        }
        if ($DB->record_exists(self::TABLE, ['spokeid' => $tospoke->id])) {
            self::delete_for_spoke_row($fromspokeid);
            return;
        }
        try {
            $uris = self::uris_for((string) $tospoke->wwwroot);
        } catch (\moodle_exception $e) {
            self::delete_for_spoke_row($fromspokeid);
            return;
        }
        self::point_at($client, (int) $tospoke->id, $uris);
    }

    /**
     * A spoke row's address changed in place (move_spoke): point its
     * client at the new address, so sign-in keeps working without
     * registering again.
     *
     * Unlike {@see self::move()}, the client stays on the same spoke row.
     * The redirect and post-logout URIs are rebuilt from the row's new
     * wwwroot with the rules of {@see self::uris_for()}, and outstanding
     * codes are dropped, since they are bound to the old redirect URI.
     * The client id and secret stay the same, so the spoke's sign-in
     * settings need no change. Access tokens aren't tied to an address
     * and are left to expire.
     *
     * @param \stdClass $spoke The spoke row, already at its new address (needs id and wwwroot).
     * @return bool True if the spoke's client was moved, false if the spoke has no client.
     * @throws \moodle_exception oidc_badspokeurl if sign-in can't use the address. Nothing is changed then.
     */
    public static function repoint(\stdClass $spoke): bool {
        global $DB;

        $client = $DB->get_record(self::TABLE, ['spokeid' => $spoke->id]);
        if (!$client) {
            return false;
        }
        $uris = self::uris_for((string) $spoke->wwwroot);
        self::point_at($client, (int) $spoke->id, $uris);
        return true;
    }

    /**
     * Give a client its spoke row and URIs, dropping its outstanding codes.
     *
     * @param \stdClass $client Client row.
     * @param int $spokeid local_nucleushub_spokes.id the client belongs to from now on.
     * @param array{redirecturi: string, postlogouturi: string} $uris From {@see self::uris_for()}.
     * @return void
     */
    private static function point_at(\stdClass $client, int $spokeid, array $uris): void {
        global $DB;

        $DB->delete_records('local_nucleushub_oidc_code', ['clientid' => $client->clientid]);
        $DB->update_record(self::TABLE, (object) [
            'id' => $client->id,
            'spokeid' => $spokeid,
            'redirecturi' => $uris['redirecturi'],
            'postlogouturi' => $uris['postlogouturi'],
            'timemodified' => time(),
        ]);
    }

    /**
     * An active client by its public id, with its spoke's details.
     *
     * @param string $clientid
     * @return \stdClass|null Client row plus `spokename`, `spokewwwroot` and `cpspokeid`.
     */
    public static function find_active(string $clientid): ?\stdClass {
        global $DB;

        if ($clientid === '' || strlen($clientid) > 64) {
            return null;
        }
        $sql = "SELECT c.*, s.name AS spokename, s.wwwroot AS spokewwwroot, s.cpspokeid
                  FROM {" . self::TABLE . "} c
                  JOIN {local_nucleushub_spokes} s ON s.id = c.spokeid
                 WHERE c.clientid = :clientid AND s.status = :active";
        $client = $DB->get_record_sql($sql, ['clientid' => $clientid, 'active' => 'active']);
        // Exact match only, whatever the database collation.
        if (!$client || !hash_equals((string) $client->clientid, $clientid)) {
            return null;
        }
        return $client;
    }

    /**
     * Check a client's credentials.
     *
     * @param string $clientid
     * @param string $secret
     * @return \stdClass|null The active client, or null if the id or secret is wrong.
     */
    public static function authenticate(string $clientid, string $secret): ?\stdClass {
        $client = self::find_active($clientid);
        $presented = self::hash_secret($secret);
        if (!$client || $secret === '') {
            // Same work either way.
            hash_equals(self::DUMMY_HASH, $presented);
            return null;
        }
        return hash_equals((string) $client->secrethash, $presented) ? $client : null;
    }

    /**
     * The stored form of a client secret.
     *
     * @param string $secret
     * @return string Hex SHA-256.
     */
    public static function hash_secret(string $secret): string {
        return hash('sha256', $secret);
    }

    /**
     * Is this exactly the post-logout URI of an active client?
     *
     * @param string $uri
     * @return bool
     */
    public static function is_post_logout_uri(string $uri): bool {
        global $DB;

        if ($uri === '') {
            return false;
        }
        $sql = "SELECT c.postlogouturi
                  FROM {" . self::TABLE . "} c
                  JOIN {local_nucleushub_spokes} s ON s.id = c.spokeid
                 WHERE s.status = :active";
        foreach ($DB->get_fieldset_sql($sql, ['active' => 'active']) as $registered) {
            if ((string) $registered === $uri) {
                return true;
            }
        }
        return false;
    }

    /**
     * The one active spoke row for a Nucleus spoke ID.
     *
     * @param string $cpspokeid
     * @return \stdClass|null
     */
    public static function active_spoke(string $cpspokeid): ?\stdClass {
        global $DB;

        $cpspokeid = trim($cpspokeid);
        if ($cpspokeid === '') {
            return null;
        }
        $rows = $DB->get_records(
            'local_nucleushub_spokes',
            ['cpspokeid' => $cpspokeid, 'status' => 'active'],
            'timemodified DESC, id DESC',
            '*',
            0,
            1
        );
        return $rows ? reset($rows) : null;
    }

    /**
     * The redirect and post-logout URIs for a spoke address.
     *
     * The address must be an absolute http(s) URL with a host and no
     * query, fragment or credentials. When the hub itself runs on https,
     * so must the spoke.
     *
     * @param string $wwwroot Spoke wwwroot.
     * @return array{redirecturi: string, postlogouturi: string}
     * @throws \moodle_exception If the address can't be used.
     */
    public static function uris_for(string $wwwroot): array {
        global $CFG;

        $wwwroot = rtrim(trim($wwwroot), '/');
        $parts = parse_url($wwwroot);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $hubhttps = str_starts_with(strtolower($CFG->wwwroot), 'https://');
        if (
            !$parts || empty($parts['host'])
            || !in_array($scheme, ['http', 'https'], true)
            || ($hubhttps && $scheme !== 'https')
            || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['user']) || isset($parts['pass'])
            || preg_match('/[\s<>"\'\\\\]/', $wwwroot)
        ) {
            throw new \moodle_exception('oidc_badspokeurl', 'local_nucleushub');
        }
        return [
            'redirecturi' => $wwwroot . self::CALLBACK_PATH,
            'postlogouturi' => $wwwroot . '/',
        ];
    }

    /**
     * A new public client id.
     *
     * @return string
     */
    private static function new_clientid(): string {
        return 'nucleus-' . bin2hex(random_bytes(16));
    }

    /**
     * A new client secret: 32 random bytes, base64url.
     *
     * @return string
     */
    private static function new_secret(): string {
        return JWT::urlsafeB64Encode(random_bytes(32));
    }
}
