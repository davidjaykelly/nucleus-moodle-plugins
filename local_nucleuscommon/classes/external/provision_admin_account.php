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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * External function: local_nucleuscommon_provision_admin_account.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleuscommon\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

/**
 * ADR-016 Option A — provision a customer-facing site-admin
 * account on this Moodle (hub or spoke).
 *
 * Called by CP at two trigger points:
 *   - hub_provision worker success → creates the federation
 *     requester's account on the new hub Moodle.
 *   - users.service.grantSpoke (delegation) → creates the
 *     delegated spoke_admin's account on the spoke Moodle.
 *
 * Idempotent: re-calls for the same email find the existing
 * user, ensure they're a site admin, generate a fresh
 * password-reset token, return it. CP emails the URL.
 *
 * The user is created with `auth=manual` so they can set their
 * own password via the standard Moodle reset flow. We don't
 * promise the email is verified at the Moodle level — CP has
 * already verified it before this call (signup → verify-email
 * for hubs; invite-accept for spokes).
 *
 * Site-admin assignment uses Moodle's built-in `siteadmins`
 * config (a comma-separated list of user ids in `mdl_config`).
 * That's the same mechanism `set_main_admin` and the admin
 * settings UI use; we add to the list rather than replace.
 */
class provision_admin_account extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'email'       => new external_value(PARAM_EMAIL, 'Email of the admin to provision.'),
            'displayName' => new external_value(PARAM_TEXT, 'Display name for first-name field.', VALUE_DEFAULT, ''),
            'firstName'   => new external_value(PARAM_TEXT, 'Optional explicit first name.', VALUE_DEFAULT, ''),
            'lastName'    => new external_value(PARAM_TEXT, 'Optional explicit last name.', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * @return array{userId: int, created: bool, resetUrl: string, expiresAt: int}
     */
    public static function execute(
        string $email,
        string $displayName = '',
        string $firstName = '',
        string $lastName = ''
    ): array {
        global $CFG, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'email'       => $email,
            'displayName' => $displayName,
            'firstName'   => $firstName,
            'lastName'    => $lastName,
        ]);

        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->dirroot . '/login/lib.php');

        $email = strtolower(trim($params['email']));

        // Derive first/last name. If not supplied, split displayName
        // on the last space; if displayName is empty, fall back to
        // the email local part.
        [$first, $last] = self::resolve_names(
            $params['firstName'],
            $params['lastName'],
            $params['displayName'],
            $email,
        );

        // Find by email. Moodle's email column isn't unique by
        // default (`$CFG->allowaccountssameemail` may relax it
        // further) — pick the most recent active record so we
        // don't try to provision against a soft-deleted user.
        $existing = $DB->get_records('user', [
            'email' => $email,
            'deleted' => 0,
        ], 'timecreated DESC', '*', 0, 1);

        $created = false;
        if ($existing) {
            $user = reset($existing);
        } else {
            // Username has to be unique site-wide. Use the email
            // verbatim — Moodle accepts emails as usernames when
            // `$CFG->extendedusernamechars` is set (default on
            // 4.x+). Fall back to a sanitised slug if rejected.
            $username = self::pick_username($email);

            $newuser = (object) [
                'auth'       => 'manual',
                'username'   => $username,
                'email'      => $email,
                'firstname'  => $first,
                'lastname'   => $last,
                'password'   => '',
                'confirmed'  => 1,
                'mnethostid' => $CFG->mnet_localhost_id,
                'lang'       => $CFG->lang ?? 'en',
                'timezone'   => '99',
            ];
            $userid = user_create_user($newuser, false, false);
            $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
            $created = true;
        }

        // Promote to site admin. `siteadmins` is a comma-separated
        // list in mdl_config — the same value the admin UI edits.
        // Idempotent set-add.
        self::ensure_site_admin((int) $user->id);

        // Generate a fresh single-use reset token. We always issue
        // a new one rather than reusing — the caller is the trust
        // root (CP, via cpWsToken), so it controls when a fresh
        // token is needed.
        $reset = core_login_generate_password_reset($user);
        // Tokens have a fixed lifetime per Moodle's
        // pwresettime config (default 30 minutes). Surface that
        // back to CP so the email copy can be honest about it.
        $ttlSeconds = (int) ($CFG->pwresettime ?? 1800);
        $expiresAt = (int) $reset->timerequested + $ttlSeconds;

        $resetUrl = rtrim($CFG->wwwroot, '/') . '/login/forgot_password.php?token=' . urlencode($reset->token);

        return [
            'userId'    => (int) $user->id,
            'created'   => $created,
            'resetUrl'  => $resetUrl,
            'expiresAt' => $expiresAt,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'userId'    => new external_value(PARAM_INT, 'mdl_user.id of the provisioned admin.'),
            'created'   => new external_value(PARAM_BOOL, 'True if this call created the user; false if reused existing.'),
            'resetUrl'  => new external_value(PARAM_RAW, 'One-time password-reset URL — pass to the customer via email.'),
            'expiresAt' => new external_value(PARAM_INT, 'Unix time when the reset URL expires.'),
        ]);
    }

    /**
     * Site admin promotion — adds userid to `$CFG->siteadmins`
     * (the comma-separated list in mdl_config) without disturbing
     * the existing admins. Same mechanism the admin UI uses.
     */
    private static function ensure_site_admin(int $userid): void {
        $existing = (string) get_config('core', 'siteadmins');
        $ids = array_filter(array_map('intval', explode(',', $existing)));
        if (in_array($userid, $ids, true)) {
            return;
        }
        $ids[] = $userid;
        set_config('siteadmins', implode(',', $ids));
    }

    /**
     * Pick names from whichever field is populated; never produce
     * empty first/last — Moodle requires both.
     *
     * @return array{0: string, 1: string} [first, last]
     */
    private static function resolve_names(
        string $first,
        string $last,
        string $display,
        string $email
    ): array {
        $f = trim($first);
        $l = trim($last);
        if ($f !== '' && $l !== '') {
            return [$f, $l];
        }
        $d = trim($display);
        if ($d !== '') {
            $space = strrpos($d, ' ');
            if ($space !== false) {
                $f = $f !== '' ? $f : substr($d, 0, $space);
                $l = $l !== '' ? $l : substr($d, $space + 1);
            } else {
                $f = $f !== '' ? $f : $d;
                $l = $l !== '' ? $l : ' ';
            }
        }
        if ($f === '') {
            // Last fallback: email local part.
            $local = explode('@', $email)[0] ?? 'admin';
            $f = $local;
        }
        if ($l === '') {
            $l = ' ';
        }
        return [$f, $l];
    }

    /**
     * Pick a unique username. Try the email verbatim first; if
     * Moodle's username uniqueness rejects it (unlikely with
     * `extendedusernamechars` on by default), fall back to a
     * sanitised slug with a numeric suffix until unique.
     */
    private static function pick_username(string $email): string {
        global $DB;
        if (!$DB->record_exists('user', ['username' => $email])) {
            return $email;
        }
        // Sanitise: lowercase alphanumerics + dot + hyphen +
        // underscore. Anything else (including @) becomes _.
        $slug = strtolower(preg_replace('/[^a-z0-9._-]+/i', '_', $email));
        $base = $slug;
        $suffix = 0;
        while ($DB->record_exists('user', ['username' => $slug])) {
            $suffix++;
            $slug = $base . '_' . $suffix;
        }
        return $slug;
    }
}
