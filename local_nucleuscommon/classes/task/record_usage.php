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
 * Record this site's people figures for the usage graphs in the Nucleus portal.
 *
 * Every 10 minutes it stores how many people have accounts, how many are
 * online now and how many were active in the last 24 hours, then deletes
 * samples older than 90 days. The control plane reads them back through
 * local_nucleuscommon_get_usage_history. Counts only: no one is identified.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class record_usage extends \core\task\scheduled_task {
    /** @var string The table the samples go in. */
    public const TABLE = 'local_nucleuscommon_usage';

    /** @var int How long a sample is kept, in seconds: 90 days. */
    public const KEEP = 90 * DAYSECS;

    /** @var int Moodle's "online users" window, in seconds. */
    public const ONLINE_WINDOW = 300;

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_record_usage', 'local_nucleuscommon');
    }

    /**
     * Record one sample and delete the ones older than 90 days.
     *
     * @return void
     */
    public function execute() {
        global $DB;
        $now = time();

        $DB->insert_record(self::TABLE, (object) (['timecreated' => $now] + self::counts($now)));
        $DB->delete_records_select(self::TABLE, 'timecreated < :cutoff', ['cutoff' => $now - self::KEEP]);
    }

    /**
     * The people figures at a moment.
     *
     * `users` and `active24h` use the same rules as get_tenant_stats, so the
     * graphs agree with the tiles above them.
     *
     * @param int $now The moment, as a Unix time.
     * @return array{users: int, online: int, active24h: int}
     */
    public static function counts(int $now): array {
        global $DB;

        $users = $DB->count_records_select(
            'user',
            'deleted = 0 AND username <> :guest AND confirmed = 1',
            ['guest' => 'guest'],
        );
        $online = $DB->count_records_select(
            'user',
            'deleted = 0 AND username <> :guest AND lastaccess > :cutoff',
            ['guest' => 'guest', 'cutoff' => $now - self::ONLINE_WINDOW],
        );
        $active24h = $DB->count_records_select(
            'user',
            'deleted = 0 AND username <> :guest AND lastaccess > :cutoff',
            ['guest' => 'guest', 'cutoff' => $now - DAYSECS],
        );

        return [
            'users' => (int) $users,
            'online' => (int) $online,
            'active24h' => (int) $active24h,
        ];
    }
}
