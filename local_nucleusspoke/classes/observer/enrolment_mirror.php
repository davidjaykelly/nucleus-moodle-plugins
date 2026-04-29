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
 * Observer that mirrors local enrolments into hub enrolments (Mode B).
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleusspoke\observer;

use local_nucleusspoke\client\hub_client;

defined('MOODLE_INTERNAL') || die();

/**
 * When a local user is enrolled in a course that was "Enable federation"-ed
 * (Mode B placeholder), project the user to the hub and enrol them in the
 * corresponding hub course. Phase 0 eventual consistency: if the hub call
 * fails, we leave the local enrolment in place and log — compensating
 * transactions are a Phase 1 concern.
 *
 * Skipped entirely for Mode A (content federation) courses, and for
 * enrolments on courses we don't own.
 */
class enrolment_mirror {

    /**
     * @param \core\event\user_enrolment_created $event
     * @return void
     */
    public static function handle(\core\event\user_enrolment_created $event): void {
        global $DB;

        $courseid = (int)$event->courseid;
        $userid = (int)$event->relateduserid;
        if ($courseid <= 0 || $userid <= 0) {
            return;
        }

        $fed = $DB->get_record('local_nucleusspoke_courses', [
            'localcourseid' => $courseid,
            'mode'          => 'identity',
        ]);
        if (!$fed) {
            // Not a federation-hosted course — normal local enrolment.
            return;
        }

        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user || $user->deleted) {
            return;
        }

        try {
            $client = hub_client::default();
            $projection = $client->project_user(
                $userid,
                $user->username,
                (string)$user->email,
                (string)$user->firstname,
                (string)$user->lastname
            );
            $hubuserid = (int)($projection['hubuserid'] ?? 0);
            if ($hubuserid <= 0) {
                throw new \moodle_exception('huberror', 'local_nucleuscommon', '', null,
                    'project_user returned no hubuserid: ' . json_encode($projection));
            }
            $client->request_enrolment($hubuserid, (int)$fed->hubcourseid);
        } catch (\Throwable $e) {
            // Phase 0 policy: don't roll back the local enrolment on hub
            // failure — the user has a local seat already, and compensating
            // behaviour is a Phase 1 concern. Log so ops can see the gap.
            debugging('Nucleus: enrolment mirror failed for user=' . $userid .
                ' local_course=' . $courseid . ' hub_course=' . $fed->hubcourseid .
                ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
