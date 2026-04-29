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
 * External function: local_nucleushub_mark_deprecated
 * (ADR-014 Phase 2).
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
use local_nucleushub\version\deprecator;

defined('MOODLE_INTERNAL') || die();

class mark_deprecated extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'versionguid' => new external_value(PARAM_ALPHANUMEXT, 'Version guid to flip.'),
            'deprecated' => new external_value(PARAM_BOOL, 'true to mark, false to clear.'),
            'reason' => new external_value(
                PARAM_RAW,
                'Operator note shown in UIs. Ignored when deprecated=false.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    public static function execute(
        string $versionguid,
        bool $deprecated,
        string $reason
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'versionguid' => $versionguid,
            'deprecated' => $deprecated,
            'reason' => $reason,
        ]);

        $row = deprecator::set(
            (string) $params['versionguid'],
            (bool) $params['deprecated'],
            $params['reason'] !== '' ? (string) $params['reason'] : null
        );

        return [
            'versionguid' => (string) $row->guid,
            'deprecated' => (bool) $row->deprecated,
            'deprecatedreason' => (string) ($row->deprecatedreason ?? ''),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'versionguid' => new external_value(PARAM_ALPHANUMEXT, 'Version guid.'),
            'deprecated' => new external_value(PARAM_BOOL, 'New deprecation state.'),
            'deprecatedreason' => new external_value(PARAM_RAW, 'Reason (empty if cleared).'),
        ]);
    }
}
