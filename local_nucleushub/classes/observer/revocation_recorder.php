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
 * Observer for hub-initiated shadow user deletions.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleushub\observer;

use local_nucleuscommon\events\publisher;

defined('MOODLE_INTERNAL') || die();

/**
 * Phase B1 Step 5 — GDPR cascade, hub→spoke direction.
 *
 * Fires on `\core\event\user_deleted` for any user that has a
 * matching `local_nucleushub_projusers` row. Deletes the projusers
 * row and publishes `mode_b_user_revoked.v1` with `direction: 'hub'`.
 * CP fans out to the originating spoke so its mirror enrolments
 * on Mode B placeholder courses get unenrolled.
 *
 * Skipped when:
 *   - The user wasn't a shadow (no projusers row).
 *   - The projusers row is already gone (revoke_user.php already
 *     handled the cleanup; spoke-initiated path's delete_user
 *     fires this event too, and we mustn't double-publish).
 */
class revocation_recorder {

    /**
     * @param \core\event\user_deleted $event
     * @return void
     */
    public static function handle(\core\event\user_deleted $event): void {
        global $DB;

        $hubuserid = (int)$event->objectid;
        if ($hubuserid <= 0) {
            return;
        }

        $row = $DB->get_record('local_nucleushub_projusers', ['hubuserid' => $hubuserid]);
        if (!$row) {
            // Either a local-only hub user (no federation impact)
            // or revoke_user.php already deleted the row before
            // calling delete_user — both are fine to ignore.
            return;
        }

        $spoke = $DB->get_record('local_nucleushub_spokes',
            ['id' => $row->spokeid], '*', IGNORE_MISSING);

        $DB->delete_records('local_nucleushub_projusers', ['id' => $row->id]);

        if (!$spoke || empty($spoke->cpspokeid)) {
            return;
        }

        try {
            publisher::publish(
                'mode_b_user_revoked.v1',
                'hub',
                'cp',
                [
                    'cp_spoke_id'    => (string)$spoke->cpspokeid,
                    'spoke_id'       => (int)$spoke->id,
                    'spoke_name'     => (string)$spoke->name,
                    'spoke_user_id'  => (int)$row->spokeuserid,
                    'hub_user_id'    => $hubuserid,
                    'spoke_username' => (string)($row->spokeusername ?? ''),
                    'spoke_email'    => (string)($row->spokeemail ?? ''),
                    'direction'      => 'hub',
                    'revoked_at'     => time(),
                ]
            );
        } catch (\Throwable $e) {
            debugging('Nucleus: hub-initiated revocation publish failed: ' . $e->getMessage(),
                DEBUG_DEVELOPER);
        }
    }
}
