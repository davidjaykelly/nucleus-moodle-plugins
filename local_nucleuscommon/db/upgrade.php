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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * Upgrade steps for local_nucleuscommon.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Run schema upgrades for local_nucleuscommon.
 *
 * Each `if ($oldversion < NNN)` block must terminate with a matching
 * upgrade_plugin_savepoint() call per Moodle coding standards.
 *
 * @param int $oldversion Previously-installed version of this plugin.
 * @return bool True on success.
 */
function xmldb_local_nucleuscommon_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026042401) {
        // ADR-014 Phase 1 — introduce the shared course-family and
        // course-version tables. Common plugin existed before
        // install.xml did, so for existing installs we create what
        // install.xml would have on a fresh install (same pattern as
        // the hub / spoke plugins).
        $tables = [
            'local_nucleuscommon_family',
            'local_nucleuscommon_version',
        ];
        foreach ($tables as $tablename) {
            $table = new xmldb_table($tablename);
            if (!$dbman->table_exists($table)) {
                $dbman->install_one_table_from_xmldb_file(
                    __DIR__ . '/install.xml',
                    $tablename
                );
            }
        }

        upgrade_plugin_savepoint(true, 2026042401, 'local', 'nucleuscommon');
    }

    return true;
}
