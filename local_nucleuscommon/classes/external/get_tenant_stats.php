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
 * External function: local_nucleuscommon_get_tenant_stats.
 *
 * Cheap aggregate counts the control plane shows in tenant
 * snapshot panels. Single round-trip; ~4 SQL aggregates.
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
 * Tenant-wide aggregate stats. Excludes the guest user, the
 * deleted-user pseudo-records, and the front-page (`SITEID`)
 * pseudo-course so the numbers match what an operator would
 * count by hand.
 */
class get_tenant_stats extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $DB;
        self::validate_parameters(self::execute_parameters(), []);

        // Active human users: not deleted, not the guest account, confirmed.
        $users = (int)$DB->count_records_select(
            'user',
            'deleted = 0 AND username <> :guest AND confirmed = 1',
            ['guest' => 'guest'],
        );

        // Real courses (front page is courseid = SITEID = 1).
        $courses = (int)$DB->count_records_select(
            'course',
            'id <> :siteid',
            ['siteid' => SITEID],
        );

        // Enrolments via the user_enrolments → enrol → course chain.
        // Counts (user, course) pairs once even if a user has
        // multiple enrolment instances in the same course (manual +
        // self, etc).
        $enrolments = (int)$DB->count_records_sql(
            "SELECT COUNT(*) FROM (
                SELECT DISTINCT ue.userid, e.courseid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {course} c ON c.id = e.courseid
                 WHERE c.id <> :siteid
             ) sub",
            ['siteid' => SITEID],
        );

        // Active users in the last 24h via lastaccess. Excludes
        // accounts that have never logged in (lastaccess = 0).
        $cutoff = time() - 86400;
        $active24h = (int)$DB->count_records_select(
            'user',
            'deleted = 0 AND username <> :guest AND lastaccess > :cutoff',
            ['guest' => 'guest', 'cutoff' => $cutoff],
        );

        return [
            'users'           => $users,
            'courses'         => $courses,
            'enrolments'      => $enrolments,
            'activeUsers24h'  => $active24h,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'users'          => new external_value(PARAM_INT, 'Active confirmed human users (excludes guest + deleted).'),
            'courses'        => new external_value(PARAM_INT, 'Real courses (excludes the front page).'),
            'enrolments'     => new external_value(PARAM_INT, 'Distinct (user, course) enrolment pairs.'),
            'activeUsers24h' => new external_value(PARAM_INT, 'Users with `lastaccess` in the past 24 hours.'),
        ]);
    }
}
