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
 * Mode B action: create a local placeholder for a hub-hosted course.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleusspoke\action;

use local_nucleusspoke\client\hub_client;

defined('MOODLE_INTERNAL') || die();

/**
 * Creates a local "wrapper" course that represents a hub-hosted course.
 * The local course has no content; it exists so that Moodle's enrolment
 * UI can be used, with the observer redirecting the real enrolment to
 * the hub.
 *
 * Phase 0 simplification: local placeholder course is empty. Phase 1
 * will either (a) add a single "Access on hub" module that deep-links
 * with SSO, or (b) proxy the entire course view through the spoke for
 * proper branding.
 */
class enable_federation {

    /**
     * @param int $hubcourseid
     * @return array{status: string, localcourseid: int, message: string}
     */
    public static function run(int $hubcourseid): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');

        $existing = $DB->get_record('local_nucleusspoke_courses',
            ['hubcourseid' => $hubcourseid, 'mode' => 'identity']);
        if ($existing && $DB->record_exists('course', ['id' => $existing->localcourseid])) {
            return [
                'status'        => 'already_enabled',
                'localcourseid' => (int)$existing->localcourseid,
                'message'       => 'Federation already enabled for this hub course.',
            ];
        }

        $client = hub_client::default();
        $hubcourses = $client->list_courses();
        $hubcourse = null;
        foreach ($hubcourses as $c) {
            if ((int)$c['id'] === $hubcourseid) { $hubcourse = $c; break; }
        }
        if (!$hubcourse) {
            throw new \moodle_exception('huberror', 'local_nucleuscommon', '', null,
                "Hub course {$hubcourseid} not offered for federation.");
        }

        $shortname = self::uniquify_shortname('fed_' . ($hubcourse['shortname'] ?? 'course'));
        $data = (object) [
            'fullname'         => 'Federated: ' . ($hubcourse['fullname'] ?? "Course {$hubcourseid}"),
            'shortname'        => $shortname,
            'category'         => 1,
            'summary'          => ($hubcourse['summary'] ?? '') . "\n\n<em>This course is hosted on the federation hub.</em>",
            'summaryformat'    => FORMAT_HTML,
            // Required so `completion_completion::mark_complete()` actually
            // marks the course complete when completion envelopes arrive.
            'enablecompletion' => 1,
        ];
        $newcourse = create_course($data);

        $now = time();
        $DB->insert_record('local_nucleusspoke_courses', (object) [
            'localcourseid' => (int)$newcourse->id,
            'hubcourseid'   => $hubcourseid,
            'mode'          => 'identity',
            'timesynced'    => $now,
            'timecreated'   => $now,
        ]);

        return [
            'status'        => 'enabled',
            'localcourseid' => (int)$newcourse->id,
            'message'       => "Placeholder created at local course id {$newcourse->id}.",
        ];
    }

    /**
     * @param string $base
     * @return string
     */
    private static function uniquify_shortname(string $base): string {
        global $DB;
        $candidate = $base;
        $i = 2;
        while ($DB->record_exists('course', ['shortname' => $candidate])) {
            $candidate = $base . '_' . $i;
            $i++;
        }
        return $candidate;
    }
}
