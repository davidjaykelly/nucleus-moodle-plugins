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
 * Mode A action: pull a hub course's MBZ and restore locally.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleusspoke\action;

use local_nucleusspoke\client\hub_client;

defined('MOODLE_INTERNAL') || die();

/**
 * Orchestrates: request backup on hub → HTTP-fetch MBZ to local temp →
 * restore into a new local course → record the mapping.
 */
class copy_locally {

    /**
     * Run the copy. Creates a new local course, restores the MBZ into it,
     * and inserts a row in local_nucleusspoke_courses.
     *
     * @param int $hubcourseid Course id on the hub.
     * @return array{status: string, localcourseid: int, message: string}
     */
    public static function run(int $hubcourseid): array {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        // If we've already copied this hub course, update in place rather
        // than create a duplicate local course.
        $existing = $DB->get_record('local_nucleusspoke_courses',
            ['hubcourseid' => $hubcourseid, 'mode' => 'content']);
        if ($existing && $DB->record_exists('course', ['id' => $existing->localcourseid])) {
            return [
                'status'        => 'already_copied',
                'localcourseid' => (int)$existing->localcourseid,
                'message'       => 'Local copy already exists — resync is a Phase 1 feature.',
            ];
        }

        $client = hub_client::default();
        $hubcourses = $client->list_courses();
        $hubcourse = null;
        foreach ($hubcourses as $c) {
            if ((int)$c['id'] === $hubcourseid) { $hubcourse = $c; break; }
        }
        if (!$hubcourse) {
            throw new \moodle_exception('huberror', 'local_nucleuscommon', '', "Hub course {$hubcourseid} not offered for federation.",
                "Hub course {$hubcourseid} not offered for federation.");
        }

        // Ask the hub to produce a backup.
        $response = $client->request_course_copy($hubcourseid);
        if (($response['status'] ?? '') !== 'ready' || empty($response['backup_url'])) {
            $msg = 'Hub returned non-ready status: ' . json_encode($response);
            throw new \moodle_exception('huberror', 'local_nucleuscommon', '', $msg, $msg);
        }

        // HTTP-fetch the MBZ into a local tempfile, then extract.
        $mbzpath = tempnam(sys_get_temp_dir(), 'nucleus-mbz-');
        try {
            if (!$client->fetch_backup($response['backup_url'], $mbzpath)) {
                throw new \moodle_exception('huberror', 'local_nucleuscommon', '', 'MBZ download failed for ' . $response['backup_url'],
                    'MBZ download failed for ' . $response['backup_url']);
            }

            // Extract into Moodle's backup temp area.
            $backupdir = \restore_controller::get_tempdir_name(SITEID, $USER->id);
            $targetdir = $CFG->tempdir . '/backup/' . $backupdir;
            check_dir_exists($targetdir, true, true);
            $packer = get_file_packer('application/vnd.moodle.backup');
            if (!$packer->extract_to_pathname($mbzpath, $targetdir)) {
                throw new \moodle_exception('huberror', 'local_nucleuscommon', '', 'Failed to extract MBZ into ' . $targetdir,
                    'Failed to extract MBZ into ' . $targetdir);
            }
        } finally {
            @unlink($mbzpath);
        }

        // Create an empty target course to restore into. Same category
        // resolution as puller.php — find/create a "Nucleus federation"
        // category by idnumber so we don't blow up on Moodles whose
        // Miscellaneous (id=1) has been renamed or deleted.
        $shortname = self::uniquify_shortname('fed_' . ($hubcourse['shortname'] ?? 'course'));
        $fullname = 'Federated: ' . ($hubcourse['fullname'] ?? "Course {$hubcourseid}");
        $categoryid = self::resolve_target_category();
        $newcourseid = \restore_dbops::create_new_course($fullname, $shortname, $categoryid);

        // Run the restore.
        $rc = new \restore_controller(
            $backupdir,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        try {
            // Same user-data stripping as puller.php — federation pulls
            // never carry users/roles/files/logs across; they belong to
            // the spoke. Without this the hub's admin user clashes with
            // the spoke's admin and precheck fails.
            $plan = $rc->get_plan();
            foreach (['users', 'role_assignments', 'user_files', 'logs', 'comments', 'badges', 'grade_histories'] as $key) {
                if ($plan->setting_exists($key)) {
                    $plan->get_setting($key)->set_value(false);
                }
            }
            // Per ADR-021 — only `errors` should block; `warnings`
            // (e.g. backup-from-newer-Moodle compatibility note) are
            // non-blocking and just need surfacing to the operator.
            $rc->execute_precheck();
            $precheck = $rc->get_precheck_results();
            if (!empty($precheck['errors'])) {
                $msg = 'Restore precheck failed: ' . json_encode($precheck);
                throw new \moodle_exception('huberror', 'local_nucleuscommon', '', $msg, $msg);
            }
            if (!empty($precheck['warnings'])) {
                debugging(
                    'Nucleus pull warnings (proceeding anyway): '
                    . json_encode($precheck['warnings']),
                    DEBUG_NORMAL
                );
            }
            $rc->execute_plan();
        } finally {
            $rc->destroy();
        }

        $now = time();
        $DB->insert_record('local_nucleusspoke_courses', (object) [
            'localcourseid' => $newcourseid,
            'hubcourseid'   => $hubcourseid,
            'mode'          => 'content',
            'timesynced'    => $now,
            'timecreated'   => $now,
        ]);

        return [
            'status'        => 'copied',
            'localcourseid' => (int)$newcourseid,
            'message'       => "Copied to local course id {$newcourseid}.",
        ];
    }

    /**
     * Ensure we don't collide with an existing course shortname. Moodle
     * requires shortname uniqueness.
     *
     * @param string $base
     * @return string
     */
    private static function uniquify_shortname(string $base): string {
        global $DB;
        $candidate = $base;
        $i = 2;
        while ($DB->record_exists('course', ['shortname' => $candidate])) {
            $candidate = $base . '_' . $i;
            $i++;
        }
        return $candidate;
    }

    /**
     * Find or create the "Nucleus federation" category and return its
     * id. Mirrors puller::resolve_target_category — kept duplicated
     * (rather than shared via local_nucleuscommon) so each path stays
     * readable in isolation; if a third caller appears, fold this
     * into nucleuscommon as a static helper.
     */
    private static function resolve_target_category(): int {
        global $DB;
        $existing = $DB->get_record('course_categories', ['idnumber' => 'nucleus_federation']);
        if ($existing) {
            return (int) $existing->id;
        }
        $created = \core_course_category::create([
            'name' => 'Nucleus federation',
            'idnumber' => 'nucleus_federation',
            'description' => 'Courses pulled from a Nucleus federation hub.',
        ]);
        return (int) $created->id;
    }
}
