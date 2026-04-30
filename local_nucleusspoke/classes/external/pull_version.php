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
 * External function: local_nucleusspoke_pull_version.
 *
 * Pulls a specific version of a hub-published course family onto
 * this spoke — downloads the snapshot from the control plane,
 * restores it as a new local course, and records an instance row.
 * Family + version metadata is supplied by the caller so the spoke
 * doesn't need to round-trip the hub for it.
 *
 * Capability: local/nucleusspoke:pull (contextsystem).
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

namespace local_nucleusspoke\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_nucleusspoke\version\puller;

defined('MOODLE_INTERNAL') || die();

class pull_version extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'family' => new external_single_structure([
                'guid' => new external_value(PARAM_ALPHANUMEXT, 'Family guid (UUID).'),
                'slug' => new external_value(PARAM_TEXT, 'Human-readable slug for display.'),
                'hubfederationid' => new external_value(PARAM_ALPHANUMEXT, 'Control-plane federation id the family originates from.'),
            ], 'Course-family descriptor.'),
            'version' => new external_single_structure([
                'guid' => new external_value(PARAM_ALPHANUMEXT, 'Version guid (UUID).'),
                'versionnumber' => new external_value(PARAM_TEXT, 'Semver-lite version number.'),
                'severity' => new external_value(PARAM_ALPHA, 'major | minor | patch.'),
                'snapshotref' => new external_value(PARAM_TEXT, 'Opaque blob reference for the .mbz in CP storage.'),
                'snapshothash' => new external_value(PARAM_ALPHANUMEXT, 'SHA-256 hex of the .mbz for integrity check.'),
                'hubcourseid' => new external_value(PARAM_INT, 'mdl_course.id the version was snapshotted from on the hub.'),
                'timepublished' => new external_value(PARAM_INT, 'Unix time the version was published on the hub.'),
                'releasenotes' => new external_value(
                    PARAM_RAW,
                    'Author-written release notes, if any.',
                    VALUE_DEFAULT,
                    ''
                ),
                'lockedforspokeedit' => new external_value(
                    PARAM_BOOL,
                    'Hub published with the spoke-edit lock — spoke applies CAP_PREVENT overrides + UI lock at restore.',
                    VALUE_DEFAULT,
                    false
                ),
            ], 'Version descriptor.'),
            'targetcategoryid' => new external_value(
                PARAM_INT,
                'Local course category to restore into. Defaults to top-level (1).',
                VALUE_DEFAULT,
                1
            ),
            'staging' => new external_value(
                PARAM_BOOL,
                'When true, restored course is hidden from students; instance state=staging until promoted.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * @param array $family
     * @param array $version
     * @param int $targetcategoryid
     * @param bool $staging
     * @return array Instance metadata from puller::pull().
     */
    public static function execute(
        array $family,
        array $version,
        int $targetcategoryid,
        bool $staging = false
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'family' => $family,
            'version' => $version,
            'targetcategoryid' => $targetcategoryid,
            'staging' => $staging,
        ]);

        // Auth is enforced by the service-level `capabilities`
        // declaration + the token's user. See receive_notification
        // for the full rationale — same skip for the same reason.

        return puller::pull(
            $params['family'],
            $params['version'],
            (int) $params['targetcategoryid'],
            (int) $USER->id,
            (bool) $params['staging']
        );
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'instanceid' => new external_value(PARAM_INT, 'Local local_nucleusspoke_instance.id.'),
            'localcourseid' => new external_value(PARAM_INT, 'mdl_course.id of the restored course.'),
            'familyid' => new external_value(PARAM_INT, 'Local local_nucleuscommon_family.id (upserted).'),
            'versionid' => new external_value(PARAM_INT, 'Local local_nucleuscommon_version.id (upserted).'),
            'timepulled' => new external_value(PARAM_INT, 'Unix time the pull completed.'),
            'state' => new external_value(PARAM_ALPHAEXT, 'Instance state: active (default) or staging.'),
        ]);
    }
}
