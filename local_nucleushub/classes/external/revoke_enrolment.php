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
 * External function: local_nucleushub_revoke_enrolment.
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

defined('MOODLE_INTERNAL') || die();

/**
 * Mode B: remove a projected user's enrolment from a hub-hosted course.
 *
 * No-op if the user wasn't enrolled via the manual plugin. Same projusers
 * guardrail as request_enrolment.
 */
class revoke_enrolment extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'hubuserid' => new external_value(PARAM_INT, 'Hub-side shadow user id'),
            'courseid'  => new external_value(PARAM_INT, 'Hub course id to unenrol from'),
        ]);
    }

    /**
     * @param int $hubuserid
     * @param int $courseid
     * @return array
     */
    public static function execute(int $hubuserid, int $courseid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'hubuserid' => $hubuserid,
            'courseid'  => $courseid,
        ]);

        if (!$DB->record_exists('local_nucleushub_projusers', ['hubuserid' => $params['hubuserid']])) {
            throw new \moodle_exception('notaprojectedhubuser', 'local_nucleushub');
        }

        $instance = $DB->get_record('enrol',
            ['courseid' => $params['courseid'], 'enrol' => 'manual']);
        if (!$instance) {
            return ['status' => 'not_enrolled'];
        }
        $enrolment = $DB->get_record('user_enrolments',
            ['enrolid' => $instance->id, 'userid' => $params['hubuserid']]);
        if (!$enrolment) {
            return ['status' => 'not_enrolled'];
        }

        $plugin = enrol_get_plugin('manual');
        if (!$plugin) {
            throw new \moodle_exception('enrolnotavailable', 'local_nucleushub');
        }
        $plugin->unenrol_user($instance, $params['hubuserid']);

        return ['status' => 'revoked'];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHANUMEXT, 'revoked | not_enrolled | failed'),
        ]);
    }
}
