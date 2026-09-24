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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_nucleuscommon\task;

/**
 * The usage sample: its schedule, its people figures and the 90-day pruning.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(record_usage::class)]
final class record_usage_test extends \advanced_testcase {

    /**
     * Make a user, then set these fields directly, as the counts read them.
     *
     * @param array $fields User fields to set, such as lastaccess.
     * @return void
     */
    private function user(array $fields): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $DB->update_record('user', (object) (['id' => $user->id] + $fields));
    }

    /**
     * The task runs every 10 minutes.
     */
    public function test_runs_every_10_minutes(): void {
        $task = \core\task\manager::get_scheduled_task(record_usage::class);
        $this->assertInstanceOf(record_usage::class, $task);
        $this->assertSame('*/10', $task->get_minute());
        $this->assertSame('*', $task->get_hour());
    }

    /**
     * One row per run: accounts, people online and people active in the last 24 hours.
     */
    public function test_records_the_people_figures(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();

        // The admin account, never seen, and the guest account, seen just now.
        $DB->set_field('user', 'lastaccess', 0);
        $DB->set_field('user', 'lastaccess', $now - 60, ['username' => 'guest']);

        $this->user(['lastaccess' => $now - 60]);
        $this->user(['lastaccess' => $now - 120]);
        $this->user(['lastaccess' => $now - 600]);
        $this->user(['lastaccess' => $now - 2 * DAYSECS]);
        $this->user(['lastaccess' => 0]);
        $this->user(['confirmed' => 0, 'lastaccess' => 0]);
        $this->user(['deleted' => 1, 'lastaccess' => $now - 60]);

        (new record_usage())->execute();

        $rows = $DB->get_records(record_usage::TABLE);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertGreaterThanOrEqual($now, (int) $row->timecreated);
        $this->assertLessThanOrEqual(time(), (int) $row->timecreated);
        // The admin account and the five confirmed accounts that aren't deleted.
        $this->assertSame(6, (int) $row->users);
        // Seen in the last 5 minutes: 1 and 2 minutes ago. Not the guest or the deleted account.
        $this->assertSame(2, (int) $row->online);
        // Seen in the last 24 hours: those two and the one seen 10 minutes ago.
        $this->assertSame(3, (int) $row->active24h);
    }

    /**
     * Samples older than 90 days go; newer ones stay.
     */
    public function test_deletes_samples_older_than_90_days(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $figures = ['users' => 1, 'online' => 0, 'active24h' => 0];
        $old = $DB->insert_record(record_usage::TABLE, (object) (['timecreated' => $now - 91 * DAYSECS] + $figures));
        $kept = $DB->insert_record(record_usage::TABLE, (object) (['timecreated' => $now - 89 * DAYSECS] + $figures));
        $recent = $DB->insert_record(record_usage::TABLE, (object) (['timecreated' => $now - 600] + $figures));

        (new record_usage())->execute();

        $this->assertFalse($DB->record_exists(record_usage::TABLE, ['id' => $old]));
        $this->assertTrue($DB->record_exists(record_usage::TABLE, ['id' => $kept]));
        $this->assertTrue($DB->record_exists(record_usage::TABLE, ['id' => $recent]));
        // The two kept and the one this run recorded.
        $this->assertSame(3, $DB->count_records(record_usage::TABLE));
    }
}
