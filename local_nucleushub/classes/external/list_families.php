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
 * External function: local_nucleushub_list_families (ADR-014 Phase 1).
 *
 * Returns all course families known to this hub with their full
 * version history. Used by the control-plane portal to populate
 * the per-federation "Published families" panel.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

namespace local_nucleushub\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

class list_families extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * @return array List of families with versions.
     */
    public static function execute(): array {
        global $DB;

        // Auth is enforced by the service declaration
        // ('local/nucleushub:publish'). No in-function
        // validate_context — see receive_notification.php for the
        // rationale against the service-account user gotcha.

        $families = $DB->get_records('local_nucleuscommon_family', null, 'slug ASC');
        if (!$families) {
            return [];
        }

        $familyids = array_map(fn($f) => (int) $f->id, array_values($families));
        [$insql, $params] = $DB->get_in_or_equal($familyids);
        $versions = $DB->get_records_select(
            'local_nucleuscommon_version',
            "familyid {$insql}",
            $params,
            'familyid ASC, timepublished ASC'
        );

        $versionsbyfamily = [];
        foreach ($versions as $v) {
            $versionsbyfamily[$v->familyid][] = [
                'guid' => (string) $v->guid,
                'versionnumber' => (string) $v->versionnumber,
                'severity' => (string) $v->severity,
                'snapshotref' => (string) ($v->snapshotref ?? ''),
                'snapshothash' => (string) ($v->snapshothash ?? ''),
                'hubcourseid' => (int) $v->hubcourseid,
                'timepublished' => (int) $v->timepublished,
                'releasenotes' => (string) ($v->releasenotes ?? ''),
                'deprecated' => (bool) $v->deprecated,
                'deprecatedreason' => (string) ($v->deprecatedreason ?? ''),
            ];
        }

        $out = [];
        foreach ($families as $f) {
            // Courseid + current working course metadata help the
            // portal link into the hub Moodle when the operator
            // clicks a family.
            $draft = $DB->get_record(
                'local_nucleushub_draft',
                ['familyid' => $f->id]
            );
            $hubcourseid = $draft ? (int) $draft->hubcourseid : 0;
            $hubcourse = $hubcourseid
                ? $DB->get_record('course', ['id' => $hubcourseid], 'id, shortname, fullname')
                : null;

            $out[] = [
                'guid' => (string) $f->guid,
                'slug' => (string) $f->slug,
                'hubfederationid' => (string) $f->hubfederationid,
                'catalogvisible' => (bool) $f->catalogvisible,
                'timecreated' => (int) $f->timecreated,
                'hubcourseid' => $hubcourseid,
                'hubcourseshortname' => (string) ($hubcourse->shortname ?? ''),
                'hubcoursefullname' => (string) ($hubcourse->fullname ?? ''),
                'pendingchangecount' => (int) ($draft->pendingchangecount ?? 0),
                'versions' => $versionsbyfamily[$f->id] ?? [],
            ];
        }

        return $out;
    }

    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'guid' => new external_value(PARAM_ALPHANUMEXT, 'Family guid (UUID).'),
            'slug' => new external_value(PARAM_TEXT, 'Human-readable slug.'),
            'hubfederationid' => new external_value(PARAM_ALPHANUMEXT, 'Control-plane federation id.'),
            'catalogvisible' => new external_value(PARAM_BOOL, 'Listed in the federation catalog browser.'),
            'timecreated' => new external_value(PARAM_INT, 'Unix time of family creation.'),
            'hubcourseid' => new external_value(PARAM_INT, 'Working hub mdl_course.id backing this family (0 if no draft).'),
            'hubcourseshortname' => new external_value(PARAM_TEXT, 'Working course shortname.'),
            'hubcoursefullname' => new external_value(PARAM_TEXT, 'Working course fullname.'),
            'pendingchangecount' => new external_value(PARAM_INT, 'Changelog entries since last publish.'),
            'versions' => new external_multiple_structure(new external_single_structure([
                'guid' => new external_value(PARAM_ALPHANUMEXT, 'Version guid.'),
                'versionnumber' => new external_value(PARAM_TEXT, 'Semver-lite number.'),
                'severity' => new external_value(PARAM_ALPHA, 'major | minor | patch.'),
                'snapshotref' => new external_value(PARAM_TEXT, 'Blob reference, empty if upload pending/failed.'),
                'snapshothash' => new external_value(PARAM_ALPHANUMEXT, 'SHA-256 hex, empty if not finalised.'),
                'hubcourseid' => new external_value(PARAM_INT, 'Hub course id at publish time.'),
                'timepublished' => new external_value(PARAM_INT, 'Unix time of publish.'),
                'releasenotes' => new external_value(PARAM_RAW, 'Author-written notes.'),
                'deprecated' => new external_value(PARAM_BOOL, 'Hub-side deprecation flag.'),
                'deprecatedreason' => new external_value(PARAM_TEXT, 'Human reason if deprecated.'),
            ])),
        ]));
    }
}
