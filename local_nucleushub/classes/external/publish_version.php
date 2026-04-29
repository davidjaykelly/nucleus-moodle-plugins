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
 * External function: local_nucleushub_publish_version.
 *
 * Publishes a new version of a course family (ADR-014 Phase 1).
 * Synchronous — runs a Moodle backup, uploads the `.mbz` to the
 * control plane, records the version row, and returns version
 * metadata to the caller.
 *
 * Capability: local/nucleushub:publish (contextsystem).
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

namespace local_nucleushub\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_nucleushub\version\publisher;

defined('MOODLE_INTERNAL') || die();

/**
 * Publishes a snapshot of a hub course as a new version of a
 * course family.
 */
class publish_version extends external_api {

    /**
     * Describe the expected input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'hubcourseid' => new external_value(PARAM_INT, 'mdl_course.id of the course being published.'),
            'severity' => new external_value(PARAM_ALPHA, 'One of: major, minor, patch.'),
            'releasenotes' => new external_value(
                PARAM_RAW,
                'Author-written notes. Required for minor/major; optional for patch.',
                VALUE_DEFAULT,
                ''
            ),
            'familyguid' => new external_value(
                PARAM_ALPHANUMEXT,
                'Optional — publish into this existing family. Omit to auto-resolve or create on first publish.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Run the publish flow.
     *
     * @param int $hubcourseid
     * @param string $severity
     * @param string $releasenotes
     * @param string $familyguid
     * @return array Version metadata per publisher::publish().
     * @throws \moodle_exception
     */
    public static function execute(
        int $hubcourseid,
        string $severity,
        string $releasenotes,
        string $familyguid
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'hubcourseid' => $hubcourseid,
            'severity' => $severity,
            'releasenotes' => $releasenotes,
            'familyguid' => $familyguid,
        ]);

        // Auth: db/services.php declares 'local/nucleushub:publish'
        // as the required capability — Moodle checks it against the
        // token's user before invoking us. An in-function
        // validate_context adds nothing and raises 'User not fully
        // set-up' against service-account users with incomplete
        // profile fields. Skipped.

        $guid = $params['familyguid'] === '' ? null : $params['familyguid'];
        $notes = $params['releasenotes'] === '' ? null : $params['releasenotes'];

        return publisher::publish(
            (int) $params['hubcourseid'],
            (string) $params['severity'],
            $notes,
            $guid,
            (int) $USER->id
        );
    }

    /**
     * Describe the return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'familyguid' => new external_value(PARAM_ALPHANUMEXT, 'Family guid (created on first publish).'),
            'versionguid' => new external_value(PARAM_ALPHANUMEXT, 'Guid of the newly published version.'),
            'versionnumber' => new external_value(PARAM_TEXT, 'Semver-lite string, e.g. 1.2.0.'),
            'snapshotref' => new external_value(PARAM_TEXT, 'Opaque blob reference, e.g. local://{guid}.mbz.'),
            'snapshothash' => new external_value(PARAM_ALPHANUMEXT, 'SHA-256 hex of the snapshot.'),
            'size' => new external_value(PARAM_INT, 'Snapshot size in bytes.'),
            'timepublished' => new external_value(PARAM_INT, 'Unix time of publish completion.'),
        ]);
    }
}
