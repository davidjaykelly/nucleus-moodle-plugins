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

namespace local_nucleusspoke\local;

/**
 * The editing lock on courses pulled from a locked version.
 *
 * The lock itself is a set of capability overrides on the course,
 * applied at pull time (puller::apply_edit_lock). This class only
 * answers "is this course locked?" for the notice teachers see.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lock {
    /**
     * Was this course pulled from a version the hub published with
     * editing locked?
     *
     * @param int $courseid mdl_course.id
     * @return bool
     */
    public static function is_locked(int $courseid): bool {
        global $DB;
        if ($courseid <= 0 || $courseid === (int) SITEID) {
            return false;
        }
        $sql = "SELECT v.lockedforspokeedit
                  FROM {local_nucleusspoke_instance} i
                  JOIN {local_nucleuscommon_version} v ON v.id = i.versionid
                 WHERE i.localcourseid = :courseid";
        $row = $DB->get_record_sql($sql, ['courseid' => $courseid], IGNORE_MISSING);
        return $row && (int) $row->lockedforspokeedit === 1;
    }
}
