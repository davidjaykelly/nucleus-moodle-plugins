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

/**
 * External function: local_nucleuscommon_get_usage_history.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleuscommon\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * The people figures the record_usage task took, for the usage graphs in
 * the Nucleus portal.
 *
 * One sample per `step`-second bucket that has any rows from `since` on,
 * with the highest value of each figure in the bucket. Buckets with no rows
 * are left out; the control plane shows them as gaps. Like get_tenant_stats,
 * it is only on the control-plane services.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_usage_history extends external_api {
    /** @var int[] The bucket sizes the control plane uses, in seconds: 10 minutes, 1 hour and 4 hours. */
    public const STEPS = [600, 3600, 14400];

    /** @var int How far back `since` may reach, in seconds: 31 days. */
    public const MAX_AGE = 31 * DAYSECS;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'since' => new external_value(PARAM_INT, 'Unix time of the earliest sample to include, at most 31 days ago.'),
            'step' => new external_value(PARAM_INT, 'Bucket size in seconds: 600, 3600 or 14400.'),
        ]);
    }

    /**
     * The samples from `since` on, bucketed by `step`.
     *
     * @param int $since Unix time of the earliest sample to include.
     * @param int $step Bucket size in seconds.
     * @return array{samples: array<int, array{time: int, users: int, online: int, active24h: int}>}
     * @throws \invalid_parameter_exception If `step` isn't offered or `since` is more than 31 days ago.
     */
    public static function execute(int $since, int $step): array {
        global $DB;
        [
            'since' => $since,
            'step' => $step,
        ] = self::validate_parameters(self::execute_parameters(), ['since' => $since, 'step' => $step]);

        if (!in_array($step, self::STEPS, true)) {
            throw new \invalid_parameter_exception('step must be 600, 3600 or 14400 seconds.');
        }
        if ($since < time() - self::MAX_AGE) {
            throw new \invalid_parameter_exception('since must be no more than 31 days ago.');
        }

        // The step is one of three known integers, so it goes into the SQL as
        // a literal: the SELECT and GROUP BY expressions must be identical,
        // which bound parameters wouldn't be on every database.
        $bucket = "FLOOR(timecreated / {$step}) * {$step}";
        $sql = "SELECT {$bucket} AS bucket,
                       MAX(users) AS users,
                       MAX(online) AS online,
                       MAX(active24h) AS active24h
                  FROM {local_nucleuscommon_usage}
                 WHERE timecreated >= :since
              GROUP BY {$bucket}
              ORDER BY bucket ASC";

        $samples = [];
        $rows = $DB->get_recordset_sql($sql, ['since' => $since]);
        foreach ($rows as $row) {
            $samples[] = [
                'time' => (int) $row->bucket,
                'users' => (int) $row->users,
                'online' => (int) $row->online,
                'active24h' => (int) $row->active24h,
            ];
        }
        $rows->close();

        return ['samples' => $samples];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'samples' => new external_multiple_structure(
                new external_single_structure([
                    'time' => new external_value(PARAM_INT, 'Start of the bucket, as a Unix time.'),
                    'users' => new external_value(PARAM_INT, 'Most confirmed users in the bucket (excludes guest and deleted).'),
                    'online' => new external_value(PARAM_INT, 'Most users online (seen in the last 5 minutes) in the bucket.'),
                    'active24h' => new external_value(PARAM_INT, 'Most users seen in the last 24 hours in the bucket.'),
                ])
            ),
        ]);
    }
}
