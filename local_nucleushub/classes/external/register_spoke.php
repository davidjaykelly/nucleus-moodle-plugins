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
 * External function: local_nucleushub_register_spoke.
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
 * Register a spoke with this hub and return the token it calls the hub with.
 *
 * The control plane calls this when it provisions a spoke or an external
 * spoke joins, then pushes the token to the spoke (configure_hub).
 *
 * Each spoke gets its own hub service account and its own permanent
 * token for the `nucleus_federation` service (ADR-023 section 6); see
 * {@see spoke_identity}. Idempotent: calling it again for the same
 * wwwroot reuses the spoke's account and returns the same token.
 *
 * An address another Nucleus spoke still holds is refused
 * (`spokeurlinuse`), comparing scheme and host without regard to case
 * and ignoring default ports: one spoke can't take over another's hub
 * account, token or sign-in client by claiming its address. Only a
 * removed spoke's address, or a row with no Nucleus spoke ID, can be
 * taken over.
 *
 * When every active spoke has its own account, the site admin token
 * all spokes used to share is deleted (see
 * {@see spoke_identity::retire_shared_token()}).
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class register_spoke extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'wwwroot'    => new external_value(PARAM_URL, 'Spoke browser-facing URL (e.g. https://acme.example.com)'),
            'name'       => new external_value(PARAM_TEXT, 'Operator-facing spoke name / slug'),
            'cpspokeid'  => new external_value(
                PARAM_RAW,
                'Nucleus spoke ID. Optional - older callers can leave it out.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Register the spoke, give it its own hub account, and return that account's token.
     *
     * @param string $wwwroot
     * @param string $name
     * @param string $cpspokeid
     * @return array{token: string, spokeId: int}
     */
    public static function execute(string $wwwroot, string $name, string $cpspokeid = ''): array {
        global $DB;

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['wwwroot' => $wwwroot, 'name' => $name, 'cpspokeid' => $cpspokeid]
        );
        $wwwroot = trim($params['wwwroot'], '/');
        $name = trim($params['name']);
        $cpspokeid = trim($params['cpspokeid']);
        if ($wwwroot === '') {
            throw new \invalid_parameter_exception('wwwroot must be a URL.');
        }

        $service = spoke_identity::service();
        $now = time();
        // Accounts cut off now and deleted once the registration is saved.
        $retired = [];

        // Another Nucleus spoke still at this address: refuse, and touch
        // nothing of theirs.
        self::refuse_if_in_use($wwwroot, $cpspokeid);

        $transaction = $DB->start_delegated_transaction();

        // The browser-facing URL is the natural key: one row per spoke site.
        $spoke = $DB->get_record('local_nucleushub_spokes', ['wwwroot' => $wwwroot]);
        if ($spoke) {
            if (!empty($spoke->cpspokeid) && $spoke->cpspokeid !== $cpspokeid) {
                // A removed spoke's address, taken over by a new spoke
                // (anything else was refused above). Clear anything the
                // old spoke left: it keeps no token or sign-in client.
                if (!empty($spoke->serviceuserid)) {
                    spoke_identity::revoke((int) $spoke->serviceuserid);
                }
                client_registry::delete_for_spoke_row((int) $spoke->id);
            }
            $spoke->name = $name;
            $spoke->status = 'active';
            $spoke->timemodified = $now;
            if ($cpspokeid !== '') {
                $spoke->cpspokeid = $cpspokeid;
            }
        } else {
            $spoke = (object) [
                'name'         => $name,
                'wwwroot'      => $wwwroot,
                'token'        => '',
                'status'       => 'active',
                'cpspokeid'    => $cpspokeid !== '' ? $cpspokeid : null,
                'serviceuserid' => null,
                'timecreated'  => $now,
                'timemodified' => $now,
            ];
            $spoke->id = $DB->insert_record('local_nucleushub_spokes', $spoke);
        }

        $identity = spoke_identity::ensure($spoke, $service);
        $spoke->serviceuserid = $identity['userid'];
        // A copy of the token on the row, for the hub's own records.
        $spoke->token = $identity['token'];
        $DB->update_record('local_nucleushub_spokes', $spoke);

        // The same Nucleus spoke registered at an older address is gone:
        // one spoke, one hub account.
        if ($cpspokeid !== '') {
            $others = $DB->get_records_select(
                'local_nucleushub_spokes',
                'cpspokeid = :cpspokeid AND id <> :id AND status <> :removed',
                ['cpspokeid' => $cpspokeid, 'id' => $spoke->id, 'removed' => 'removed']
            );
            foreach ($others as $other) {
                // Its sign-in client follows it to the new address, so
                // sign-in keeps working (ADR-023 section 3).
                client_registry::move((int) $other->id, $spoke);
                if (!empty($other->serviceuserid)) {
                    spoke_identity::revoke((int) $other->serviceuserid);
                    $retired[] = (int) $other->serviceuserid;
                }
                $DB->update_record('local_nucleushub_spokes', (object) [
                    'id' => $other->id,
                    'status' => 'removed',
                    'token' => '',
                    'serviceuserid' => null,
                    'timemodified' => $now,
                ]);
            }
        }

        $transaction->allow_commit();

        // Outside the transaction: deleting a user is a lot of work. Their
        // tokens are already gone, so a failure here leaves nothing usable.
        foreach ($retired as $userid) {
            spoke_identity::remove($userid);
        }
        spoke_identity::retire_shared_token($service);

        return [
            'token'   => $identity['token'],
            'spokeId' => (int) $spoke->id,
        ];
    }

    /**
     * Refuse an address another Nucleus spoke still holds.
     *
     * A row that isn't removed and has a different (non-empty) Nucleus
     * spoke ID holds the address. Addresses are compared with scheme and
     * host in lower case and default ports dropped, so a change of case
     * or an explicit :443 doesn't get round it.
     *
     * @param string $wwwroot The address being registered.
     * @param string $cpspokeid The Nucleus spoke ID registering it ('' for older callers).
     * @return void
     * @throws \moodle_exception spokeurlinuse
     */
    private static function refuse_if_in_use(string $wwwroot, string $cpspokeid): void {
        global $DB;

        $wanted = self::normalise_wwwroot($wwwroot);
        $rows = $DB->get_records_select(
            'local_nucleushub_spokes',
            "status <> :removed AND cpspokeid IS NOT NULL AND cpspokeid <> ''",
            ['removed' => 'removed'],
            '',
            'id, wwwroot, cpspokeid'
        );
        foreach ($rows as $row) {
            if ((string) $row->cpspokeid !== $cpspokeid && self::normalise_wwwroot((string) $row->wwwroot) === $wanted) {
                throw new \moodle_exception('spokeurlinuse', 'local_nucleushub', '', s($wwwroot));
            }
        }
    }

    /**
     * An address in a form for comparison: scheme and host lower case,
     * default port dropped, no trailing slash.
     *
     * @param string $wwwroot
     * @return string
     */
    private static function normalise_wwwroot(string $wwwroot): string {
        $wwwroot = rtrim(trim($wwwroot), '/');
        $parts = parse_url($wwwroot);
        if (!$parts || empty($parts['host'])) {
            return strtolower($wwwroot);
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }
        return $scheme . '://' . strtolower((string) $parts['host']) . ($port !== null ? ':' . $port : '')
            . rtrim((string) ($parts['path'] ?? ''), '/');
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'token'   => new external_value(PARAM_RAW, 'Token the spoke uses on hub WS calls.'),
            'spokeId' => new external_value(PARAM_INT, 'local_nucleushub_spokes row id.'),
        ]);
    }
}
