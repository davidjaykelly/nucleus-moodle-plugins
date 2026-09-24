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

namespace local_nucleushub\local;

/**
 * Each spoke's own identity on the hub (ADR-023 section 6).
 *
 * A spoke calls the hub's `nucleus_federation` web service as its own
 * service account: a Moodle user named `nucleus_spoke_<row id>` that
 * can't sign in, is allowed on that service only, and holds exactly one
 * permanent token. So a spoke's token can be revoked without touching
 * any other spoke, and the hub can tell which spoke is calling.
 *
 * Two Moodle rules shape the account:
 * - Web services refuse `nologin` accounts, so it uses the core
 *   `webservice` auth plugin, which can't sign in through the login
 *   page or reset a password. It has no stored password either.
 * - The REST server needs `webservice/rest:use`, which no standard role
 *   gives. The account gets the "Nucleus spoke" role, which allows that
 *   one capability at site level and nothing else.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class spoke_identity {
    /** @var string Shortname of the web service spokes call. */
    public const SERVICE = 'nucleus_federation';

    /** @var string Shortname of the role that lets a spoke's account use REST. */
    public const ROLE = 'nucleusspoke';

    /** @var string Service account usernames: this, then the spoke row id. */
    public const USERNAME_PREFIX = 'nucleus_spoke_';

    /** @var string Auth plugin of service accounts. */
    public const AUTH = 'webservice';

    /**
     * Turn on web services and the REST protocol, and return the
     * service spokes call.
     *
     * @return \stdClass external_services row.
     */
    public static function service(): \stdClass {
        global $DB;

        if (!get_config('core', 'enablewebservices')) {
            set_config('enablewebservices', 1);
        }
        $existing = get_config('core', 'webserviceprotocols');
        $protocols = ($existing && is_string($existing)) ? explode(',', $existing) : [];
        if (!in_array('rest', $protocols, true)) {
            $protocols[] = 'rest';
            set_config('webserviceprotocols', implode(',', array_filter($protocols)));
        }

        return $DB->get_record('external_services', ['shortname' => self::SERVICE], '*', MUST_EXIST);
    }

    /**
     * Give a spoke its own service account and token, reusing and
     * repairing the ones it already has.
     *
     * Idempotent: a second call returns the same account and token.
     *
     * @param \stdClass $spoke local_nucleushub_spokes row, already saved (it needs an id).
     * @param \stdClass $service From {@see self::service()}.
     * @return array{userid: int, token: string}
     */
    public static function ensure(\stdClass $spoke, \stdClass $service): array {
        $user = self::ensure_user($spoke);
        self::ensure_access((int) $user->id, $service);
        $token = self::ensure_token((int) $user->id, $service, $spoke);
        return ['userid' => (int) $user->id, 'token' => $token];
    }

    /**
     * Cut a spoke's service account off: delete its tokens and take it
     * off the service's allowed users. Cheap, and safe inside a
     * transaction.
     *
     * @param int $userid local_nucleushub_spokes.serviceuserid
     * @return void
     */
    public static function revoke(int $userid): void {
        global $DB;

        if (!self::live_service_account($userid)) {
            return;
        }
        $DB->delete_records('external_tokens', ['userid' => $userid]);
        $DB->delete_records('external_services_users', ['userid' => $userid]);
    }

    /**
     * Delete a spoke's service account: its tokens, its place on the
     * service, then the account itself. Safe to repeat.
     *
     * @param int $userid local_nucleushub_spokes.serviceuserid
     * @return void
     */
    public static function remove(int $userid): void {
        $user = self::live_service_account($userid);
        if (!$user) {
            return;
        }
        self::revoke($userid);
        delete_user($user);
    }

    /**
     * Retire the token every spoke used to share, once no active spoke
     * needs it.
     *
     * Before ADR-023, register_spoke handed every spoke the site
     * admin's token for this service. When every active spoke row has
     * its own service account, delete the site admins' tokens for this
     * service and take them off its allowed users. Until then, change
     * nothing: spokes that haven't been registered again still use it.
     *
     * @param \stdClass $service From {@see self::service()}.
     * @return bool True if no active spoke needs the shared token any more.
     */
    public static function retire_shared_token(\stdClass $service): bool {
        global $DB;

        $waiting = $DB->count_records_select(
            'local_nucleushub_spokes',
            'status = :active AND (serviceuserid IS NULL OR serviceuserid = 0)',
            ['active' => 'active']
        );
        if ($waiting > 0) {
            return false;
        }

        $adminids = array_map('intval', array_keys(get_admins()));
        if (!$adminids) {
            return true;
        }
        [$insql, $params] = $DB->get_in_or_equal($adminids, SQL_PARAMS_NAMED);
        $params['serviceid'] = (int) $service->id;
        $DB->delete_records_select('external_tokens', "externalserviceid = :serviceid AND userid {$insql}", $params);
        $DB->delete_records_select('external_services_users', "externalserviceid = :serviceid AND userid {$insql}", $params);
        return true;
    }

    /**
     * The spoke's service account, created if it has none.
     *
     * An account the row already points at is reused and repaired
     * (right auth, confirmed, not suspended), so the token returned
     * always works.
     *
     * @param \stdClass $spoke
     * @return \stdClass user row
     * @throws \moodle_exception If the username is taken by an account that isn't a service account.
     */
    private static function ensure_user(\stdClass $spoke): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');

        if (!empty($spoke->serviceuserid)) {
            $user = $DB->get_record('user', ['id' => $spoke->serviceuserid, 'deleted' => 0]);
            if ($user && self::is_service_account($user)) {
                self::repair_user($user);
                return $user;
            }
        }

        $username = self::USERNAME_PREFIX . (int) $spoke->id;
        $user = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id]);
        if ($user) {
            // Only adopt an account that looks like one this class made.
            if (!empty($user->deleted) || $user->auth !== self::AUTH || !self::is_service_account($user)) {
                throw new \moodle_exception('serviceuserclash', 'local_nucleushub', '', $username);
            }
            self::repair_user($user);
            return $user;
        }

        $name = trim((string) ($spoke->name ?? ''));
        $email = !empty($CFG->noreplyaddress)
            ? $CFG->noreplyaddress
            : 'noreply@' . (get_host_from_url($CFG->wwwroot) ?: 'localhost');
        $user = (object) [
            'auth' => self::AUTH,
            'username' => $username,
            'password' => AUTH_PASSWORD_NOT_CACHED,
            'firstname' => get_string('serviceuser_firstname', 'local_nucleushub'),
            'lastname' => $name !== '' ? \core_text::substr($name, 0, 100) : $username,
            'email' => $email,
            'emailstop' => 1,
            'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
            'lang' => $CFG->lang ?? 'en',
            'timezone' => '99',
        ];
        $user->id = user_create_user($user, false, false);
        return $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
    }

    /**
     * Put back what a service account needs to call the hub.
     *
     * @param \stdClass $user
     * @return void
     */
    private static function repair_user(\stdClass $user): void {
        global $CFG;

        $fix = [];
        if ($user->auth !== self::AUTH) {
            $fix['auth'] = self::AUTH;
        }
        if (empty($user->confirmed)) {
            $fix['confirmed'] = 1;
        }
        if (!empty($user->suspended)) {
            $fix['suspended'] = 0;
        }
        if (!$fix) {
            return;
        }
        require_once($CFG->dirroot . '/user/lib.php');
        user_update_user((object) (['id' => (int) $user->id] + $fix), false, false);
    }

    /**
     * Allow the account on the service, with the role that lets it use REST.
     *
     * @param int $userid
     * @param \stdClass $service
     * @return void
     */
    private static function ensure_access(int $userid, \stdClass $service): void {
        global $DB;

        role_assign(self::role(), $userid, \context_system::instance()->id, 'local_nucleushub');

        if (!$DB->record_exists('external_services_users', ['externalserviceid' => $service->id, 'userid' => $userid])) {
            $DB->insert_record('external_services_users', (object) [
                'externalserviceid' => (int) $service->id,
                'userid' => $userid,
                'timecreated' => time(),
            ]);
        }
    }

    /**
     * The "Nucleus spoke" role, created the first time a spoke registers.
     *
     * Site level only, with `webservice/rest:use` and nothing else.
     *
     * @return int role id
     */
    private static function role(): int {
        global $DB;

        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => self::ROLE]);
        if (!$roleid) {
            $roleid = (int) create_role(
                get_string('spokerole_name', 'local_nucleushub'),
                self::ROLE,
                get_string('spokerole_description', 'local_nucleushub')
            );
            set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        }

        $systemid = \context_system::instance()->id;
        $permission = $DB->get_field('role_capabilities', 'permission', [
            'roleid' => $roleid,
            'contextid' => $systemid,
            'capability' => 'webservice/rest:use',
        ]);
        if ((int) $permission !== CAP_ALLOW) {
            assign_capability('webservice/rest:use', CAP_ALLOW, $roleid, $systemid, true);
        }
        return $roleid;
    }

    /**
     * The account's one permanent token for the service.
     *
     * Keeps the oldest working token, deletes any others the account
     * has (for any service), and mints one if none is left.
     *
     * @param int $userid
     * @param \stdClass $service
     * @param \stdClass $spoke
     * @return string token
     */
    private static function ensure_token(int $userid, \stdClass $service, \stdClass $spoke): string {
        global $DB;

        $keep = null;
        $now = time();
        foreach ($DB->get_records('external_tokens', ['userid' => $userid], 'timecreated ASC, id ASC') as $token) {
            $usable = (int) $token->externalserviceid === (int) $service->id
                && (int) $token->tokentype === EXTERNAL_TOKEN_PERMANENT
                && (empty($token->validuntil) || (int) $token->validuntil > $now);
            if ($keep === null && $usable) {
                $keep = $token;
                continue;
            }
            $DB->delete_records('external_tokens', ['id' => $token->id]);
        }
        if ($keep) {
            return (string) $keep->token;
        }

        $name = trim((string) ($spoke->name ?? ''));
        return \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $service,
            $userid,
            \context_system::instance(),
            0,
            '',
            get_string('serviceuser_tokenname', 'local_nucleushub', $name !== '' ? $name : (string) $spoke->wwwroot)
        );
    }

    /**
     * Is this one of the spoke service accounts this class makes?
     *
     * @param \stdClass $user
     * @return bool
     */
    private static function is_service_account(\stdClass $user): bool {
        return str_starts_with((string) $user->username, self::USERNAME_PREFIX) && !is_siteadmin($user->id);
    }

    /**
     * The user, if it is a spoke service account that hasn't been deleted.
     *
     * Anything else is left alone: a row pointing at the wrong user must
     * never cost that user their tokens.
     *
     * @param int $userid
     * @return \stdClass|null user row
     */
    private static function live_service_account(int $userid): ?\stdClass {
        global $DB;

        if ($userid <= 0) {
            return null;
        }
        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user || !empty($user->deleted)) {
            return null;
        }
        if (!self::is_service_account($user)) {
            debugging('Nucleus: user ' . $userid . ' is not a spoke service account, so it was left alone.', DEBUG_DEVELOPER);
            return null;
        }
        return $user;
    }
}
