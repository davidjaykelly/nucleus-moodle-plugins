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
 * External function: local_nucleushub_signin_status.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleushub\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_nucleushub\local\org_signin;

/**
 * The hub's sign-in set-up: the organisation's provider, and whether
 * the hub lets anyone create an account.
 *
 * Only on the "Nucleus control plane (hub)" service.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signin_status extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * The status.
     *
     * @return array{org: array, selfregistration: bool}
     */
    public static function execute(): array {
        self::validate_parameters(self::execute_parameters(), []);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        return org_signin::status();
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'org' => new external_single_structure([
                'configured' => new external_value(PARAM_BOOL, 'Nucleus has set up an issuer on this hub.'),
                'enabled' => new external_value(PARAM_BOOL,
                    'It is on and usable: issuer enabled with its endpoints, and the OAuth 2 plugin on.'),
                'provider' => new external_value(PARAM_ALPHA, 'microsoft, google or oidc; empty if not set up.'),
                'displayname' => new external_value(PARAM_TEXT, 'Name on the login button.'),
                'domains' => new external_value(PARAM_RAW, 'Allowed email domains, comma-separated.'),
                'issuerid' => new external_value(PARAM_INT, 'The Moodle OAuth 2 issuer id, or 0.'),
            ]),
            'selfregistration' => new external_value(PARAM_BOOL, 'True if the hub lets anyone create an account.'),
        ]);
    }
}
