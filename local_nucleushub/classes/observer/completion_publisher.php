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
 * Observer that publishes completion events to the federation event stream.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleushub\observer;

use local_nucleuscommon\events\publisher;

defined('MOODLE_INTERNAL') || die();

/**
 * Translates Moodle's `\core\event\course_completed` into a `completion.v1`
 * envelope on the shared Redis stream, but only for users that were
 * projected from a spoke — local-only users on the hub do not produce
 * federation events.
 *
 * Also mirrors each published envelope into `local_nucleushub_events` so
 * the hub has its own audit log independent of the stream's retention.
 *
 * Publishing here is non-internal (registered with `'internal' => false` in
 * events.php) so it runs *after* the database transaction that committed
 * the completion. That keeps the stream from carrying events that Moodle
 * then rolls back.
 */
class completion_publisher {

    /**
     * Handle a course_completed event.
     *
     * @param \core\event\course_completed $event
     * @return void
     */
    public static function handle(\core\event\course_completed $event): void {
        global $DB;

        // Identify the user who completed. Different Moodle versions have
        // populated userid vs relateduserid inconsistently — check both.
        $userid = (int)($event->relateduserid ?: $event->userid);
        $courseid = (int)$event->courseid;
        if ($userid <= 0 || $courseid <= 0) {
            return;
        }

        $mapping = $DB->get_record('local_nucleushub_projusers', ['hubuserid' => $userid]);
        if (!$mapping) {
            // Local-only hub user — not a federation concern.
            return;
        }

        $spoke = $DB->get_record('local_nucleushub_spokes',
            ['id' => $mapping->spokeid], '*', IGNORE_MISSING);
        if (!$spoke) {
            debugging('Nucleus: projuser row ' . $mapping->id .
                ' references missing spoke ' . $mapping->spokeid, DEBUG_DEVELOPER);
            return;
        }

        $payload = [
            'hub_user_id'    => (int)$mapping->hubuserid,
            'spoke_id'       => (int)$spoke->id,
            'spoke_name'     => (string)$spoke->name,
            // Phase B1: CP-mediated routing keys off cp_spoke_id —
            // empty string when the spoke pre-dates the cpspokeid
            // backfill (the next register_spoke from CP fills it in).
            'cp_spoke_id'    => (string)($spoke->cpspokeid ?? ''),
            'spoke_user_id'  => (int)$mapping->spokeuserid,
            'course_id'      => $courseid,
            'completed_at'   => (int)$event->timecreated,
        ];

        try {
            $ids = publisher::publish(
                'completion.v1',
                'hub',
                'spoke:' . $spoke->name,
                $payload
            );

            $DB->insert_record('local_nucleushub_events', (object) [
                'envelopeid'  => $ids['envelope_id'],
                'streamid'    => $ids['stream_id'],
                'type'        => 'completion.v1',
                'destination' => 'spoke:' . $spoke->name,
                'payload'     => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'timecreated' => time(),
            ]);
        } catch (\Throwable $e) {
            // Never let a federation publish failure bubble into the
            // course_completed event chain — the completion is already
            // committed on the hub. Log for ops to see.
            debugging('Nucleus: completion publish failed: ' . $e->getMessage(),
                DEBUG_DEVELOPER);
        }
    }
}
