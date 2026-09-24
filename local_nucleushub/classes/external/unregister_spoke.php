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
 * External function: local_nucleushub_unregister_spoke.
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
use local_nucleushub\local\spoke_identity;

/**
 * Remove a spoke from this hub (ADR-023 section 6).
 *
 * The control plane calls this when a spoke leaves the federation. Every
 * row for that Nucleus spoke is marked `removed`, and its hub service
 * account is cut off and deleted: tokens, place on the
 * `nucleus_federation` service, then the account. Its sign-in client,
 * with any codes and tokens, is deleted too.
 *
 * Idempotent: a second call finds the rows already removed and returns
 * ok again. `ok` is false only when the hub has never had a spoke with
 * that ID, so there was nothing to remove.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unregister_spoke extends external_api {
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
     * Remove the spoke and its hub account.
     *
     * @param string $cpspokeid
     * @return array{ok: bool}
     */
    public static function execute(string $cpspokeid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['cpspokeid' => $cpspokeid]);
        $cpspokeid = trim($params['cpspokeid']);
        if ($cpspokeid === '') {
            throw new \invalid_parameter_exception('cpspokeid is required.');
        }

        $spokes = $DB->get_records('local_nucleushub_spokes', ['cpspokeid' => $cpspokeid]);
        if (!$spokes) {
            return ['ok' => false];
        }

        // Sign-in first: the spoke can't sign anyone in from here on.
        client_registry::delete($cpspokeid);

        foreach ($spokes as $spoke) {
            // The account first: if deleting it fails, the row still
            // points at it and a retry finishes the job.
            if (!empty($spoke->serviceuserid)) {
                spoke_identity::remove((int) $spoke->serviceuserid);
            }
            if ($spoke->status !== 'removed' || !empty($spoke->serviceuserid) || (string) $spoke->token !== '') {
                $DB->update_record('local_nucleushub_spokes', (object) [
                    'id' => $spoke->id,
                    'status' => 'removed',
                    'token' => '',
                    'serviceuserid' => null,
                    'timemodified' => time(),
                ]);
            }
        }

        // Removing the last spoke still on the shared token retires it.
        $service = $DB->get_record('external_services', ['shortname' => spoke_identity::SERVICE]);
        if ($service) {
            spoke_identity::retire_shared_token($service);
        }

        return ['ok' => true];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'True if the hub had this spoke; it is now removed.'),
        ]);
    }
}
