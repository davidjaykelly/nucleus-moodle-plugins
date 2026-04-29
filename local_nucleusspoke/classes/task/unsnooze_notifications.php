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
 * Scheduled task: move snoozed notifications back to pending when
 * their `snoozeuntil` timestamp has passed (ADR-014 Phase 2).
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

namespace local_nucleusspoke\task;

use local_nucleusspoke\version\notifications;

defined('MOODLE_INTERNAL') || die();

class unsnooze_notifications extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task_unsnooze_notifications', 'local_nucleusspoke');
    }

    public function execute(): void {
        $count = notifications::unsnooze_due();
        if ($count > 0) {
            mtrace("local_nucleusspoke: un-snoozed {$count} notification(s).");
        }
    }
}
