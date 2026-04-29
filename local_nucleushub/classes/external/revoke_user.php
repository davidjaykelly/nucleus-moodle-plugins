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
 * External function: local_nucleushub_revoke_user.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleushub\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_nucleuscommon\events\publisher;

defined('MOODLE_INTERNAL') || die();

/**
 * Phase B1 Step 5 — spoke-initiated GDPR cascade.
 *
 * The spoke calls this when one of its users is deleted. We:
 *
 *   1. Look up the projusers row keyed (caller-spokeid, spokeuserid).
 *   2. If found, call Moodle's `delete_user()` on the shadow user
 *      (anonymises hub-side records to retain referential integrity
 *      on completions etc., per Moodle's standard GDPR behaviour).
 *   3. Delete the projusers row.
 *   4. Publish `mode_b_user_revoked.v1` for CP audit (direction:
 *      'spoke' so the CP-side handler doesn't fan back out).
 *
 * Idempotent: a second call for the same (spokeid, spokeuserid) is
 * a no-op return `{removed: false}`.
 */
class revoke_user extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'spokeuserid' => new external_value(PARAM_INT, 'User id on the calling spoke.'),
        ]);
    }

    /**
     * @return array{removed: bool, hubuserid: int}
     */
    public static function execute(int $spokeuserid): array {
        global $CFG, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'spokeuserid' => $spokeuserid,
        ]);

        require_once($CFG->dirroot . '/user/lib.php');

        $spoke = self::resolve_caller_spoke();

        $row = $DB->get_record('local_nucleushub_projusers', [
            'spokeid'     => $spoke->id,
            'spokeuserid' => $params['spokeuserid'],
        ]);
        if (!$row) {
            return ['removed' => false, 'hubuserid' => 0];
        }

        $hubuserid = (int)$row->hubuserid;

        // Order matters: delete the projusers row BEFORE
        // delete_user() so that the user_deleted event fires with
        // no projusers match, preventing `revocation_recorder` from
        // publishing a duplicate direction='hub' envelope. We
        // (revoke_user) own this revocation and emit direction='spoke'
        // ourselves below.
        $DB->delete_records('local_nucleushub_projusers', ['id' => $row->id]);

        $hubuser = $DB->get_record('user', ['id' => $hubuserid]);
        if ($hubuser && empty($hubuser->deleted)) {
            // delete_user() is the right entrypoint — handles
            // anonymisation, the user_deleted event chain, and
            // associated table cleanup.
            delete_user($hubuser);
        }

        if (!empty($spoke->cpspokeid)) {
            try {
                publisher::publish(
                    'mode_b_user_revoked.v1',
                    'hub',
                    'cp',
                    [
                        'cp_spoke_id'    => (string)$spoke->cpspokeid,
                        'spoke_id'       => (int)$spoke->id,
                        'spoke_name'     => (string)$spoke->name,
                        'spoke_user_id'  => (int)$params['spokeuserid'],
                        'hub_user_id'    => $hubuserid,
                        'spoke_username' => (string)($row->spokeusername ?? ''),
                        'spoke_email'    => (string)($row->spokeemail ?? ''),
                        'direction'      => 'spoke',
                        'revoked_at'     => time(),
                    ]
                );
            } catch (\Throwable $e) {
                debugging('Nucleus: mode_b_user_revoked publish failed: ' . $e->getMessage(),
                    DEBUG_DEVELOPER);
            }
        }

        return ['removed' => true, 'hubuserid' => $hubuserid];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'removed'   => new external_value(PARAM_BOOL, 'true if a projusers row was found and removed.'),
            'hubuserid' => new external_value(PARAM_INT, 'The shadow mdl_user.id that was deleted; 0 on no-op.'),
        ]);
    }

    /**
     * Match `project_user::resolve_caller_spoke()` semantics. Phase 1+
     * will swap to bearer-token-keyed lookup.
     */
    private static function resolve_caller_spoke(): \stdClass {
        global $DB;
        $spoke = $DB->get_record('local_nucleushub_spokes',
            ['status' => 'active'], '*', IGNORE_MULTIPLE);
        if (!$spoke) {
            throw new \moodle_exception('nospokeregistered', 'local_nucleushub');
        }
        return $spoke;
    }
}
