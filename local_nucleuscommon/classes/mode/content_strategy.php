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
 * Mode A (content federation) strategy.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleuscommon\mode;

defined('MOODLE_INTERNAL') || die();

/**
 * Content federation: hub distributes course backups; spoke owns users,
 * enrolments, completions. Nothing about individual learners leaves the
 * spoke.
 */
class content_strategy implements strategy {

    public function name(): string {
        return 'content';
    }

    /**
     * In Mode A, spoke enrolments are purely local — no hub projection.
     * Normal Moodle enrolment continues without intervention.
     */
    public function handle_spoke_enrolment_intent(int $userid, int $courseid): void {
        // Intentionally empty: Mode A does not project users to the hub.
    }

    /**
     * Completion events are not part of the Mode A protocol. If one arrives
     * on a spoke configured for Mode A, it's a misconfiguration somewhere —
     * log and move on (the consumer ack will keep it out of the pending list).
     */
    public function handle_completion_event(array $envelope): void {
        debugging(
            'Nucleus: unexpected completion event in Mode A — envelope id ' .
                ($envelope['id'] ?? '?'),
            DEBUG_DEVELOPER
        );
    }
}
