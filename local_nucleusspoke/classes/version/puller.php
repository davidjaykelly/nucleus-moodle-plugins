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
 * Pull orchestration for course versioning (ADR-014 Phase 1).
 *
 * Downloads a course-version snapshot from the control plane,
 * restores it as a new local Moodle course, and records the
 * instance row tying local course ↔ (family, version). Mirrors
 * hub-side family/version rows on the spoke so subsequent queries
 * are local. Synchronous in Phase 1.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

namespace local_nucleusspoke\version;

use local_nucleuscommon\transport\cp_client;
use local_nucleuscommon\events\publisher as event_publisher;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/backup/util/includes/restore_includes.php');
require_once($GLOBALS['CFG']->dirroot . '/course/lib.php');

/**
 * Orchestrates pulling a specific version of a family from CP blob
 * storage into a new local course. Stateless — static methods.
 */
class puller {

    /**
     * Pull a version. Creates a new local Moodle course and an
     * instance row linking (family, version, local course).
     *
     * @param array $family DTO: ['guid', 'slug', 'hubfederationid'].
     * @param array $version DTO: ['guid', 'versionnumber', 'severity', 'snapshotref', 'snapshothash', 'hubcourseid', 'timepublished', 'releasenotes' (optional)].
     * @param int|null $targetcategoryid Category to restore into. Defaults to top-level (id=1).
     * @param int $userid Acting user id (the puller).
     * @param bool $staging When true, the restored course is made
     *                      invisible to students and the instance
     *                      is recorded with state='staging'.
     *                      Promote via promoter::promote_instance.
     * @return array ['instanceid', 'localcourseid', 'familyid', 'versionid', 'timepulled', 'state'].
     * @throws \moodle_exception
     */
    public static function pull(
        array $family,
        array $version,
        ?int $targetcategoryid,
        int $userid,
        bool $staging = false
    ): array {
        global $CFG, $DB, $USER;

        $familyrow = registry::upsert_family($family);
        $versionrow = registry::upsert_version($version, $familyrow->id);

        $mbzpath = self::download_snapshot($versionrow);
        try {
            $backupdir = self::extract_backup($mbzpath);
            try {
                $newcourseid = self::restore_into_new_course(
                    $backupdir,
                    $family,
                    $version,
                    $userid,
                    self::resolve_target_category($targetcategoryid)
                );
            } finally {
                // Restore consumes the extracted tree; clean up whatever's left.
                $fulldir = $CFG->tempdir . '/backup/' . $backupdir;
                if (is_dir($fulldir)) {
                    remove_dir($fulldir);
                }
            }
        } finally {
            if (file_exists($mbzpath)) {
                @unlink($mbzpath);
            }
        }

        // Staging pulls hide the restored course from students until
        // an operator promotes it. Teachers still see it via their
        // course edit role — perfect for "kick the tyres before
        // making it live" workflows.
        if ($staging) {
            course_change_visibility((int) $newcourseid, false);
        }

        $now = time();
        $instanceid = $DB->insert_record('local_nucleusspoke_instance', (object) [
            'familyid' => $familyrow->id,
            'versionid' => $versionrow->id,
            'localcourseid' => $newcourseid,
            'state' => $staging ? 'staging' : 'active',
            'timepulled' => $now,
            'pulledbyid' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        // Staging pulls shouldn't resolve notifications — the operator
        // hasn't committed yet. Only a live pull (or a promote, later)
        // clears the "pending" banner.
        if (!$staging) {
            self::resolve_notifications($familyrow->id, $versionrow->id, $userid, $now);
        }

        // Broadcast the pull on the shared event stream so the CP
        // can record an audit row. Best-effort — a stalled Redis
        // mustn't fail a successful pull.
        try {
            $spokeid = (string) (get_config('local_nucleuscommon', 'federationid') ?: 'unknown');
            event_publisher::publish(
                'course_instance_pulled.v1',
                'spoke:' . $spokeid,
                'broadcast',
                [
                    'familyguid' => $familyrow->guid,
                    'familyslug' => $familyrow->slug,
                    'hubfederationid' => $familyrow->hubfederationid,
                    'versionguid' => $versionrow->guid,
                    'versionnumber' => $versionrow->versionnumber,
                    'severity' => $versionrow->severity,
                    'localcourseid' => (int) $newcourseid,
                    'instanceid' => (int) $instanceid,
                    'pulledbyid' => $userid,
                    'timepulled' => $now,
                ]
            );
        } catch (\Throwable $emiterr) {
            debugging(
                'course_instance_pulled event emission failed: ' . $emiterr->getMessage(),
                DEBUG_NORMAL
            );
        }

        return [
            'instanceid' => (int) $instanceid,
            'localcourseid' => (int) $newcourseid,
            'familyid' => (int) $familyrow->id,
            'versionid' => (int) $versionrow->id,
            'timepulled' => $now,
            'state' => $staging ? 'staging' : 'active',
        ];
    }

    /**
     * Download the .mbz from CP into a temp file, verify the hash.
     *
     * @param \stdClass $versionrow Version row (has snapshotref, snapshothash).
     * @return string Local path to the .mbz.
     * @throws \moodle_exception
     */
    private static function download_snapshot(\stdClass $versionrow): string {
        $mbzpath = tempnam(sys_get_temp_dir(), 'nucleus-snapshot-');
        if ($mbzpath === false) {
            // Pass the message as $a so Moodle substitutes it into
            // the {$a} placeholder in the `pullfailed` lang string.
            // Keep the same text in $debuginfo for log/dev-mode
            // display — same content, two surfaces.
            throw new \moodle_exception(
                'pullfailed',
                'local_nucleusspoke',
                '',
                'could not allocate temp file for snapshot',
                'could not allocate temp file for snapshot'
            );
        }
        try {
            $path = '/course-versions/' . rawurlencode($versionrow->guid) . '/snapshot';
            cp_client::from_config()->get_to_file($path, $mbzpath);
            $got = hash_file('sha256', $mbzpath);
            if ($got !== $versionrow->snapshothash) {
                throw new \moodle_exception(
                    'snapshothashmismatch',
                    'local_nucleusspoke',
                    '',
                    (object) [
                        'expected' => $versionrow->snapshothash,
                        'got' => $got,
                    ]
                );
            }
            return $mbzpath;
        } catch (\Throwable $e) {
            @unlink($mbzpath);
            throw $e;
        }
    }

    /**
     * Extract the .mbz into Moodle's backup staging directory and
     * return the directory name (relative to $CFG->tempdir/backup).
     *
     * @param string $mbzpath Path to the .mbz.
     * @return string Relative dirname the restore_controller expects.
     * @throws \moodle_exception
     */
    private static function extract_backup(string $mbzpath): string {
        global $CFG, $USER;

        $backupdir = \restore_controller::get_tempdir_name(SITEID, $USER->id);
        $targetdir = $CFG->tempdir . '/backup/' . $backupdir;
        check_dir_exists($targetdir, true, true);
        $packer = get_file_packer('application/vnd.moodle.backup');
        if (!$packer->extract_to_pathname($mbzpath, $targetdir)) {
            $msg = 'failed to extract MBZ into ' . $targetdir;
            throw new \moodle_exception('pullfailed', 'local_nucleusspoke', '', $msg, $msg);
        }
        return $backupdir;
    }

    /**
     * Create a fresh course and restore the extracted backup into
     * it. Returns the new course id.
     *
     * @param string $backupdir Relative backup directory name.
     * @param array $family Family DTO (for slug / display derivation).
     * @param array $version Version DTO (for labelling).
     * @param int $userid Acting user id.
     * @return int New course id.
     * @throws \moodle_exception
     */
    private static function restore_into_new_course(
        string $backupdir,
        array $family,
        array $version,
        int $userid,
        int $categoryid
    ): int {
        $shortname = self::uniquify_shortname(
            $family['slug'] . '-v' . $version['versionnumber']
        );
        $fullname = $family['slug'] . ' (v' . $version['versionnumber'] . ')';
        $newcourseid = \restore_dbops::create_new_course($fullname, $shortname, $categoryid);

        $rc = new \restore_controller(
            $backupdir,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $userid,
            \backup::TARGET_NEW_COURSE
        );
        try {
            if (!$rc->execute_precheck()) {
                $warnings = $rc->get_precheck_results();
                $msg = 'restore precheck failed: ' . json_encode($warnings);
                throw new \moodle_exception('pullfailed', 'local_nucleusspoke', '', $msg, $msg);
            }
            // Override the names baked into the backup MBZ so the
            // local course identifies the family + version, not the
            // hub course's at-publish-time fullname (which is often
            // noisy, e.g. mid-edit titles). create_new_course already
            // wrote our preferred names; the restore plan will
            // otherwise stomp them. set_value before execute_plan.
            $plan = $rc->get_plan();
            if ($plan->setting_exists('course_fullname')) {
                $plan->get_setting('course_fullname')->set_value($fullname);
            }
            if ($plan->setting_exists('course_shortname')) {
                $plan->get_setting('course_shortname')->set_value($shortname);
            }
            $rc->execute_plan();
        } finally {
            $rc->destroy();
        }
        return (int) $newcourseid;
    }

    /**
     * Any notification for this (family, version) is now resolved
     * by the pull. Idempotent — a re-pull is a no-op here.
     *
     * @param int $familyid
     * @param int $versionid
     * @param int $userid
     * @param int $now
     */
    private static function resolve_notifications(
        int $familyid,
        int $versionid,
        int $userid,
        int $now
    ): void {
        global $DB;
        $rows = $DB->get_records(
            'local_nucleusspoke_notification',
            ['familyid' => $familyid, 'versionid' => $versionid]
        );
        foreach ($rows as $row) {
            if ($row->state === 'resolved') {
                continue;
            }
            $DB->update_record('local_nucleusspoke_notification', (object) [
                'id' => $row->id,
                'state' => 'resolved',
                'timeresolved' => $now,
                'resolvedbyid' => $userid,
            ]);
        }
    }

    /**
     * Ensure shortname uniqueness. Moodle enforces it at the DB
     * level; collisions would surface as generic errors. Mirrors
     * the existing copy_locally pattern.
     *
     * @param string $base
     * @return string
     */
    private static function uniquify_shortname(string $base): string {
        global $DB;
        $candidate = $base;
        $i = 2;
        while ($DB->record_exists('course', ['shortname' => $candidate])) {
            $candidate = $base . '-' . $i;
            $i++;
        }
        return $candidate;
    }

    /**
     * Resolve the category id pulled courses get restored into.
     *
     * The previous behaviour hardcoded `1` (Moodle's "Miscellaneous"
     * default). That breaks on any Moodle whose categories have been
     * reorganised — Misc may have been renamed, deleted, or its id
     * may not match. New behaviour:
     *
     *   1. If the caller passed an explicit id and it exists, use it.
     *   2. Otherwise find or create a "Nucleus federation" category
     *      and use that.
     *
     * Putting federated courses in their own category is also nicer
     * organisationally than mixing them into the spoke's local Misc.
     *
     * @param int|null $explicit Caller-supplied target. Validated for
     *                           existence; falls through if missing.
     * @return int A guaranteed-valid `course_categories.id`.
     */
    private static function resolve_target_category(?int $explicit): int {
        global $DB;
        if ($explicit !== null && $DB->record_exists('course_categories', ['id' => $explicit])) {
            return $explicit;
        }
        // Find by idnumber so renames don't break the lookup. The
        // idnumber is plugin-scoped and unlikely to clash.
        $existing = $DB->get_record('course_categories', ['idnumber' => 'nucleus_federation']);
        if ($existing) {
            return (int) $existing->id;
        }
        $created = \core_course_category::create([
            'name' => 'Nucleus federation',
            'idnumber' => 'nucleus_federation',
            'description' => 'Courses pulled from a Nucleus federation hub. Managed automatically — restored versions land here unless an explicit category is set on pull.',
        ]);
        return (int) $created->id;
    }

}
