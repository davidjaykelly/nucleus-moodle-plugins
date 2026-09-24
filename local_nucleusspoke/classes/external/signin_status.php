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

/**
 * External function: local_nucleusspoke_signin_status.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleusspoke\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_nucleusspoke\local\signin;

defined('MOODLE_INTERNAL') || die();

/**
 * Whether sign-in with the hub is on here, and with which client
 * (ADR-023). Never returns the client secret.
 */
class signin_status extends external_api {

    /**
     * Parameters: none.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Report the status.
     *
     * @return array ['enabled' => bool, 'issuer' => string, 'clientid' => string, 'linked' => int]
     */
    public static function execute(): array {
        self::validate_parameters(self::execute_parameters(), []);
        signin::require_site_config();
        signin::require_plugin();

        return [
            'enabled' => \auth_nucleus\local\config::is_enabled(),
            'issuer' => \auth_nucleus\local\config::issuer(),
            'clientid' => \auth_nucleus\local\config::clientid(),
            'linked' => \auth_nucleus\local\accounts::linked_count(),
        ];
    }

    /**
     * Return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'enabled' => new external_value(PARAM_BOOL, 'Whether \'nucleus\' is in $CFG->auth.'),
            'issuer' => new external_value(PARAM_RAW, 'The configured issuer, or empty.'),
            'clientid' => new external_value(PARAM_RAW, 'The configured client id, or empty.'),
            'linked' => new external_value(PARAM_INT, 'How many accounts are linked to hub accounts.'),
        ]);
    }
}
