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

namespace local_nucleuscommon\external;

use core_external\external_api;
use local_nucleuscommon\task\record_usage;

/**
 * The usage history web service: bucketing, the since filter, validation and
 * the services it is on.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(get_usage_history::class)]
final class get_usage_history_test extends \advanced_testcase {

    /**
     * Store one sample.
     *
     * @param int $time When it was taken.
     * @param int $users Accounts.
     * @param int $online People online.
     * @param int $active24h People active in the last 24 hours.
     * @return void
     */
    private function sample(int $time, int $users, int $online, int $active24h): void {
        global $DB;
        $DB->insert_record(record_usage::TABLE, (object) [
            'timecreated' => $time,
            'users' => $users,
            'online' => $online,
            'active24h' => $active24h,
        ]);
    }

    /**
     * Call the web service and check its return against the declared structure.
     *
     * @param int $since Unix time of the earliest sample to include.
     * @param int $step Bucket size in seconds.
     * @return array The samples.
     */
    private function history(int $since, int $step): array {
        return external_api::clean_returnvalue(
            get_usage_history::execute_returns(),
            get_usage_history::execute($since, $step)
        )['samples'];
    }

    /**
     * One sample per bucket, in time order, with the highest of each figure in it.
     */
    public function test_one_sample_per_bucket_with_the_highest_values(): void {
        $this->resetAfterTest();
        // The start of a 4-hour bucket about a day ago, so every step's buckets line up with it.
        $base = intdiv(time() - DAYSECS, 14400) * 14400;

        // Stored out of time order: the result is in time order all the same.
        $this->sample($base + 3 * HOURSECS + 10, 14, 0, 7);
        $this->sample($base + 1200, 12, 4, 3);
        $this->sample($base + HOURSECS + 600, 13, 2, 6);
        $this->sample($base + 60, 10, 1, 5);

        // Hourly: the first hour's figures come from different rows. Nothing in
        // the third hour, so no sample for it.
        $this->assertSame([
            ['time' => $base, 'users' => 12, 'online' => 4, 'active24h' => 5],
            ['time' => $base + HOURSECS, 'users' => 13, 'online' => 2, 'active24h' => 6],
            ['time' => $base + 3 * HOURSECS, 'users' => 14, 'online' => 0, 'active24h' => 7],
        ], $this->history($base, HOURSECS));

        // Every 10 minutes: each row is in a bucket of its own.
        $this->assertSame([
            ['time' => $base, 'users' => 10, 'online' => 1, 'active24h' => 5],
            ['time' => $base + 1200, 'users' => 12, 'online' => 4, 'active24h' => 3],
            ['time' => $base + HOURSECS + 600, 'users' => 13, 'online' => 2, 'active24h' => 6],
            ['time' => $base + 3 * HOURSECS, 'users' => 14, 'online' => 0, 'active24h' => 7],
        ], $this->history($base, 600));

        // Every 4 hours: one bucket with the highest of everything.
        $this->assertSame([
            ['time' => $base, 'users' => 14, 'online' => 4, 'active24h' => 7],
        ], $this->history($base, 14400));
    }

    /**
     * Only rows taken at or after since count, even within a bucket that starts before it.
     */
    public function test_only_rows_from_since_on(): void {
        $this->resetAfterTest();
        $base = intdiv(time() - DAYSECS, HOURSECS) * HOURSECS;
        $since = $base + 600;

        $this->sample($base - 60, 99, 99, 99);
        $this->sample($base + 60, 50, 50, 50);
        $this->sample($since, 11, 5, 2);
        $this->sample($base + 1200, 12, 4, 3);

        // The bucket still starts on the hour; the row at exactly since is in.
        $this->assertSame([
            ['time' => $base, 'users' => 12, 'online' => 5, 'active24h' => 3],
        ], $this->history($since, HOURSECS));
    }

    /**
     * No samples, no buckets.
     */
    public function test_nothing_recorded_yet(): void {
        $this->resetAfterTest();
        $this->assertSame([], $this->history(time() - DAYSECS, 600));
    }

    /**
     * Only the three steps the control plane uses are accepted.
     */
    public function test_rejects_a_step_it_does_not_offer(): void {
        foreach ([0, -600, 60, 300, 1800, 7200, 86400] as $step) {
            try {
                get_usage_history::execute(time() - HOURSECS, $step);
                $this->fail('Allowed step ' . $step);
            } catch (\invalid_parameter_exception $e) {
                $this->assertStringContainsString('step must be 600, 3600 or 14400', $e->debuginfo);
            }
        }
    }

    /**
     * since may reach back 31 days and no further.
     */
    public function test_rejects_since_more_than_31_days_ago(): void {
        foreach ([0, time() - 32 * DAYSECS, time() - 31 * DAYSECS - HOURSECS] as $since) {
            try {
                get_usage_history::execute($since, 14400);
                $this->fail('Allowed since ' . $since);
            } catch (\invalid_parameter_exception $e) {
                $this->assertStringContainsString('since must be no more than 31 days ago', $e->debuginfo);
            }
        }

        // 30 days back, as the control plane asks for its 30-day range, is fine.
        $this->assertSame([], $this->history(time() - 30 * DAYSECS, 14400));
    }

    /**
     * The function is on the same services as get_tenant_stats: the control-plane ones.
     */
    public function test_on_the_same_services_as_get_tenant_stats(): void {
        global $DB;
        $sql = "SELECT s.shortname
                  FROM {external_services_functions} sf
                  JOIN {external_services} s ON s.id = sf.externalserviceid
                 WHERE sf.functionname = :functionname
              ORDER BY s.shortname";
        $stats = $DB->get_fieldset_sql($sql, ['functionname' => 'local_nucleuscommon_get_tenant_stats']);
        if (!$stats) {
            $this->markTestSkipped('The hub and spoke plugins, which own the control-plane services, aren\'t installed.');
        }

        $this->assertSame($stats, $DB->get_fieldset_sql($sql, ['functionname' => 'local_nucleuscommon_get_usage_history']));
    }
}
