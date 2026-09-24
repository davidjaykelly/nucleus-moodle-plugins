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
 * Upgrade steps for local_nucleushub.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
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
        // Introduce the hub's spokes table. This block is intentionally
        // idempotent per-table: the plugin was registered at version
        // 2026042100 before install.xml existed (Day 1 skeleton), so for
        // existing installs we need to create what install.xml would have
        // created on a fresh install. It also created the projusers and
        // events tables, which install.xml no longer defines; the
        // 2026092404 step drops them where they exist.
        $tables = [
            'local_nucleushub_spokes',
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
        $field = new xmldb_field(
            'cpspokeid',
            XMLDB_TYPE_CHAR,
            '40',
            null,
            null,
            null,
            null,
            'timelastheartbeat'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('cpspokeid', XMLDB_INDEX_NOTUNIQUE, ['cpspokeid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        upgrade_plugin_savepoint(true, 2026042602, 'local', 'nucleushub');
    }

    if ($oldversion < 2026092400) {
        // The shared spoke token setting (and its settings page) is gone.
        // Per-spoke hub accounts and tokens arrived in 2026092404.
        unset_config('spoketoken', 'local_nucleushub');

        upgrade_plugin_savepoint(true, 2026092400, 'local', 'nucleushub');
    }

    if ($oldversion < 2026092402) {
        // The legacy course copy (request_course_copy + download.php) is
        // gone. Its backups sat in dataroot and were made with the site's
        // defaults, which include enrolled users, so delete them.
        global $CFG;
        $dir = rtrim($CFG->dataroot, '/') . '/nucleushub_backups';
        if (is_dir($dir)) {
            fulldelete($dir);
        }

        upgrade_plugin_savepoint(true, 2026092402, 'local', 'nucleushub');
    }

    if ($oldversion < 2026092404) {
        // ADR-023: user sharing ("projection") is gone. Drop the table
        // that linked spoke users to their hub copies, and the outbound
        // event log that only completion routing wrote to.
        foreach (['local_nucleushub_projusers', 'local_nucleushub_events'] as $tablename) {
            $table = new xmldb_table($tablename);
            if ($dbman->table_exists($table)) {
                $dbman->drop_table($table);
            }
        }

        // ADR-023 section 6: every spoke gets its own hub service account.
        // Rows get it the next time Nucleus calls register_spoke; until
        // then those spokes keep the old shared token, which
        // register_spoke retires once no active spoke needs it.
        $table = new xmldb_table('local_nucleushub_spokes');
        $field = new xmldb_field('serviceuserid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'cpspokeid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Foreign keys are created as plain indexes, and add_key skips
        // the index when it already exists.
        $key = new xmldb_key('serviceuserid_fk', XMLDB_KEY_FOREIGN, ['serviceuserid'], 'user', ['id']);
        $dbman->add_key($table, $key);

        upgrade_plugin_savepoint(true, 2026092404, 'local', 'nucleushub');
    }

    if ($oldversion < 2026092500) {
        // ADR-023 section 3: the hub signs people in to its spokes. One
        // OpenID Connect client per spoke, plus the short-lived
        // authorisation codes and access tokens. The signing key is made
        // on first use, not here.
        $tables = [
            'local_nucleushub_oidc_client',
            'local_nucleushub_oidc_code',
            'local_nucleushub_oidc_token',
        ];
        foreach ($tables as $tablename) {
            $table = new xmldb_table($tablename);
            if (!$dbman->table_exists($table)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', $tablename);
            }
        }

        upgrade_plugin_savepoint(true, 2026092500, 'local', 'nucleushub');
    }

    if ($oldversion < 2026092501) {
        // Security review of sign in with the hub.
        // H2: the sub claim becomes a random identifier per user, kept
        // in its own table, instead of the user id (ids can come back
        // after a database restore).
        $table = new xmldb_table('local_nucleushub_oidc_subject');
        if (!$dbman->table_exists($table)) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_nucleushub_oidc_subject');
        }

        // L1: client secrets are stored as SHA-256, not bcrypt. No client
        // is in production yet, so every client goes, with its codes and
        // tokens. Nucleus registers them again when sign-in is turned on.
        $DB->delete_records('local_nucleushub_oidc_code');
        $DB->delete_records('local_nucleushub_oidc_token');
        $DB->delete_records('local_nucleushub_oidc_client');

        upgrade_plugin_savepoint(true, 2026092501, 'local', 'nucleushub');
    }

    return true;
}
