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
 * External function: local_nucleushub_list_users_for_linking.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleushub\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * List hub accounts so Nucleus can offer to link spoke accounts to them
 * (ADR-023 section 4).
 *
 * The control plane pages through this and the spoke's accounts, and
 * matches them by email for an admin to review. `sub` is the opaque
 * subject the hub puts in ID tokens (32 lowercase hex characters, never
 * the user id); listing an account makes its subject if it has none. Deleted accounts, the guest, and `webservice` and
 * `nologin` accounts are left out; unconfirmed and suspended ones are
 * listed with their flags so the control plane can report them.
 *
 * Only on the "Nucleus control plane (hub)" service.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_users_for_linking extends external_api {
    /** @var int Largest page. */
    public const MAX_PER_PAGE = 500;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'page' => new external_value(PARAM_INT, 'Page number, from 0.', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'Accounts per page, at most 500.', VALUE_DEFAULT, self::MAX_PER_PAGE),
        ]);
    }

    /**
     * One page of hub accounts.
     *
     * @param int $page
     * @param int $perpage
     * @return array{users: array, total: int}
     */
    public static function execute(int $page = 0, int $perpage = self::MAX_PER_PAGE): array {
        global $CFG, $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['page' => $page, 'perpage' => $perpage]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $page = max(0, (int) $params['page']);
        $perpage = min(self::MAX_PER_PAGE, max(1, (int) $params['perpage']));

        [$authsql, $sqlparams] = $DB->get_in_or_equal(
            \local_nucleushub\local\oidc\provider::REFUSED_AUTH,
            SQL_PARAMS_NAMED,
            'auth',
            false
        );
        $where = "deleted = 0 AND id <> :guestid AND auth {$authsql}";
        $sqlparams['guestid'] = (int) ($CFG->siteguest ?? 0);

        $total = $DB->count_records_select('user', $where, $sqlparams);
        $rows = $DB->get_records_select(
            'user',
            $where,
            $sqlparams,
            'id ASC',
            'id, email, confirmed, suspended',
            $page * $perpage,
            $perpage
        );

        $subs = \local_nucleushub\local\oidc\subjects::for_users(array_keys($rows));
        $users = [];
        foreach ($rows as $row) {
            $users[] = [
                'sub' => $subs[(int) $row->id],
                'email' => (string) $row->email,
                'confirmed' => !empty($row->confirmed),
                'suspended' => !empty($row->suspended),
            ];
        }
        return ['users' => $users, 'total' => (int) $total];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'users' => new external_multiple_structure(new external_single_structure([
                'sub' => new external_value(PARAM_ALPHANUM, 'Opaque subject: the sub claim, 32 lowercase hex characters.'),
                'email' => new external_value(PARAM_RAW, 'Email address.'),
                'confirmed' => new external_value(PARAM_BOOL, 'Whether the account is confirmed.'),
                'suspended' => new external_value(PARAM_BOOL, 'Whether the account is suspended.'),
            ])),
            'total' => new external_value(PARAM_INT, 'Accounts in every page.'),
        ]);
    }
}
