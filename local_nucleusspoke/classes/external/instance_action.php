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
 * External function: local_nucleusspoke_instance_action
 * (ADR-014 Phase 2). Close / reopen actions on a pulled instance.
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
use local_nucleusspoke\version\lifecycle;

defined('MOODLE_INTERNAL') || die();

class instance_action extends external_api {

    public const ACTIONS = ['close', 'reopen'];

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'instanceid' => new external_value(PARAM_INT, 'local_nucleusspoke_instance.id'),
            'action' => new external_value(PARAM_ALPHA, 'close | reopen'),
        ]);
    }

    public static function execute(int $instanceid, string $action): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'instanceid' => $instanceid,
            'action' => $action,
        ]);
        if (!in_array($params['action'], self::ACTIONS, true)) {
            throw new \moodle_exception(
                'instance_action_unknown',
                'local_nucleusspoke',
                '',
                $params['action']
            );
        }

        $row = $params['action'] === 'close'
            ? lifecycle::close_to_enrolment((int) $params['instanceid'])
            : lifecycle::reopen((int) $params['instanceid']);

        return [
            'instanceid' => (int) $row->id,
            'state' => (string) $row->state,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'instanceid' => new external_value(PARAM_INT, 'Instance id.'),
            'state' => new external_value(PARAM_ALPHAEXT, 'New state.'),
        ]);
    }
}
