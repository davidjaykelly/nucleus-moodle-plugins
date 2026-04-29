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
 * External function: local_nucleusspoke_pull_course.
 *
 * Thin WS wrapper over the existing `copy_locally::run()` action.
 * The control plane invokes this when an operator clicks
 * "Pull to spoke" in the content-sync UI.
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
use local_nucleusspoke\action\copy_locally;

defined('MOODLE_INTERNAL') || die();

/**
 * Pull a hub course's MBZ and restore as a new local course.
 *
 * Synchronous on the spoke side: a real-sized course backup +
 * restore takes seconds to minutes; the control plane calls this
 * inside a BullMQ job so the wait is server-side and the operator
 * sees progress via SSE rather than waiting on a hanging request.
 *
 * Idempotent: re-pulling an already-copied course returns
 * `already_copied` instead of creating a duplicate. Re-sync of an
 * existing copy lands in Slice 2.
 */
class pull_course extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'hubcourseid' => new external_value(PARAM_INT, 'Hub course id to copy'),
        ]);
    }

    /**
     * @param int $hubcourseid
     * @return array
     */
    public static function execute(int $hubcourseid): array {
        $params = self::validate_parameters(self::execute_parameters(),
            ['hubcourseid' => $hubcourseid]);
        return copy_locally::run((int)$params['hubcourseid']);
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'        => new external_value(PARAM_ALPHANUMEXT, 'copied | already_copied | failed'),
            'localcourseid' => new external_value(PARAM_INT, 'Newly-created or existing local course id', VALUE_OPTIONAL),
            'message'       => new external_value(PARAM_TEXT, 'Human-readable detail', VALUE_OPTIONAL),
        ]);
    }
}
