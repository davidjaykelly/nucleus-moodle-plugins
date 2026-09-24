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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/authlib.php');

/**
 * Which local account a hub account signs in to, and the linking step.
 *
 * The rules (ADR-023 section 4):
 * - A hub account signs in to the local account linked to its issuer
 *   and sub in auth_nucleus_link, and only that one.
 * - Email never links anything. When a sub isn't linked and an account
 *   with the same email already exists here, sign-in is refused: that
 *   account has to be linked through Nucleus first.
 * - Otherwise the first sign-in creates an account (auth=nucleus,
 *   username hub-{sub}) and links it.
 * - A privileged account is never linked, created or signed in to
 *   through here: a site admin, anyone with a role assigned at site
 *   level, or anyone who can configure the site, assign roles or edit
 *   users there. Nor is a deleted or suspended account.
 * - Turning sign-in off gives linked accounts back the login method they
 *   had before they were linked (when that method is still on), and
 *   makes accounts the hub created manual accounts.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class accounts {
    /** @var string The link table. */
    public const TABLE = 'auth_nucleus_link';

    /** @var string The hub's sub: an opaque 32-character lower-case hex string. */
    public const SUB_PATTERN = '/^[a-f0-9]{32}$/';

    /** @var int Most accounts listed per page. */
    public const MAX_PER_PAGE = 500;

    /** @var string[] Capabilities at site level that make an account privileged. */
    public const PRIVILEGED_CAPS = ['moodle/site:config', 'moodle/role:assign', 'moodle/user:update'];

    /** @var string[] Account types that are never linked or signed in to. */
    private const BLOCKED_AUTH = ['nologin', 'webservice'];

    /**
     * Is this a usable hub sub?
     *
     * @param mixed $sub
     * @return bool
     */
    public static function valid_sub($sub): bool {
        return is_string($sub) && preg_match(self::SUB_PATTERN, $sub) === 1;
    }

    /**
     * The username for a hub account created here.
     *
     * @param string $sub A valid sub (already lower case).
     * @return string
     */
    public static function username_for(string $sub): string {
        return 'hub-' . $sub;
    }

    /**
     * The indexed form of an issuer (Moodle can't index the full URL).
     *
     * @param string $issuer
     * @return string 64 hex characters.
     */
    public static function issuer_hash(string $issuer): string {
        return hash('sha256', $issuer);
    }

    /**
     * Is this account privileged?
     *
     * A site admin, anyone with a role assigned in the system context, or
     * anyone with moodle/site:config, moodle/role:assign or
     * moodle/user:update in the system context.
     *
     * @param int $userid
     * @return bool
     */
    public static function is_privileged(int $userid): bool {
        global $DB;

        if ($userid <= 0) {
            return false;
        }
        if (is_siteadmin($userid)) {
            return true;
        }
        // Any role at site or course-category level: a category Manager
        // can manage roles, courses and "log in as" people in that
        // category, which is too much to hand to another site's users.
        $sql = "SELECT 1
                  FROM {role_assignments} ra
                  JOIN {context} ctx ON ctx.id = ra.contextid
                 WHERE ra.userid = :userid
                   AND ctx.contextlevel IN (:system, :category)";
        if ($DB->record_exists_sql($sql, ['userid' => $userid, 'system' => CONTEXT_SYSTEM, 'category' => CONTEXT_COURSECAT])) {
            return true;
        }
        return self::has_privileged_capability($userid);
    }

    /**
     * Does the account have a privileged capability in the system context?
     *
     * @param int $userid
     * @return bool
     */
    private static function has_privileged_capability(int $userid): bool {
        $system = \context_system::instance();
        foreach (self::PRIVILEGED_CAPS as $capability) {
            if (has_capability($capability, $system, $userid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The refusal for a privileged account.
     *
     * @param int $userid
     * @param string $why For the logs.
     * @return signin_exception
     */
    private static function privileged_refusal(int $userid, string $why): signin_exception {
        return new signin_exception(is_siteadmin($userid) ? 'siteadmin' : 'privileged', AUTH_LOGIN_UNAUTHORISED,
            $why, null, $userid);
    }

    /**
     * Find, or create, the local account for verified ID token claims.
     *
     * @param \stdClass $claims Verified claims (see {@see id_token::verify()}).
     * @return \stdClass The complete user record, ready for complete_user_login().
     * @throws signin_exception If this person can't sign in here.
     */
    public static function sign_in(\stdClass $claims): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');

        $issuer = config::issuer();
        if ($issuer === '' || ($claims->iss ?? null) !== $issuer) {
            throw new signin_exception('notenabled', AUTH_LOGIN_FAILED, 'claims are not from the configured issuer');
        }
        $sub = $claims->sub ?? null;
        if (!self::valid_sub($sub)) {
            throw new signin_exception('claims', AUTH_LOGIN_NOUSER, 'sub missing or unusable');
        }
        $profile = self::profile_from_claims($claims);

        $link = self::find_link($issuer, $sub);
        if ($link) {
            $user = $DB->get_record('user', ['id' => (int) $link->userid]);
            self::check_linked_account($user, $link);
            self::refresh($user, $profile);
            $userid = (int) $user->id;
        } else {
            $userid = self::create($issuer, $sub, $profile);
        }

        $user = get_complete_user_data('id', $userid);
        // Checked again on the record that will be signed in.
        if (!$user || !empty($user->suspended) || $user->auth !== config::AUTH) {
            throw new signin_exception('account', AUTH_LOGIN_UNAUTHORISED, 'account failed the final check', null, $userid);
        }
        if (self::is_privileged($userid)) {
            throw self::privileged_refusal($userid, 'account is privileged (final check)');
        }
        return $user;
    }

    /**
     * The link for this issuer and sub, if there is one.
     *
     * @param string $issuer
     * @param string $sub
     * @return \stdClass|null
     */
    public static function find_link(string $issuer, string $sub): ?\stdClass {
        global $DB;
        $link = $DB->get_record(self::TABLE, ['issuerhash' => self::issuer_hash($issuer), 'sub' => $sub]);
        // The hash is only the index; the issuer itself must match too.
        return ($link && $link->issuer === $issuer) ? $link : null;
    }

    /**
     * The profile fields the hub sends: email, names and language.
     *
     * @param \stdClass $claims
     * @return array field => value; lang only when that language is installed here.
     * @throws signin_exception If the email or a name is missing.
     */
    private static function profile_from_claims(\stdClass $claims): array {
        $email = is_string($claims->email ?? null) ? trim($claims->email) : '';
        $firstname = is_string($claims->given_name ?? null) ? trim($claims->given_name) : '';
        $lastname = is_string($claims->family_name ?? null) ? trim($claims->family_name) : '';

        if (
            $email === ''
            || \core_text::strlen($email) > 100
            || !validate_email($email)
            || clean_param($email, PARAM_EMAIL) !== $email
        ) {
            throw new signin_exception('claims', AUTH_LOGIN_NOUSER, 'no usable email claim');
        }
        $firstname = \core_text::substr(clean_param($firstname, PARAM_NOTAGS), 0, 100);
        $lastname = \core_text::substr(clean_param($lastname, PARAM_NOTAGS), 0, 100);
        if ($firstname === '' || $lastname === '') {
            throw new signin_exception('claims', AUTH_LOGIN_NOUSER, 'no given_name or family_name claim');
        }

        $profile = ['email' => $email, 'firstname' => $firstname, 'lastname' => $lastname];

        // Moodle language codes are lower case with underscores (en_us);
        // the hub may send a BCP 47 tag (en-US). Only installed ones count.
        if (is_string($claims->locale ?? null) && $claims->locale !== '') {
            $lang = str_replace('-', '_', \core_text::strtolower(trim($claims->locale)));
            $lang = clean_param($lang, PARAM_LANG);
            if ($lang !== '') {
                $profile['lang'] = $lang;
            }
        }
        return $profile;
    }

    /**
     * Refuse a linked account that mustn't be signed in to.
     *
     * @param \stdClass|false $user The linked account's user record.
     * @param \stdClass $link Its link.
     * @throws signin_exception
     */
    private static function check_linked_account($user, \stdClass $link): void {
        global $CFG;

        if (!$user || !empty($user->deleted)) {
            throw new signin_exception('account', AUTH_LOGIN_NOUSER, 'linked account deleted',
                null, $user ? (int) $user->id : null);
        }
        $userid = (int) $user->id;
        if (self::is_privileged($userid)) {
            throw self::privileged_refusal($userid, 'linked account is privileged');
        }
        if (!empty($user->suspended)) {
            throw new signin_exception('account', AUTH_LOGIN_SUSPENDED, 'linked account suspended', null, $userid);
        }
        if (isguestuser($user) || (int) $user->mnethostid !== (int) $CFG->mnet_localhost_id) {
            throw new signin_exception('account', AUTH_LOGIN_UNAUTHORISED, 'linked account is the guest or remote',
                null, $userid);
        }
        // A hub account; or one turning sign-in off gave back its own
        // login method (manual, or what it had before it was linked).
        $allowed = [config::AUTH, 'manual'];
        $previous = (string) ($link->previousauth ?? '');
        if ($previous !== '' && !in_array($previous, self::BLOCKED_AUTH, true)) {
            $allowed[] = $previous;
        }
        if (!in_array($user->auth, $allowed, true)) {
            throw new signin_exception('account', AUTH_LOGIN_UNAUTHORISED,
                'linked account uses auth ' . clean_param($user->auth, PARAM_PLUGIN), null, $userid);
        }
    }

    /**
     * Bring a linked account up to date with the hub.
     *
     * Email and names (locked here) follow the hub; language doesn't,
     * once the account exists. A linked account that turning sign-in off
     * gave back its own login method becomes a hub account again, without
     * a local password.
     *
     * @param \stdClass $user
     * @param array $profile From {@see profile_from_claims()}.
     */
    private static function refresh(\stdClass $user, array $profile): void {
        $update = (object) ['id' => $user->id];
        $changed = false;
        foreach ($profile as $field => $value) {
            // Language is taken from the hub only when the account is
            // made; after that it's the person's own choice on this site.
            if ($field === 'lang') {
                continue;
            }
            if ((string) $user->$field !== $value) {
                $update->$field = $value;
                $changed = true;
            }
        }
        if ($user->auth !== config::AUTH) {
            $update->auth = config::AUTH;
            $update->password = AUTH_PASSWORD_NOT_CACHED;
            $changed = true;
        }
        if (empty($user->confirmed)) {
            // The hub only signs in confirmed people.
            $update->confirmed = '1';
            $changed = true;
        }
        if ($changed) {
            user_update_user($update, false, true);
        }
        // A forced password change can't be done by a hub account and
        // would stop complete_user_login().
        self::clear_forced_password_change((int) $user->id);
    }

    /**
     * Drop a pending forced password change, which a hub account can't do.
     *
     * @param int $userid
     */
    private static function clear_forced_password_change(int $userid): void {
        if (get_user_preferences('auth_forcepasswordchange', null, $userid) !== null) {
            unset_user_preference('auth_forcepasswordchange', $userid);
        }
    }

    /**
     * Create and link an account for a sub that isn't linked yet.
     *
     * @param string $issuer
     * @param string $sub
     * @param array $profile From {@see profile_from_claims()}.
     * @return int The new user id.
     * @throws signin_exception
     */
    private static function create(string $issuer, string $sub, array $profile): int {
        global $CFG, $DB;

        // Never link by email, and never make a second account for
        // someone who already has one: that account must be linked
        // through Nucleus. Any account with this email counts, privileged
        // and suspended accounts included (a privileged one can't be
        // linked, so its owner keeps signing in locally).
        $existing = self::find_by_email($profile['email']);
        if ($existing !== null) {
            // The local account isn't the one being signed in to, so it
            // isn't named as the failed login's user; the log says which.
            $what = self::is_privileged($existing) ? 'privileged local account ' : 'local account ';
            throw new signin_exception('emailexists', AUTH_LOGIN_UNAUTHORISED,
                'sub not linked and ' . $what . $existing . ' has the same email; not linked, not created',
                $profile['email']);
        }

        if (!empty($CFG->authpreventaccountcreation)) {
            throw new signin_exception('nocreate', AUTH_LOGIN_UNAUTHORISED,
                'sub not linked and authpreventaccountcreation is on');
        }

        $username = self::username_for($sub);
        if ($username !== \core_user::clean_field($username, 'username')) {
            throw new signin_exception('claims', AUTH_LOGIN_NOUSER, 'sub makes an invalid username');
        }
        if ($DB->record_exists('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id])) {
            // Never take over an account by its username either.
            throw new signin_exception('account', AUTH_LOGIN_UNAUTHORISED,
                'username for this sub already belongs to an unlinked account');
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $user = (object) array_merge($profile, [
                'auth' => config::AUTH,
                'confirmed' => 1,
                'mnethostid' => $CFG->mnet_localhost_id,
                'username' => $username,
                'password' => AUTH_PASSWORD_NOT_CACHED,
            ]);
            $userid = (int) user_create_user($user, false, true);
            // Only possible when the default role for every user grants a
            // privileged capability; then no account is made at all.
            if (self::is_privileged($userid)) {
                throw new signin_exception('privileged', AUTH_LOGIN_UNAUTHORISED,
                    'a new account would be privileged; not created');
            }
            self::insert_link($userid, $issuer, $sub, null);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            try {
                $transaction->rollback($e);
            } catch (\Throwable $ignored) {
                // Rollback rethrows; the refusal below is what matters.
                unset($ignored);
            }
            if ($e instanceof signin_exception) {
                throw $e;
            }
            // Most likely a second sign-in for the same person racing
            // this one; the next attempt will find the link.
            throw new signin_exception('generic', AUTH_LOGIN_FAILED, 'could not create the account');
        }
        return $userid;
    }

    /**
     * Store a link.
     *
     * @param int $userid
     * @param string $issuer
     * @param string $sub
     * @param string|null $previousauth The account's login method before linking; null if the hub made it.
     */
    private static function insert_link(int $userid, string $issuer, string $sub, ?string $previousauth): void {
        global $DB;
        $DB->insert_record(self::TABLE, (object) [
            'userid' => $userid,
            'issuer' => $issuer,
            'issuerhash' => self::issuer_hash($issuer),
            'sub' => $sub,
            'previousauth' => $previousauth,
            'timecreated' => time(),
        ]);
    }

    /**
     * A local, non-deleted account with this email (any case), if any.
     *
     * @param string $email
     * @return int|null The lowest matching user id.
     */
    public static function find_by_email(string $email): ?int {
        global $CFG, $DB;

        $select = 'deleted = 0 AND mnethostid = :mnethostid AND id <> :guestid AND '
            . $DB->sql_equal('email', ':email', false);
        $ids = $DB->get_fieldset_select('user', 'id', $select, [
            'mnethostid' => $CFG->mnet_localhost_id,
            'guestid' => (int) ($CFG->siteguest ?? 0),
            'email' => $email,
        ]);
        if (!$ids) {
            return null;
        }
        return (int) min(array_map('intval', $ids));
    }

    /**
     * Link local accounts to hub accounts of the configured issuer, as
     * reviewed in the portal.
     *
     * Each applied link records the account's login method, then sets
     * auth=nucleus on it and removes its local password. Skip reasons
     * (for the portal):
     * - siteadmin: the account is a site admin;
     * - privileged: the account has a site-level role or can configure
     *   the site, assign roles or edit users;
     * - suspended, deleted: the account is suspended or deleted;
     * - already_linked: the account is linked to another hub account;
     * - sub_linked: the hub account is linked to another account;
     * - not_found: there's no such account;
     * - not_allowed: the guest, a remote (MNet) account, or a service or
     *   blocked account (auth webservice or nologin);
     * - invalid_sub: the sub isn't a valid hub sub.
     *
     * @param array $links [['userid' => int, 'sub' => string], ...]
     * @return array ['applied' => int, 'skipped' => [['userid' => int, 'reason' => string], ...]]
     * @throws \moodle_exception If no issuer is configured.
     */
    public static function apply_links(array $links): array {
        $issuer = config::issuer();
        if ($issuer === '') {
            throw new \moodle_exception('error_linknotconfigured', 'auth_nucleus');
        }
        $applied = 0;
        $skipped = [];
        foreach ($links as $link) {
            $userid = (int) ($link['userid'] ?? 0);
            $reason = self::link_one($issuer, $userid, (string) ($link['sub'] ?? ''));
            if ($reason === null) {
                $applied++;
            } else {
                $skipped[] = ['userid' => $userid, 'reason' => $reason];
            }
        }
        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * Link one account.
     *
     * @param string $issuer
     * @param int $userid
     * @param string $sub
     * @return string|null Skip reason, or null when linked.
     */
    private static function link_one(string $issuer, int $userid, string $sub): ?string {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');

        if (!self::valid_sub($sub)) {
            return 'invalid_sub';
        }
        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user) {
            return 'not_found';
        }
        if (!empty($user->deleted)) {
            return 'deleted';
        }
        if (is_siteadmin($userid)) {
            return 'siteadmin';
        }
        if (self::is_privileged($userid)) {
            return 'privileged';
        }
        if (!empty($user->suspended)) {
            return 'suspended';
        }
        if (
            isguestuser($user)
            || (int) $user->mnethostid !== (int) $CFG->mnet_localhost_id
            || in_array($user->auth, self::BLOCKED_AUTH, true)
        ) {
            return 'not_allowed';
        }

        $hash = self::issuer_hash($issuer);
        $byuser = $DB->get_record(self::TABLE, ['userid' => $userid]);
        if ($byuser && ($byuser->issuerhash !== $hash || $byuser->issuer !== $issuer || $byuser->sub !== $sub)) {
            return 'already_linked';
        }
        $bysub = self::find_link($issuer, $sub);
        if ($bysub && (int) $bysub->userid !== $userid) {
            return 'sub_linked';
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            if (!$byuser) {
                // Remember how the account signed in, to give it back when
                // sign-in with the hub is turned off.
                self::insert_link($userid, $issuer, $sub, $user->auth !== config::AUTH ? (string) $user->auth : null);
            }
            if ($user->auth !== config::AUTH) {
                user_update_user((object) [
                    'id' => $userid,
                    'auth' => config::AUTH,
                    'password' => AUTH_PASSWORD_NOT_CACHED,
                ], false, true);
            }
            self::clear_forced_password_change($userid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            try {
                $transaction->rollback($e);
            } catch (\Throwable $ignored) {
                unset($ignored);
            }
            // Another request linked one side first.
            return self::find_link($issuer, $sub) ? 'sub_linked' : 'already_linked';
        }
        return null;
    }

    /**
     * Give every hub account (auth=nucleus) back a login method of its own.
     *
     * A linked account gets the method it had before it was linked, when
     * that authentication plugin is still enabled; otherwise, and for
     * accounts the hub created, it becomes a manual account (people use
     * "Forgotten your password?"). Their sessions end. Links are kept.
     *
     * @return int How many (non-deleted) accounts changed.
     */
    public static function release_hub_accounts(): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');

        \core\session\manager::destroy_by_auth_plugin(config::AUTH);

        $rows = $DB->get_records_sql(
            "SELECT u.id, l.previousauth
               FROM {user} u
          LEFT JOIN {" . self::TABLE . "} l ON l.userid = u.id
              WHERE u.auth = :auth AND u.deleted = 0
           ORDER BY u.id",
            ['auth' => config::AUTH]
        );
        foreach ($rows as $row) {
            // One by one, so user_updated fires for each account.
            user_update_user((object) [
                'id' => (int) $row->id,
                'auth' => self::restored_auth($row->previousauth),
            ], false, true);
        }
        // Deleted accounts can't sign in anyway; just stop them being hub accounts.
        $DB->set_field_select('user', 'auth', 'manual', 'auth = :auth AND deleted = 1', ['auth' => config::AUTH]);

        return count($rows);
    }

    /**
     * The login method to give back to an account.
     *
     * @param string|null $previousauth From its link.
     * @return string The previous method if it's still enabled, else 'manual'.
     */
    public static function restored_auth(?string $previousauth): string {
        $previousauth = (string) $previousauth;
        if (
            $previousauth !== ''
            && $previousauth !== config::AUTH
            && !in_array($previousauth, self::BLOCKED_AUTH, true)
            && exists_auth_plugin($previousauth)
            && is_enabled_auth($previousauth)
        ) {
            return $previousauth;
        }
        return 'manual';
    }

    /**
     * Before sign-in switches to this issuer: if it's a different issuer
     * from the one configured (or any link belongs to another issuer),
     * give hub accounts their own login back and forget every link.
     *
     * @param string $issuer The issuer about to be configured.
     * @return bool Whether links were cleared.
     */
    public static function reset_for_issuer(string $issuer): bool {
        global $DB;

        $current = config::issuer();
        $otherlinks = $DB->record_exists_select(self::TABLE, 'issuerhash <> :hash',
            ['hash' => self::issuer_hash($issuer)]);
        if (($current === '' || $current === $issuer) && !$otherlinks) {
            return false;
        }
        self::release_hub_accounts();
        $DB->delete_records(self::TABLE);
        return true;
    }

    /**
     * One page of the local accounts, for the portal's linking preview.
     *
     * Deleted accounts, the guest and remote (MNet) accounts are left out.
     *
     * @param int $page 0-based.
     * @param int $perpage Capped at MAX_PER_PAGE.
     * @return array ['accounts' => [...], 'total' => int]
     */
    public static function list_for_linking(int $page, int $perpage): array {
        global $CFG, $DB;

        $perpage = max(1, min(self::MAX_PER_PAGE, $perpage));
        $page = max(0, $page);
        $where = 'u.deleted = 0 AND u.id <> :guestid AND u.mnethostid = :mnethostid';
        $params = [
            'guestid' => (int) ($CFG->siteguest ?? 0),
            'mnethostid' => $CFG->mnet_localhost_id,
        ];

        $total = $DB->count_records_sql("SELECT COUNT(1) FROM {user} u WHERE $where", $params);
        $rows = $DB->get_records_sql(
            "SELECT u.id, u.email, u.auth, u.suspended, l.sub
               FROM {user} u
          LEFT JOIN {" . self::TABLE . "} l ON l.userid = u.id
              WHERE $where
           ORDER BY u.id",
            $params,
            $page * $perpage,
            $perpage
        );

        // Site-level role holders in one query; capabilities per account.
        $systemroles = array_flip(array_map('intval', $DB->get_fieldset_select('role_assignments', 'DISTINCT userid',
            'contextid = :contextid', ['contextid' => \context_system::instance()->id])));
        $accounts = [];
        foreach ($rows as $row) {
            $userid = (int) $row->id;
            $siteadmin = is_siteadmin($userid);
            $accounts[] = [
                'userid' => $userid,
                'email' => (string) $row->email,
                'auth' => (string) $row->auth,
                'suspended' => !empty($row->suspended),
                'siteadmin' => $siteadmin,
                'privileged' => $siteadmin || isset($systemroles[$userid]) || self::has_privileged_capability($userid),
                'sub' => (string) ($row->sub ?? ''),
            ];
        }
        return ['accounts' => $accounts, 'total' => (int) $total];
    }

    /**
     * How many accounts are linked.
     *
     * @return int
     */
    public static function linked_count(): int {
        global $DB;
        return $DB->count_records(self::TABLE);
    }

    /**
     * Forget a deleted account's link.
     *
     * @param int $userid
     */
    public static function unlink_user(int $userid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['userid' => $userid]);
    }
}
