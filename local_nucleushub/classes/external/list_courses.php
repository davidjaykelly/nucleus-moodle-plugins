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
 * External function: local_nucleushub_list_courses.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleushub\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

/**
 * List courses that the hub is offering to this federation.
 *
 * Phase 0 simplification: returns every course except the front page.
 * Phase 1 will filter by a `nucleus_federation_available` custom course
 * field (or an explicit `course_catalog` table populated by admins),
 * per tech scope §5.2.
 */
class list_courses extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * @return array[]
     */
    public static function execute(): array {
        global $DB;

        self::validate_parameters(self::execute_parameters(), []);

        // Phase 0: Nucleus federation token holders are trusted across
        // course contexts. Phase 1 will restrict to courses where the
        // federation flag is set.
        $sql = "
            SELECT c.id, c.shortname, c.fullname, c.summary, cc.name AS category
              FROM {course} c
              JOIN {course_categories} cc ON cc.id = c.category
             WHERE c.id <> :siteid
          ORDER BY c.id
        ";
        $rows = $DB->get_records_sql($sql, ['siteid' => SITEID]);

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id'        => (int)$row->id,
                'shortname' => (string)$row->shortname,
                'fullname'  => (string)$row->fullname,
                'summary'   => (string)($row->summary ?? ''),
                'category'  => (string)$row->category,
            ];
        }
        return $result;
    }

    /**
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'id'        => new external_value(PARAM_INT, 'Course id on the hub'),
            'shortname' => new external_value(PARAM_TEXT, 'Course short name'),
            'fullname'  => new external_value(PARAM_TEXT, 'Course full name'),
            'summary'   => new external_value(PARAM_RAW, 'Course summary (HTML)'),
            'category'  => new external_value(PARAM_TEXT, 'Category name'),
        ]));
    }
}
