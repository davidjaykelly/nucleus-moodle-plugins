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
 * External function: local_nucleusspoke_apply_links.
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
 * Link existing accounts to hub accounts, as reviewed in the portal
 * (ADR-023). Each applied link makes the account a hub account
 * (auth=nucleus, no local password).
 *
 * Links are stored against the configured issuer, with the account's
 * current login method, which disable_signin gives back.
 *
 * Skipped, with a reason code: siteadmin, privileged (a site-level role,
 * or moodle/site:config, moodle/role:assign or moodle/user:update at site
 * level), suspended, deleted, already_linked (the account is linked to
 * another hub account), sub_linked (the hub account is linked to another
 * account), not_found, not_allowed (the guest, a remote account, or auth
 * webservice/nologin) and invalid_sub (not 32 lower-case hex characters).
 * Linking the same pair again counts as applied.
 */
class apply_links extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'links' => new external_multiple_structure(new external_single_structure([
                'userid' => new external_value(PARAM_INT, 'Local user id.'),
                'sub' => new external_value(PARAM_RAW_TRIMMED, 'The hub account\'s sub: 32 lower-case hex characters.'),
            ])),
        ]);
    }

    /**
     * Apply the links.
     *
     * @param array $links
     * @return array ['applied' => int, 'skipped' => [['userid' => int, 'reason' => string], ...]]
     */
    public static function execute(array $links): array {
        $params = self::validate_parameters(self::execute_parameters(), ['links' => $links]);
        signin::require_site_config();
        signin::require_plugin();

        return \auth_nucleus\local\accounts::apply_links($params['links']);
    }

    /**
     * Return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'applied' => new external_value(PARAM_INT, 'How many links were applied.'),
            'skipped' => new external_multiple_structure(new external_single_structure([
                'userid' => new external_value(PARAM_INT, 'Local user id.'),
                'reason' => new external_value(PARAM_ALPHANUMEXT,
                    'siteadmin, privileged, suspended, deleted, already_linked, sub_linked, not_found, not_allowed '
                    . 'or invalid_sub.'),
            ])),
        ]);
    }
}
