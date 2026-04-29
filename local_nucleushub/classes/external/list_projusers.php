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
 * External function: local_nucleushub_list_projusers.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleushub\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only listing of projected users (Mode B shadow users) for the
 * Nucleus portal's Identity surfaces (Phase B1 Step 4). Returns one
 * row per projusers record with the denormalised spoke-side fields,
 * plus per-shadow-user enrolment + completion counts so the portal
 * can show "alice has 3 hub courses, completed 2" without a second
 * round-trip.
 *
 * Filter:
 *   - `cpspokeid` (optional, default '') restricts to a single spoke
 *     keyed by the CP-side cuid (`local_nucleushub_spokes.cpspokeid`).
 *     Empty string returns the federation-wide list.
 *
 * Performance: per-shadow counts are computed via two grouped
 * subqueries; for ≤ low-thousands of projusers per hub this is
 * sub-100ms. If a hub grows past that, a materialised view or
 * cached counts are the next move.
 */
class list_projusers extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cpspokeid' => new external_value(
                PARAM_RAW,
                'Optional CP Spoke.id filter (cuid). Empty = all spokes.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * @return array{rows: array}
     */
    public static function execute(string $cpspokeid = ''): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cpspokeid' => $cpspokeid,
        ]);
        $cpspokeid = trim($params['cpspokeid']);

        // Filter by spokes table when cpspokeid was given. Empty
        // filter → cross-spoke listing.
        $spokewhere = '';
        $spokeparams = [];
        if ($cpspokeid !== '') {
            $spokewhere = ' AND s.cpspokeid = :cpspokeid';
            $spokeparams['cpspokeid'] = $cpspokeid;
        }

        $sql = "
            SELECT pu.id,
                   pu.spokeid,
                   s.cpspokeid,
                   s.name AS spokename,
                   pu.spokeuserid,
                   pu.hubuserid,
                   pu.spokeusername,
                   pu.spokeemail,
                   pu.timecreated,
                   COALESCE(ec.cnt, 0) AS enrolmentcount,
                   COALESCE(cc.cnt, 0) AS completioncount
              FROM {local_nucleushub_projusers} pu
              JOIN {local_nucleushub_spokes} s ON s.id = pu.spokeid
              LEFT JOIN (
                  SELECT ue.userid, COUNT(DISTINCT e.courseid) AS cnt
                    FROM {user_enrolments} ue
                    JOIN {enrol} e ON e.id = ue.enrolid
                   GROUP BY ue.userid
              ) ec ON ec.userid = pu.hubuserid
              LEFT JOIN (
                  SELECT userid, COUNT(*) AS cnt
                    FROM {course_completions}
                   WHERE timecompleted IS NOT NULL
                   GROUP BY userid
              ) cc ON cc.userid = pu.hubuserid
             WHERE 1=1 $spokewhere
             ORDER BY pu.timecreated DESC
        ";

        $rows = $DB->get_records_sql($sql, $spokeparams);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'              => (int)$r->id,
                'spokeid'         => (int)$r->spokeid,
                'cpspokeid'       => (string)($r->cpspokeid ?? ''),
                'spokename'       => (string)$r->spokename,
                'spokeuserid'     => (int)$r->spokeuserid,
                'hubuserid'       => (int)$r->hubuserid,
                'spokeusername'   => (string)$r->spokeusername,
                'spokeemail'      => (string)$r->spokeemail,
                'timecreated'     => (int)$r->timecreated,
                'enrolmentcount'  => (int)$r->enrolmentcount,
                'completioncount' => (int)$r->completioncount,
            ];
        }

        return ['rows' => $out];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'rows' => new external_multiple_structure(
                new external_single_structure([
                    'id'              => new external_value(PARAM_INT, 'projusers row id.'),
                    'spokeid'         => new external_value(PARAM_INT, 'Hub-local spoke id.'),
                    'cpspokeid'       => new external_value(PARAM_RAW, 'CP Spoke.id (cuid).'),
                    'spokename'       => new external_value(PARAM_TEXT, 'Spoke name from local_nucleushub_spokes.'),
                    'spokeuserid'     => new external_value(PARAM_INT, 'User id on the spoke side.'),
                    'hubuserid'       => new external_value(PARAM_INT, 'Shadow mdl_user.id on the hub.'),
                    'spokeusername'   => new external_value(PARAM_TEXT, 'Denormalised username.'),
                    'spokeemail'      => new external_value(PARAM_TEXT, 'Denormalised email.'),
                    'timecreated'     => new external_value(PARAM_INT, 'Unix time of first projection.'),
                    'enrolmentcount'  => new external_value(PARAM_INT, 'Distinct hub courses this shadow is enrolled in.'),
                    'completioncount' => new external_value(PARAM_INT, 'Hub-side completions for this shadow.'),
                ])
            ),
        ]);
    }
}
