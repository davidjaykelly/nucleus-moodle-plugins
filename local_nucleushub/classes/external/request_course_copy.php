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
 * External function: local_nucleushub_request_course_copy.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleushub\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

/**
 * Mode A: generate an MBZ backup of a hub course and make it available
 * for the spoke to fetch over HTTP via the download endpoint.
 *
 * Phase 0/1 shape: the MBZ lands in `$CFG->dataroot/nucleushub_backups/`
 * on the hub's moodledata volume. The `backup_url` field in the response
 * is the bare filename; the spoke builds the full download URL using
 * its known hub wwwroot + its own WS token. Phase 6 replaces this with
 * S3-backed signed URLs — the field name stays, the value becomes an
 * absolute URL.
 *
 * Synchronous. A real-sized course takes seconds to minutes; Phase 1+ moves
 * this to an async job queue so the WS call returns immediately with a
 * job id the spoke can poll.
 */
class request_course_copy extends external_api {

    /** Backup storage directory under $CFG->dataroot (never exposed as-is). */
    const BACKUPS_SUBDIR = 'nucleushub_backups';

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Hub course id to back up'),
        ]);
    }

    /**
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        global $CFG, $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(),
            ['courseid' => $courseid]);
        $courseid = $params['courseid'];

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

        if ($courseid === (int)SITEID) {
            throw new \moodle_exception('invalidcourseid', 'error');
        }
        if (!$DB->record_exists('course', ['id' => $courseid])) {
            throw new \moodle_exception('invalidcourseid', 'error');
        }

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $courseid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );

        try {
            $bc->execute_plan();
            $results = $bc->get_results();

            if (empty($results['backup_destination']) || !($results['backup_destination'] instanceof \stored_file)) {
                throw new \moodle_exception('backupfailed', 'local_nucleushub', '', null,
                    'backup_controller returned no stored_file for course ' . $courseid);
            }

            /** @var \stored_file $storedfile */
            $storedfile = $results['backup_destination'];

            // Use Moodle's own helper — creates the directory under dataroot
            // with the right ownership/permissions for apache to write.
            $reldir = self::BACKUPS_SUBDIR;
            if (!make_upload_directory($reldir)) {
                throw new \moodle_exception('backupfailed', 'local_nucleushub', '', null,
                    'could not create ' . $CFG->dataroot . '/' . $reldir);
            }
            $basedir = rtrim($CFG->dataroot, '/') . '/' . $reldir;

            // Stable per-course filename; Phase 1 will switch to content-addressed paths.
            $filename = sprintf('course-%d.mbz', $courseid);
            $targetpath = $basedir . '/' . $filename;

            if (!$storedfile->copy_content_to($targetpath)) {
                throw new \moodle_exception('backupfailed', 'local_nucleushub', '', null,
                    'copy_content_to failed writing ' . $targetpath);
            }

            $size = filesize($targetpath) ?: 0;

            return [
                'status'     => 'ready',
                'backup_url' => $filename,
                'size_bytes' => (int)$size,
            ];
        } finally {
            $bc->destroy();
        }
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'     => new external_value(PARAM_ALPHANUMEXT, 'ready | pending | failed'),
            'backup_url' => new external_value(PARAM_FILE, 'Filename the spoke can GET from `/local/nucleushub/download.php?wstoken=…&file=…` (Phase 1). Becomes a signed S3 URL in Phase 6.', VALUE_OPTIONAL),
            'size_bytes' => new external_value(PARAM_INT, 'MBZ size in bytes', VALUE_OPTIONAL),
        ]);
    }
}
