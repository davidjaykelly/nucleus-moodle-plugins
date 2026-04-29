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
 * External function: local_nucleusspoke_revoke_user.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleusspoke\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

/**
 * Phase B1 Step 5 — hub-initiated GDPR cascade.
 *
 * CP routes a `mode_b_user_revoked.v1` envelope (direction='hub')
 * to this function. We unenrol the local user from every Mode B
 * placeholder course (rows where `local_nucleusspoke_courses.mode =
 * 'identity'`) so the user no longer sees the federated content
 * after the hub admin's revocation.
 *
 * We deliberately do NOT delete the spoke user — the spoke is the
 * authoritative identity store; deleting them here would erase
 * non-federation history (local courses, Mode A pulls, etc.) which
 * the hub admin had no jurisdiction over.
 *
 * Idempotent: calling for a user with no Mode B enrolments returns
 * `unenrolled: 0`. Calling for an already-revoked user is the same.
 */
class revoke_user extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'spokeuserid' => new external_value(PARAM_INT, 'Spoke user id whose hub shadow has been revoked.'),
        ]);
    }

    /**
     * @return array{status: string, unenrolled: int}
     */
    public static function execute(int $spokeuserid): array {
        global $DB, $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'spokeuserid' => $spokeuserid,
        ]);

        require_once($CFG->libdir . '/enrollib.php');

        if (!$DB->record_exists('user', ['id' => $params['spokeuserid'], 'deleted' => 0])) {
            // User already gone (the more common case is spoke-initiated
            // revoke, but a hub-initiated revoke for a user that was
            // also deleted on the spoke is fine — no-op).
            return ['status' => 'user_missing', 'unenrolled' => 0];
        }

        $modebcourses = $DB->get_records('local_nucleusspoke_courses', ['mode' => 'identity']);
        if (!$modebcourses) {
            return ['status' => 'no_mode_b_courses', 'unenrolled' => 0];
        }

        $plugin = enrol_get_plugin('manual');
        if (!$plugin) {
            throw new \moodle_exception('enrolnotavailable', 'local_nucleusspoke');
        }

        $count = 0;
        foreach ($modebcourses as $fed) {
            $instance = $DB->get_record('enrol', [
                'courseid' => $fed->localcourseid,
                'enrol'    => 'manual',
            ]);
            if (!$instance) {
                continue;
            }
            $ue = $DB->get_record('user_enrolments', [
                'enrolid' => $instance->id,
                'userid'  => $params['spokeuserid'],
            ]);
            if (!$ue) {
                continue;
            }
            $plugin->unenrol_user($instance, $params['spokeuserid']);
            $count++;
        }

        return [
            'status'     => 'revoked',
            'unenrolled' => $count,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'     => new external_value(PARAM_ALPHAEXT, 'revoked | user_missing | no_mode_b_courses'),
            'unenrolled' => new external_value(PARAM_INT, 'Count of Mode B placeholder courses the user was unenrolled from.'),
        ]);
    }
}
