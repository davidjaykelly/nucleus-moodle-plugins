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
 * Upgrade steps for auth_nucleus.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade steps for auth_nucleus.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_auth_nucleus_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026092501) {
        // Links now belong to an issuer and remember the account's previous
        // login method, and the hub's sub became an opaque 32-character hex
        // string, so no existing link can match again. There are none in
        // production: recreate the table empty.
        $table = new xmldb_table('auth_nucleus_link');
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
        $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'auth_nucleus_link');

        // Accounts the old links pointed at can't sign in with the hub any
        // more; make them manual accounts (Forgotten your password?), as
        // turning sign-in off does. Any session they have ends.
        \core\session\manager::destroy_by_auth_plugin('nucleus');
        $DB->set_field('user', 'auth', 'manual', ['auth' => 'nucleus']);

        upgrade_plugin_savepoint(true, 2026092501, 'auth', 'nucleus');
    }

    return true;
}
