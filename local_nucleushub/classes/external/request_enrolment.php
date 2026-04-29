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
 * External function: local_nucleushub_request_enrolment.
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
 * Mode B: enrol a projected user in a hub-hosted course via the manual
 * enrolment plugin.
 *
 * Guardrail: only users present in `local_nucleushub_projusers` can be
 * enrolled through this endpoint. Stops a misbehaving or compromised
 * spoke token from enrolling arbitrary hub users.
 */
class request_enrolment extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'hubuserid' => new external_value(PARAM_INT, 'Hub-side shadow user id (from project_user)'),
            'courseid'  => new external_value(PARAM_INT, 'Hub course id to enrol in'),
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

        $projuser = $DB->get_record('local_nucleushub_projusers',
            ['hubuserid' => $params['hubuserid']]);
        if (!$projuser) {
            throw new \moodle_exception('notaprojectedhubuser', 'local_nucleushub');
        }

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $instance = self::ensure_manual_enrol_instance($course);

        $existing = $DB->get_record('user_enrolments',
            ['enrolid' => $instance->id, 'userid' => $params['hubuserid']]);
        if ($existing) {
            return [
                'enrolmentid' => (int)$existing->id,
                'status'      => 'already_enrolled',
            ];
        }

        $plugin = enrol_get_plugin('manual');
        if (!$plugin) {
            throw new \moodle_exception('enrolnotavailable', 'local_nucleushub');
        }
        $plugin->enrol_user($instance, $params['hubuserid']);

        $row = $DB->get_record('user_enrolments',
            ['enrolid' => $instance->id, 'userid' => $params['hubuserid']], '*', MUST_EXIST);

        // Phase B1 Step 3: emit `mode_b_enrolment_requested.v1` so CP
        // can audit. Look up the originating spoke via the projuser
        // row's spokeid — the request is keyed on hubuserid which
        // already encodes which spoke this user belongs to. Drops
        // silently if the spoke row pre-dates the cpspokeid
        // backfill (CP-side audit captures legacy drops).
        $spoke = $DB->get_record('local_nucleushub_spokes',
            ['id' => $projuser->spokeid], '*', IGNORE_MISSING);
        if ($spoke && !empty($spoke->cpspokeid)) {
            try {
                publisher::publish(
                    'mode_b_enrolment_requested.v1',
                    'hub',
                    'cp',
                    [
                        'cp_spoke_id'    => (string)$spoke->cpspokeid,
                        'spoke_id'       => (int)$spoke->id,
                        'spoke_name'     => (string)$spoke->name,
                        'hub_user_id'    => (int)$params['hubuserid'],
                        'spoke_user_id'  => (int)$projuser->spokeuserid,
                        'hub_course_id'  => (int)$params['courseid'],
                        'enrolment_id'   => (int)$row->id,
                        'enrolled_at'    => time(),
                    ]
                );
            } catch (\Throwable $e) {
                debugging('Nucleus: mode_b_enrolment_requested publish failed: ' . $e->getMessage(),
                    DEBUG_DEVELOPER);
            }
        }

        return [
            'enrolmentid' => (int)$row->id,
            'status'      => 'enrolled',
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'enrolmentid' => new external_value(PARAM_INT, 'user_enrolments.id'),
            'status'      => new external_value(PARAM_ALPHANUMEXT, 'enrolled | already_enrolled | failed'),
        ]);
    }

    /**
     * Ensure the course has a manual enrolment instance to attach users to.
     * Courses created via create_course() get one by default, but the
     * helper keeps us safe if a course was created without.
     *
     * @param \stdClass $course
     * @return \stdClass enrol row
     */
    private static function ensure_manual_enrol_instance(\stdClass $course): \stdClass {
        global $DB;
        $instance = $DB->get_record('enrol',
            ['courseid' => $course->id, 'enrol' => 'manual']);
        if ($instance) {
            return $instance;
        }
        $plugin = enrol_get_plugin('manual');
        if (!$plugin) {
            throw new \moodle_exception('enrolnotavailable', 'local_nucleushub');
        }
        $plugin->add_default_instance($course);
        return $DB->get_record('enrol',
            ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
    }
}
