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
 * External function: local_nucleuscommon_set_federation_mode.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleuscommon\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

/**
 * CP-driven sync of `Federation.mode` onto this Moodle's
 * `local_nucleuscommon/federationmode` config. Idempotent — calling
 * with the same value is a no-op write. Lives on the common plugin
 * so the same WS function works on hubs and spokes; both nucleus_cp
 * services include it.
 */
class set_federation_mode extends external_api {

    /** @var string[] Modes the dispatcher + CP enum agree on. */
    public const VALID_MODES = ['content', 'identity', 'both'];

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'mode' => new external_value(PARAM_ALPHA,
                'Federation mode: content | identity | both.'),
        ]);
    }

    /**
     * @param string $mode
     * @return array{ok: bool, mode: string, previous: string}
     */
    public static function execute(string $mode): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'mode' => $mode,
        ]);
        $mode = strtolower($params['mode']);
        if (!in_array($mode, self::VALID_MODES, true)) {
            throw new \invalid_parameter_exception(
                "mode must be one of: " . implode(', ', self::VALID_MODES)
            );
        }

        $previous = (string)(get_config('local_nucleuscommon', 'federationmode') ?: '');
        if ($previous !== $mode) {
            set_config('federationmode', $mode, 'local_nucleuscommon');
        }

        return [
            'ok'       => true,
            'mode'     => $mode,
            'previous' => $previous,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok'       => new external_value(PARAM_BOOL, 'true on success.'),
            'mode'     => new external_value(PARAM_ALPHA, 'Mode now in effect.'),
            'previous' => new external_value(PARAM_RAW, 'Mode that was in effect before this call (empty string if unset).'),
        ]);
    }
}
