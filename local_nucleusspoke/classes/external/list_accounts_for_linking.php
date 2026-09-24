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
 * External function: local_nucleusspoke_list_accounts_for_linking.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleusspoke\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_nucleusspoke\local\signin;

defined('MOODLE_INTERNAL') || die();

/**
 * One page of this spoke's accounts, for the portal's linking preview
 * (ADR-023). Deleted accounts and the guest are left out. Privileged
 * accounts are listed, flagged, and can't be linked.
 */
class list_accounts_for_linking extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'page' => new external_value(PARAM_INT, 'Page number, from 0.', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'Accounts per page, at most 500.', VALUE_DEFAULT, 100),
        ]);
    }

    /**
     * List the accounts.
     *
     * @param int $page
     * @param int $perpage
     * @return array ['accounts' => [...], 'total' => int]
     */
    public static function execute(int $page = 0, int $perpage = 100): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'page' => $page,
            'perpage' => $perpage,
        ]);
        signin::require_site_config();
        signin::require_plugin();

        return \auth_nucleus\local\accounts::list_for_linking((int) $params['page'], (int) $params['perpage']);
    }

    /**
     * Return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'accounts' => new external_multiple_structure(new external_single_structure([
                'userid' => new external_value(PARAM_INT, 'Local user id.'),
                'email' => new external_value(PARAM_RAW, 'Email address, as stored.'),
                'auth' => new external_value(PARAM_ALPHANUMEXT, 'Authentication method (manual, nucleus, webservice, ...).'),
                'suspended' => new external_value(PARAM_BOOL, 'Whether the account is suspended.'),
                'siteadmin' => new external_value(PARAM_BOOL, 'Whether the account is a site admin.'),
                'privileged' => new external_value(PARAM_BOOL,
                    'Whether the account is privileged (a site admin, a site-level role, or moodle/site:config, '
                    . 'moodle/role:assign or moodle/user:update at site level). Never linked.'),
                'sub' => new external_value(PARAM_RAW, 'The hub sub it is linked to, or empty.'),
            ])),
            'total' => new external_value(PARAM_INT, 'Accounts in all pages.'),
        ]);
    }
}
