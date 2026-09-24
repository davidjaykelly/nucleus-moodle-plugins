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
 * External function: local_nucleusspoke_disable_signin.
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
 * Turn sign-in with the hub off on this spoke (ADR-023 section 7).
 *
 * - Removes 'nucleus' from $CFG->auth the way Site administration >
 *   Manage authentication does, which also ends hub accounts' sessions.
 * - Gives every auth=nucleus account a login of its own again: a linked
 *   account gets back the method it had before it was linked, when that
 *   authentication plugin is still enabled; otherwise, and for accounts
 *   the hub created, it becomes a manual account with no password here,
 *   so people use "Forgotten your password?". Each change goes through
 *   user_update_user(), so user_updated fires.
 * - Forgets the client secret. The links stay, so turning sign-in back
 *   on with the same issuer reuses them.
 *
 * Works without auth_nucleus installed (then there's nothing to turn
 * off, but stray hub accounts are still switched to manual).
 */
class disable_signin extends external_api {

    /**
     * Parameters: none.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Turn hub sign-in off.
     *
     * @return array ['converted' => int]
     */
    public static function execute(): array {
        global $CFG, $DB;

        self::validate_parameters(self::execute_parameters(), []);
        signin::require_site_config();

        // Off first, so nobody signs in with the hub while accounts change.
        $class = \core_plugin_manager::resolve_plugininfo_class('auth');
        $class::enable_plugin('nucleus', 0);

        if (signin::plugin_installed()) {
            // Each account gets back the login it had before it was
            // linked, when that is still enabled; otherwise manual.
            $converted = \auth_nucleus\local\accounts::release_hub_accounts();
            \auth_nucleus\local\config::clear_secret();
            return ['converted' => $converted];
        }

        // Without auth_nucleus there are no links: any stray hub accounts
        // become manual accounts, one by one so user_updated fires.
        require_once($CFG->dirroot . '/user/lib.php');
        \core\session\manager::destroy_by_auth_plugin('nucleus');
        $ids = $DB->get_fieldset_select('user', 'id', 'auth = :auth AND deleted = 0', ['auth' => 'nucleus']);
        foreach ($ids as $id) {
            user_update_user((object) ['id' => (int) $id, 'auth' => 'manual'], false, true);
        }
        $DB->set_field_select('user', 'auth', 'manual', 'auth = :auth AND deleted = 1', ['auth' => 'nucleus']);
        return ['converted' => count($ids)];
    }

    /**
     * Return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'converted' => new external_value(PARAM_INT,
                'How many hub accounts got their own login back (previous method or manual).'),
        ]);
    }
}
