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
 * Upgrade steps for local_nucleushub.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Run schema upgrades for local_nucleushub.
 *
 * Every `if ($oldversion < NNN)` block must end with a matching
 * upgrade_plugin_savepoint() call — see the Moodle coding standards.
 *
 * @param int $oldversion Previously-installed version of this plugin.
 * @return bool True on success.
 */
function xmldb_local_nucleushub_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026042101) {
        // Introduce the three hub tables. This block is intentionally
        // idempotent per-table: the plugin was registered at version
        // 2026042100 before install.xml existed (Day 1 skeleton), so for
        // existing installs we need to create what install.xml would have
        // created on a fresh install.
        $tables = [
            'local_nucleushub_spokes',
            'local_nucleushub_projusers',
            'local_nucleushub_events',
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

        upgrade_plugin_savepoint(true, 2026042101, 'local', 'nucleushub');
    }

    if ($oldversion < 2026042401) {
        // ADR-014 Phase 1 — introduce hub-side course-versioning
        // tables: 'draft' tracks the one-course-per-family working
        // copy + pending change counter, 'changelog' accumulates
        // edit events between publishes.
        $tables = [
            'local_nucleushub_draft',
            'local_nucleushub_changelog',
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

        upgrade_plugin_savepoint(true, 2026042401, 'local', 'nucleushub');
    }

    if ($oldversion < 2026042602) {
        // Phase B1 Step 2 — local_nucleushub_spokes.cpspokeid carries
        // the control-plane Spoke.id (cuid) so events emitted from
        // the hub can address the right CP Spoke directly. Backfilled
        // by register_spoke on the next CP→hub round-trip.
        $table = new xmldb_table('local_nucleushub_spokes');
        $field = new xmldb_field('cpspokeid', XMLDB_TYPE_CHAR, '40', null,
            null, null, null, 'timelastheartbeat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('cpspokeid', XMLDB_INDEX_NOTUNIQUE, ['cpspokeid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        upgrade_plugin_savepoint(true, 2026042602, 'local', 'nucleushub');
    }

    return true;
}
