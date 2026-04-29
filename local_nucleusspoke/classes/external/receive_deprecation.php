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
 * External function: local_nucleusspoke_receive_deprecation
 * (ADR-014 Phase 2). Fanned out by CP when a hub deprecates / undeprecates
 * a version; this function mirrors the flag on the spoke's local
 * version row. No-op when the spoke doesn't know the version (never
 * had a notification or a pull for it).
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

defined('MOODLE_INTERNAL') || die();

class receive_deprecation extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'versionguid' => new external_value(PARAM_ALPHANUMEXT, 'Version guid.'),
            'deprecated' => new external_value(PARAM_BOOL, 'New deprecation state.'),
            'deprecatedreason' => new external_value(PARAM_RAW, 'Reason.', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        string $versionguid,
        bool $deprecated,
        string $deprecatedreason
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'versionguid' => $versionguid,
            'deprecated' => $deprecated,
            'deprecatedreason' => $deprecatedreason,
        ]);

        $row = $DB->get_record(
            'local_nucleuscommon_version',
            ['guid' => $params['versionguid']]
        );
        if (!$row) {
            // Spoke doesn't know this version — no-op.
            return [
                'matched' => false,
                'deprecated' => (bool) $params['deprecated'],
            ];
        }

        $DB->update_record('local_nucleuscommon_version', (object) [
            'id' => $row->id,
            'deprecated' => $params['deprecated'] ? 1 : 0,
            'deprecatedreason' => $params['deprecated']
                ? ($params['deprecatedreason'] !== '' ? (string) $params['deprecatedreason'] : null)
                : null,
        ]);

        return [
            'matched' => true,
            'deprecated' => (bool) $params['deprecated'],
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'matched' => new external_value(PARAM_BOOL, 'true when the spoke had this version locally.'),
            'deprecated' => new external_value(PARAM_BOOL, 'New deprecation state applied.'),
        ]);
    }
}
