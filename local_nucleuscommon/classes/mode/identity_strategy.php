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
 * Mode B (identity federation) strategy.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleuscommon\mode;

defined('MOODLE_INTERNAL') || die();

/**
 * Identity federation: hub owns authoritative user records and enrolments
 * for hub-hosted courses. The spoke is a branded surface; its enrolment
 * flow projects users to the hub and asks the hub to enrol them.
 *
 * Phase 0 methods are stubs — real implementations land in Day 6-8 when
 * the spoke's enrolment observer and event consumer are wired.
 */
class identity_strategy implements strategy {

    public function name(): string {
        return 'identity';
    }

    /**
     * Phase 0: hub-projection logic lands in local_nucleusspoke's enrolment
     * observer. This method is a planned injection point for the dispatch
     * path; the observer will call it via the dispatcher.
     */
    public function handle_spoke_enrolment_intent(int $userid, int $courseid): void {
        // TODO (Day 7): project_user → request_enrolment via hub_client.
    }

    /**
     * Phase 0: completion handling lands in local_nucleusspoke's consumer
     * CLI. This method is a planned injection point for applying the
     * decoded envelope's payload to the local gradebook.
     */
    public function handle_completion_event(array $envelope): void {
        // TODO (Day 8): resolve spoke_user_id → course_id, mark complete.
    }
}
