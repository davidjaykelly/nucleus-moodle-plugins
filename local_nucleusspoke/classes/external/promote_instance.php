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
 * External function: local_nucleusspoke_promote_instance
 * (ADR-014 Phase 2). Flips a staging instance to active and makes
 * the underlying course visible to students.
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
use local_nucleusspoke\version\promoter;

defined('MOODLE_INTERNAL') || die();

class promote_instance extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'instanceid' => new external_value(PARAM_INT, 'local_nucleusspoke_instance.id'),
        ]);
    }

    public static function execute(int $instanceid): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'instanceid' => $instanceid,
        ]);
        $row = promoter::promote_instance(
            (int) $params['instanceid'],
            (int) $USER->id
        );
        return [
            'instanceid' => (int) $row->id,
            'state' => (string) $row->state,
            'localcourseid' => (int) $row->localcourseid,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'instanceid' => new external_value(PARAM_INT, 'Instance id.'),
            'state' => new external_value(PARAM_ALPHAEXT, 'New state (active).'),
            'localcourseid' => new external_value(PARAM_INT, 'mdl_course.id.'),
        ]);
    }
}
