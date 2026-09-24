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
 * External function: local_nucleushub_oidc_delete_client.
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
 * Delete a spoke's sign-in client, with its codes and tokens (ADR-023).
 *
 * The control plane calls this when it turns "sign in with the hub"
 * off for a spoke. The spoke can't sign anyone in through the hub from
 * then on. Idempotent: `ok` is true once the spoke has no client,
 * including when it never had one.
 *
 * Only on the "Nucleus control plane (hub)" service.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class oidc_delete_client extends external_api {
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
     * Delete the client.
     *
     * @param string $cpspokeid
     * @return array{ok: bool}
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
        client_registry::delete($cpspokeid);
        return ['ok' => true];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'True: the spoke has no sign-in client on this hub now.'),
        ]);
    }
}
