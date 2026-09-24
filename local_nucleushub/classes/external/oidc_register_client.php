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
 * External function: local_nucleushub_oidc_register_client.
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
use local_nucleushub\local\oidc\client_registry;

/**
 * Register a spoke's sign-in client on this hub (ADR-023 section 3).
 *
 * The control plane calls this when it turns on "sign in with the hub"
 * for a spoke, then pushes the result to the spoke. The client id stays
 * the same for a spoke; every call issues a new secret, returned here
 * in plain text and nowhere else, and stored only as a hash.
 *
 * Only on the "Nucleus control plane (hub)" service, never on the
 * federation service spokes call.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class oidc_register_client extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cpspokeid' => new external_value(PARAM_RAW, 'Nucleus spoke ID, as passed to register_spoke.'),
        ]);
    }

    /**
     * Register the client, or give it a new secret.
     *
     * @param string $cpspokeid
     * @return array{issuer: string, clientid: string, clientsecret: string}
     */
    public static function execute(string $cpspokeid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cpspokeid' => $cpspokeid]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $cpspokeid = trim($params['cpspokeid']);
        if ($cpspokeid === '') {
            throw new \invalid_parameter_exception('cpspokeid is required.');
        }
        return client_registry::register($cpspokeid);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'issuer' => new external_value(PARAM_RAW, 'Issuer URL the spoke must expect in ID tokens.'),
            'clientid' => new external_value(PARAM_RAW, 'The spoke\'s client id.'),
            'clientsecret' => new external_value(PARAM_RAW, 'The new client secret. Shown only in this response.'),
        ]);
    }
}
