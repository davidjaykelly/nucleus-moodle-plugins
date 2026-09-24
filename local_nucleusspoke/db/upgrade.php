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
 * Upgrade steps for local_nucleusspoke.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade steps for local_nucleusspoke.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_nucleusspoke_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026042103) {
        // This step introduced the courses mapping table, which
        // install.xml no longer defines: the 2026092404 step drops it
        // where it exists.
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

    if ($oldversion < 2026092400) {
        // Learners can see the category Nucleus creates for pulled
        // courses, so it's now "Shared courses". Rename the existing one
        // unless someone has already renamed it.
        $category = $DB->get_record('course_categories', ['idnumber' => 'nucleus_federation']);
        if ($category && $category->name === 'Nucleus federation') {
            $DB->set_field(
                'course_categories',
                'name',
                get_string('sharedcourses_category', 'local_nucleusspoke'),
                ['id' => $category->id]
            );
            if (str_starts_with((string) $category->description, 'Courses pulled from a Nucleus federation hub.')) {
                $DB->set_field(
                    'course_categories',
                    'description',
                    get_string('sharedcourses_category_desc', 'local_nucleusspoke'),
                    ['id' => $category->id]
                );
            }
            cache_helper::purge_by_event('changesincoursecat');
        }

        upgrade_plugin_savepoint(true, 2026092400, 'local', 'nucleusspoke');
    }

    if ($oldversion < 2026092404) {
        // ADR-023: user sharing ("projection") is gone. Drop the table
        // that marked local courses as stand-ins for hub courses. The
        // courses themselves stay.
        $table = new xmldb_table('local_nucleusspoke_courses');
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        upgrade_plugin_savepoint(true, 2026092404, 'local', 'nucleusspoke');
    }

    return true;
}
