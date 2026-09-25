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
 * External function: local_nucleushub_move_spoke.
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
use local_nucleushub\local\spoke_address;
use local_nucleushub\local\spoke_identity;

/**
 * Move a spoke to a new address, keeping its hub account and token.
 *
 * The control plane calls this when a spoke switches to a custom domain,
 * or back to its Nucleus name. The spoke's active row is updated in
 * place (wwwroot and timemodified), and its sign-in client follows it:
 * redirect and post-logout URIs rebuilt from the new address, pending
 * codes dropped, client id and secret kept (see
 * {@see client_registry::repoint()}).
 *
 * Unlike register_spoke at a new address, this doesn't make a new row
 * or touch the spoke's service account or token, so the spoke's hub
 * connection keeps working throughout.
 *
 * Refusals, with nothing changed:
 * - `invalidwwwroot`: the address isn't one a spoke can have (see
 *   {@see spoke_address::clean_for_move()}), for example http while the
 *   hub is https;
 * - `spokenotfound`: no active row for that Nucleus spoke ID;
 * - `spokeurlinuse`: another spoke row that isn't removed has the
 *   address, compared as register_spoke compares addresses.
 *
 * A removed spoke's row at the address is deleted, so the address can be
 * used again, as register_spoke lets a new spoke take one over. The same
 * address again is a no-op that returns `moved` false, so a retry is
 * safe. Only on the "Nucleus control plane (hub)" service.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class move_spoke extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cpspokeid' => new external_value(PARAM_RAW, 'Nucleus spoke ID, as passed to register_spoke.'),
            // Checked in execute(), so a bad address is always refused as invalidwwwroot.
            'wwwroot' => new external_value(PARAM_RAW, 'The spoke\'s new browser-facing URL (e.g. https://training.example.com).'),
        ]);
    }

    /**
     * Move the spoke's row and sign-in client to the new address.
     *
     * @param string $cpspokeid
     * @param string $wwwroot
     * @return array{moved: bool, spokeid: int}
     * @throws \moodle_exception invalidwwwroot, spokenotfound or spokeurlinuse
     */
    public static function execute(string $cpspokeid, string $wwwroot): array {
        global $DB;

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cpspokeid' => $cpspokeid, 'wwwroot' => $wwwroot]
        );
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $cpspokeid = trim($params['cpspokeid']);
        if ($cpspokeid === '') {
            throw new \invalid_parameter_exception('cpspokeid is required.');
        }
        $wwwroot = spoke_address::clean_for_move($params['wwwroot']);

        $spoke = client_registry::active_spoke($cpspokeid);
        if (!$spoke) {
            throw new \moodle_exception('spokenotfound', 'local_nucleushub', '', s($cpspokeid));
        }
        if ((string) $spoke->wwwroot === $wwwroot) {
            return ['moved' => false, 'spokeid' => (int) $spoke->id];
        }

        // Any other row at the address (the unique index is on wwwroot).
        // The spoke's own row may be there under another case.
        $removed = [];
        foreach (spoke_address::rows_at($wwwroot) as $row) {
            if ((int) $row->id === (int) $spoke->id) {
                continue;
            }
            if ((string) $row->status !== 'removed') {
                throw new \moodle_exception('spokeurlinuse', 'local_nucleushub', '', s($wwwroot));
            }
            $removed[] = $row;
        }

        $transaction = $DB->start_delegated_transaction();

        // A removed spoke's row keeps nothing usable, but it still holds
        // the address. Clear anything it might have left, as
        // register_spoke does when a new spoke takes an address over,
        // then delete it.
        foreach ($removed as $row) {
            if (!empty($row->serviceuserid)) {
                spoke_identity::revoke((int) $row->serviceuserid);
            }
            client_registry::delete_for_spoke_row((int) $row->id);
            $DB->delete_records('local_nucleushub_spokes', ['id' => $row->id]);
        }

        $spoke->wwwroot = $wwwroot;
        $spoke->timemodified = time();
        $DB->update_record('local_nucleushub_spokes', (object) [
            'id' => $spoke->id,
            'wwwroot' => $spoke->wwwroot,
            'timemodified' => $spoke->timemodified,
        ]);
        client_registry::repoint($spoke);

        $transaction->allow_commit();

        return ['moved' => true, 'spokeid' => (int) $spoke->id];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'moved' => new external_value(PARAM_BOOL, 'True if the address changed; false if the spoke was already there.'),
            'spokeid' => new external_value(PARAM_INT, 'local_nucleushub_spokes row id, the same as before.'),
        ]);
    }
}
