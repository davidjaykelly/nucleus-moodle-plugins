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

namespace local_nucleushub\output;

use local_nucleushub\local\statusbar;
use moodle_url;
use renderable;
use renderer_base;
use templatable;

/**
 * The Course families page: every course this hub shares.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class families_page implements renderable, templatable {
    /**
     * Template context.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        global $DB;

        // Families with their hub course and latest version. Outer joins:
        // a family whose hub course was deleted, or that was never
        // published, still shows.
        $rows = $DB->get_records_sql(
            "SELECT f.id AS familyid, f.slug, f.timecreated AS familytimecreated,
                    d.hubcourseid, d.pendingchangecount,
                    c.id AS courseid, c.fullname AS coursefullname,
                    v.versionnumber, v.severity, v.timepublished
               FROM {local_nucleuscommon_family} f
          LEFT JOIN {local_nucleushub_draft} d ON d.familyid = f.id
          LEFT JOIN {course} c ON c.id = d.hubcourseid
          LEFT JOIN {local_nucleuscommon_version} v ON v.id = d.lastpublishversionid
           ORDER BY f.slug ASC"
        );
        $versioncounts = $DB->get_records_sql_menu(
            "SELECT familyid, COUNT(1) FROM {local_nucleuscommon_version} GROUP BY familyid"
        );
        $spokes = $DB->count_records('local_nucleushub_spokes', ['status' => 'active']);

        $families = [];
        foreach ($rows as $row) {
            $pending = (int) ($row->pendingchangecount ?? 0);
            $hascourse = !empty($row->courseid);
            if ($pending > 0) {
                $changes = get_string('pendingchanges_short', 'local_nucleushub', $pending);
            } else if ($row->versionnumber) {
                $changes = get_string('statusbar_hub_clean', 'local_nucleushub');
            } else {
                $changes = get_string('familyneverpublished_short', 'local_nucleushub');
            }
            $families[] = [
                'slug' => $row->slug,
                'hascourse' => $hascourse,
                'coursename' => $hascourse ? format_string($row->coursefullname) : '',
                'courseurl' => $hascourse ? (new moodle_url('/course/view.php', ['id' => $row->courseid]))->out(false) : '',
                'latest' => $row->versionnumber ? get_string('families_latest', 'local_nucleushub', (object) [
                    'version' => $row->versionnumber,
                    'severity' => statusbar::severity_label((string) $row->severity),
                ]) : '',
                'latestwhen' => $row->versionnumber
                    ? get_string('families_publishedago', 'local_nucleushub', format_time(time() - (int) $row->timepublished))
                    : '',
                'changes' => $changes,
                'haschanges' => $pending > 0,
                'versions' => (int) ($versioncounts[$row->familyid] ?? 0),
                'created' => get_string(
                    'families_created',
                    'local_nucleushub',
                    format_time(time() - (int) $row->familytimecreated)
                ),
                'publishurl' => $hascourse
                    ? (new moodle_url('/local/nucleushub/publish.php', ['id' => $row->courseid]))->out(false)
                    : '',
            ];
        }

        return [
            'families' => $families,
            'hasfamilies' => !empty($families),
            'reach' => $spokes > 0
                ? get_string('families_reach', 'local_nucleushub', $spokes)
                : get_string('families_reach_none', 'local_nucleushub'),
        ];
    }
}
