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
 * Upgrade steps for local_nucleusspoke.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_nucleusspoke_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026042103) {
        // Introduce the courses mapping table. Same idempotent pattern as
        // the hub plugin — see local_nucleushub/db/upgrade.php for the
        // reasoning (findings.md §7: install.xml doesn't retroactively
        // apply to existing plugin installs).
        $tables = ['local_nucleusspoke_courses'];
        foreach ($tables as $tablename) {
            $table = new xmldb_table($tablename);
            if (!$dbman->table_exists($table)) {
                $dbman->install_one_table_from_xmldb_file(
                    __DIR__ . '/install.xml',
                    $tablename
                );
            }
        }
        upgrade_plugin_savepoint(true, 2026042103, 'local', 'nucleusspoke');
    }

    if ($oldversion < 2026042401) {
        // ADR-014 Phase 1 — spoke-side course-versioning tables:
        // 'instance' tracks pulled courses pinned to specific
        // versions, 'notification' queues hub-publish events awaiting
        // a decision.
        $tables = [
            'local_nucleusspoke_instance',
            'local_nucleusspoke_notification',
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
        upgrade_plugin_savepoint(true, 2026042401, 'local', 'nucleusspoke');
    }

    if ($oldversion < 2026043002) {
        // ADR-021 v1.1 — pullnotes column on instance. Captures Tier
        // C notes (older plugin versions, restore precheck warnings)
        // surfaced in the spoke's catalogue UI.
        $table = new xmldb_table('local_nucleusspoke_instance');
        $field = new xmldb_field(
            'pullnotes',
            XMLDB_TYPE_TEXT,
            null,
            null,
            null,
            null,
            null,
            'timemodified'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026043002, 'local', 'nucleusspoke');
    }

    return true;
}
