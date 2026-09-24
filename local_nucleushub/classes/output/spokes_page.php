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

use renderable;
use renderer_base;
use templatable;

/**
 * The Spokes page: the sites connected to this hub.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class spokes_page implements renderable, templatable {
    /**
     * Template context.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        global $DB;

        // Removed spokes have left the federation: their hub account is gone.
        $records = $DB->get_records_select(
            'local_nucleushub_spokes',
            'status <> :removed',
            ['removed' => 'removed'],
            'timecreated DESC'
        );
        $now = time();
        $spokes = [];
        foreach ($records as $record) {
            $active = $record->status === 'active';
            if (empty($record->timelastheartbeat)) {
                $lastseen = get_string('spokes_heartbeat_never', 'local_nucleushub');
            } else {
                $lastseen = get_string(
                    'spokes_lastseen_ago',
                    'local_nucleushub',
                    format_time($now - (int) $record->timelastheartbeat)
                );
            }
            $spokes[] = [
                'name' => $record->name,
                'active' => $active,
                'status' => get_string($active ? 'spokes_status_active' : 'spokes_status_suspended', 'local_nucleushub'),
                'url' => $record->wwwroot,
                'joined' => userdate((int) $record->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
                'lastseen' => $lastseen,
                'stale' => empty($record->timelastheartbeat) || ($now - (int) $record->timelastheartbeat) > DAYSECS,
                'spokeid' => (string) ($record->cpspokeid ?? ''),
            ];
        }

        return [
            'spokes' => $spokes,
            'hasspokes' => !empty($spokes),
            'summary' => get_string('spokes_reach_summary', 'local_nucleushub', (object) [
                'spokes' => count($spokes),
                'families' => $DB->count_records('local_nucleuscommon_family'),
            ]),
        ];
    }
}
