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
 * Each hub user's opaque subject identifier: the `sub` claim.
 *
 * Spokes link their accounts to `sub`, so it must never pass from one
 * person to another. A user id can: after a database restore, ids are
 * handed out again. So `sub` is 16 random bytes, as 32 lowercase hex
 * characters, made the first time a user needs one and kept in
 * `local_nucleushub_oidc_subject`. It is deleted with the user, so a
 * later account never inherits it.
 *
 * Creation is race-safe: both columns are unique, so of two requests
 * making a subject for the same user, one insert fails and that request
 * reads back the winner's row.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subjects {
    /** @var string Subject table. */
    public const TABLE = 'local_nucleushub_oidc_subject';

    /** @var string Shape of a subject: what spokes check. */
    public const PATTERN = '/^[a-f0-9]{32}$/';

    /** @var int Inserts tried before giving up. */
    private const ATTEMPTS = 3;

    /**
     * A user's subject, made if they don't have one yet.
     *
     * Don't call this inside a database transaction: on PostgreSQL a
     * failed insert (a lost race) spoils the whole transaction.
     *
     * @param int $userid
     * @param callable|null $generate Makes a new subject. Tests only.
     * @return string 32 lowercase hex characters.
     * @throws \moodle_exception If no subject could be stored.
     */
    public static function for_user(int $userid, ?callable $generate = null): string {
        global $DB;

        if ($userid <= 0) {
            throw new \coding_exception('A subject needs a real user id.');
        }
        $existing = self::find_for_user($userid);
        if ($existing !== null) {
            return $existing;
        }

        $generate = $generate ?? [self::class, 'generate'];
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $sub = (string) $generate();
            if (!preg_match(self::PATTERN, $sub)) {
                throw new \coding_exception('A subject must be 32 lowercase hex characters.');
            }
            try {
                $DB->insert_record(self::TABLE, (object) [
                    'userid' => $userid,
                    'sub' => $sub,
                    'timecreated' => time(),
                ]);
                return $sub;
            } catch (\dml_write_exception $e) {
                // Another request made this user's subject first, or (in
                // theory) the random value is taken. Use theirs, or try
                // a new value.
                $existing = self::find_for_user($userid);
                if ($existing !== null) {
                    return $existing;
                }
            }
        }
        throw new \moodle_exception('oidc_nosubject', 'local_nucleushub');
    }

    /**
     * Subjects for several users, made where missing.
     *
     * @param int[] $userids
     * @return string[] userid => subject
     */
    public static function for_users(array $userids): array {
        global $DB;

        $userids = array_values(array_unique(array_filter(array_map('intval', $userids), fn($id) => $id > 0)));
        if (!$userids) {
            return [];
        }
        $subs = [];
        foreach ($DB->get_records_list(self::TABLE, 'userid', $userids, '', 'id, userid, sub') as $row) {
            $subs[(int) $row->userid] = (string) $row->sub;
        }
        foreach ($userids as $userid) {
            if (!isset($subs[$userid])) {
                $subs[$userid] = self::for_user($userid);
            }
        }
        return $subs;
    }

    /**
     * A user's subject, if they have one. Never makes one.
     *
     * @param int $userid
     * @return string|null
     */
    public static function find_for_user(int $userid): ?string {
        global $DB;

        if ($userid <= 0) {
            return null;
        }
        $sub = $DB->get_field(self::TABLE, 'sub', ['userid' => $userid]);
        return ($sub === false || $sub === null) ? null : (string) $sub;
    }

    /**
     * Forget a user's subject. Their spoke accounts can't be signed in
     * to through the hub again, and no later account can take them over.
     *
     * @param int $userid
     * @return void
     */
    public static function delete_for_user(int $userid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['userid' => $userid]);
    }

    /**
     * A new random subject.
     *
     * @return string 32 lowercase hex characters.
     */
    public static function generate(): string {
        return bin2hex(random_bytes(16));
    }
}
