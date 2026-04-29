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
 * External function: local_nucleusspoke_configure_hub.
 *
 * Stores the three plugin settings the spoke needs to call its
 * federation hub. Called by the control plane right after a spoke
 * is provisioned, with values it captured from the hub side.
 *
 * Idempotent: just `set_config` calls.
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

class configure_hub extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'hubwwwroot'    => new external_value(PARAM_URL, 'Hub browser-facing URL (e.g. https://hub.example.com).'),
            'hubtoken'      => new external_value(PARAM_RAW, 'WS token issued by the hub for this spoke.'),
            'hubconnecturl' => new external_value(
                PARAM_URL,
                'Optional: cluster-internal URL the spoke pod uses to reach the hub (e.g. http://hub.nucleus-hub.svc.cluster.local). Defaults to empty.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    public static function execute(string $hubwwwroot, string $hubtoken, string $hubconnecturl = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'hubwwwroot'    => $hubwwwroot,
            'hubtoken'      => $hubtoken,
            'hubconnecturl' => $hubconnecturl,
        ]);

        set_config('hubwwwroot', rtrim($params['hubwwwroot'], '/'), 'local_nucleusspoke');
        set_config('hubtoken', $params['hubtoken'], 'local_nucleusspoke');
        // Empty string clears any previous value, falling back to
        // hubwwwroot for outbound connections.
        set_config('hubconnecturl', rtrim($params['hubconnecturl'], '/'), 'local_nucleusspoke');

        return ['ok' => true];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'true on success'),
        ]);
    }
}
