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
 * External function: local_nucleusspoke_list_instances (ADR-014
 * Phase 1).
 *
 * Returns every pulled course instance on this spoke, plus pending
 * notification counts per family. Used by the CP portal to
 * populate the spoke-detail drawer's "Courses" tab.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

namespace local_nucleusspoke\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

class list_instances extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * @return array List of instances with family/version summaries.
     */
    public static function execute(): array {
        global $DB;

        // Auth via service declaration only — see pull_version.php.

        // Pulled instances joined against family+version for display.
        $rows = $DB->get_records_sql(
            "SELECT i.id AS instanceid, i.state, i.timepulled, i.pulledbyid,
                    i.localcourseid,
                    c.shortname AS localshortname, c.fullname AS localfullname,
                    f.id AS familyid, f.guid AS familyguid, f.slug AS familyslug,
                    f.hubfederationid,
                    v.guid AS versionguid, v.versionnumber, v.severity,
                    v.timepublished, v.releasenotes, v.deprecated
               FROM {local_nucleusspoke_instance} i
               JOIN {local_nucleuscommon_family} f ON f.id = i.familyid
               JOIN {local_nucleuscommon_version} v ON v.id = i.versionid
               JOIN {course} c ON c.id = i.localcourseid
              ORDER BY i.timepulled DESC"
        );

        if (!$rows) {
            return [];
        }

        // Pending notification counts per family — lets the caller
        // show "v1.1.0 available" alongside each instance without a
        // second round-trip.
        $pendingcounts = [];
        $familyids = array_unique(array_map(fn($r) => (int) $r->familyid, $rows));
        if ($familyids) {
            [$insql, $params] = $DB->get_in_or_equal($familyids);
            $sql = "SELECT familyid, COUNT(*) AS cnt
                      FROM {local_nucleusspoke_notification}
                     WHERE state = 'pending' AND familyid {$insql}
                     GROUP BY familyid";
            foreach ($DB->get_records_sql($sql, $params) as $row) {
                $pendingcounts[(int) $row->familyid] = (int) $row->cnt;
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'instanceid' => (int) $r->instanceid,
                'state' => (string) $r->state,
                'timepulled' => (int) $r->timepulled,
                'pulledbyid' => (int) $r->pulledbyid,
                'localcourseid' => (int) $r->localcourseid,
                'localshortname' => (string) $r->localshortname,
                'localfullname' => (string) $r->localfullname,
                'familyguid' => (string) $r->familyguid,
                'familyslug' => (string) $r->familyslug,
                'hubfederationid' => (string) $r->hubfederationid,
                'versionguid' => (string) $r->versionguid,
                'versionnumber' => (string) $r->versionnumber,
                'severity' => (string) $r->severity,
                'timepublished' => (int) $r->timepublished,
                'releasenotes' => (string) ($r->releasenotes ?? ''),
                'versiondeprecated' => (bool) $r->deprecated,
                'pendingforfamily' => (int) ($pendingcounts[(int) $r->familyid] ?? 0),
            ];
        }
        return $out;
    }

    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'instanceid' => new external_value(PARAM_INT, 'Local instance id.'),
            'state' => new external_value(PARAM_ALPHAEXT, 'active | closed-to-enrolment | archived | staging.'),
            'timepulled' => new external_value(PARAM_INT, 'Unix time of pull.'),
            'pulledbyid' => new external_value(PARAM_INT, 'Local user id that triggered the pull.'),
            'localcourseid' => new external_value(PARAM_INT, 'mdl_course.id of the restored course.'),
            'localshortname' => new external_value(PARAM_TEXT, 'Restored course shortname.'),
            'localfullname' => new external_value(PARAM_TEXT, 'Restored course fullname.'),
            'familyguid' => new external_value(PARAM_ALPHANUMEXT, 'Family guid.'),
            'familyslug' => new external_value(PARAM_TEXT, 'Family slug.'),
            'hubfederationid' => new external_value(PARAM_ALPHANUMEXT, 'Federation id.'),
            'versionguid' => new external_value(PARAM_ALPHANUMEXT, 'Version guid this instance is pinned to.'),
            'versionnumber' => new external_value(PARAM_TEXT, 'Semver-lite.'),
            'severity' => new external_value(PARAM_ALPHA, 'Version severity at publish time.'),
            'timepublished' => new external_value(PARAM_INT, 'Version publish time.'),
            'releasenotes' => new external_value(PARAM_RAW, 'Version release notes.'),
            'versiondeprecated' => new external_value(PARAM_BOOL, 'Hub marked this version deprecated.'),
            'pendingforfamily' => new external_value(PARAM_INT, 'Pending notifications for the same family (newer versions awaiting a decision).'),
        ]));
    }
}
