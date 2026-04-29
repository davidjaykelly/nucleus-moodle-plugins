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
 * Observer relaying spoke-side user deletions to the hub.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleusspoke\observer;

use local_nucleusspoke\client\hub_client;

defined('MOODLE_INTERNAL') || die();

/**
 * Phase B1 Step 5 — GDPR cascade, spoke→hub direction.
 *
 * On `\core\event\user_deleted` we call the hub's
 * `local_nucleushub_revoke_user` so any shadow user + projusers
 * row is cleaned up. Always-on rather than mode-gated: cheap
 * idempotent no-op (`removed=false`) when the user wasn't a
 * Mode B participant, vs. silently leaving stale PII on the hub
 * if the federation later turns out to be Mode B.
 *
 * Failure policy mirrors `enrolment_mirror`: log and continue.
 * Compensating retries land in a future operational pass —
 * Phase B1's audit trail (CP `mode_b_user_revoked` row) is the
 * minimum we need for ops to spot a missing cleanup.
 */
class revocation_relay {

    /**
     * @param \core\event\user_deleted $event
     * @return void
     */
    public static function handle(\core\event\user_deleted $event): void {
        $spokeuserid = (int)$event->objectid;
        if ($spokeuserid <= 0) {
            return;
        }

        // Skip if the spoke isn't configured to talk to a hub yet
        // — auto-config wires this at provision time, but a stub
        // install (tests, fresh dev image) might not have it.
        $hubwwwroot = (string)(get_config('local_nucleusspoke', 'hubwwwroot') ?: '');
        $hubtoken = (string)(get_config('local_nucleusspoke', 'hubtoken') ?: '');
        if ($hubwwwroot === '' || $hubtoken === '') {
            return;
        }

        try {
            $client = hub_client::default();
            $client->revoke_user($spokeuserid);
        } catch (\Throwable $e) {
            debugging('Nucleus: revocation_relay failed for user=' . $spokeuserid .
                ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
