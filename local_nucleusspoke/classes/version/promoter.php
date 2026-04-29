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
 * Staging → active promotion (ADR-014 Phase 2).
 *
 * Flips a staging instance to active, makes the underlying course
 * visible to students, resolves any pending notifications for the
 * family + version, and emits a pulled-event on the stream so the
 * CP records the transition in the audit log the same way a live
 * pull would.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

namespace local_nucleusspoke\version;

use local_nucleuscommon\events\publisher as event_publisher;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/course/lib.php');

class promoter {

    /**
     * Promote a staging instance to active.
     *
     * @param int $instanceid local_nucleusspoke_instance.id
     * @param int $userid Acting user (for audit fields).
     * @return \stdClass Updated instance row.
     * @throws \moodle_exception
     */
    public static function promote_instance(int $instanceid, int $userid): \stdClass {
        global $DB;

        $row = $DB->get_record(
            'local_nucleusspoke_instance',
            ['id' => $instanceid],
            '*',
            MUST_EXIST
        );
        if ($row->state !== 'staging') {
            throw new \moodle_exception(
                'instancenotstaging',
                'local_nucleusspoke',
                '',
                $row->state
            );
        }

        $family = $DB->get_record(
            'local_nucleuscommon_family',
            ['id' => $row->familyid],
            '*',
            MUST_EXIST
        );
        $version = $DB->get_record(
            'local_nucleuscommon_version',
            ['id' => $row->versionid],
            '*',
            MUST_EXIST
        );

        $now = time();
        course_change_visibility((int) $row->localcourseid, true);
        $DB->update_record('local_nucleusspoke_instance', (object) [
            'id' => $row->id,
            'state' => 'active',
            'timemodified' => $now,
        ]);

        // Clear any pending notifications for this family/version —
        // promotion is the final decision, not a "skip".
        $DB->execute(
            "UPDATE {local_nucleusspoke_notification}
                SET state = 'resolved',
                    timeresolved = :now,
                    resolvedbyid = :uid
              WHERE familyid = :fid AND versionid = :vid
                AND state IN ('pending', 'snoozed')",
            ['now' => $now, 'uid' => $userid, 'fid' => $row->familyid, 'vid' => $row->versionid]
        );

        // Reuse the existing pulled event so CP audits the promote
        // the same way as a direct pull. Best-effort.
        try {
            $spokeid = (string) (get_config('local_nucleuscommon', 'federationid') ?: 'unknown');
            event_publisher::publish(
                'course_instance_pulled.v1',
                'spoke:' . $spokeid,
                'broadcast',
                [
                    'familyguid' => $family->guid,
                    'familyslug' => $family->slug,
                    'hubfederationid' => $family->hubfederationid,
                    'versionguid' => $version->guid,
                    'versionnumber' => $version->versionnumber,
                    'severity' => $version->severity,
                    'localcourseid' => (int) $row->localcourseid,
                    'instanceid' => (int) $row->id,
                    'pulledbyid' => $userid,
                    'timepulled' => (int) $row->timepulled,
                    // Signals this row is a staging→active transition
                    // rather than a fresh pull. Audit consumers can
                    // key off this if they want to distinguish.
                    'op' => 'promote',
                ]
            );
        } catch (\Throwable $emiterr) {
            debugging(
                'course_instance_pulled (promote) emission failed: ' . $emiterr->getMessage(),
                DEBUG_NORMAL
            );
        }

        return $DB->get_record(
            'local_nucleusspoke_instance',
            ['id' => $instanceid],
            '*',
            MUST_EXIST
        );
    }
}
