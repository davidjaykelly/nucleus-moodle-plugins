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
 * Interface for mode-specific federation behaviour.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleuscommon\mode;

defined('MOODLE_INTERNAL') || die();

/**
 * Strategy interface for the two federation modes.
 *
 * Hub and spoke plugins route mode-sensitive work through {@see dispatcher}
 * which resolves the active strategy from plugin config. That keeps the
 * bulk of the plugin code mode-agnostic and localises differences here.
 *
 * Phase 0 method set is minimal — we add more hooks as Day 3-8 work
 * surfaces a need. Signatures favour arrays over value objects in Phase 0
 * for speed of iteration; Phase 1 will introduce typed DTOs.
 */
interface strategy {

    /**
     * Short machine-readable name for the mode ('content' or 'identity').
     *
     * @return string
     */
    public function name(): string;

    /**
     * Handle a spoke user's intent to enrol in a hub-provided course.
     *
     * In Mode A this is a no-op — the spoke enrols the user in its own local
     * copy as normal Moodle enrolment. In Mode B this projects the user to
     * the hub and calls hub_request_enrolment before allowing the local
     * enrolment to proceed.
     *
     * @param int $userid Local (spoke) user id.
     * @param int $courseid Local (spoke) course id — the one the user tried to enrol in.
     * @return void
     */
    public function handle_spoke_enrolment_intent(int $userid, int $courseid): void;

    /**
     * Handle an inbound completion event on the spoke.
     *
     * In Mode A completion events are not produced (hub doesn't see learners).
     * In Mode B the hub emits completion.v1 and the spoke applies it locally.
     *
     * @param array $envelope The full event envelope (see publisher for shape).
     * @return void
     */
    public function handle_completion_event(array $envelope): void;
}
