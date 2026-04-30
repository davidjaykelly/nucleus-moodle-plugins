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
 * External function: local_nucleushub_describe_version.
 *
 * ADR-021 Tier A — return the dependency manifest of a published
 * course version *without* serving the .mbz. The spoke calls this
 * before downloading so it can refuse a pull cleanly when its plugin
 * set or Moodle major version doesn't match what the backup needs.
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

defined('MOODLE_INTERNAL') || die();

/**
 * Return the manifest JSON for a published version.
 */
class describe_version extends external_api {

    /**
     * Describe the expected input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'versionguid' => new external_value(
                PARAM_ALPHANUMEXT,
                'Guid of the published version to describe.'
            ),
        ]);
    }

    /**
     * Look up the manifest for a published version.
     *
     * @param string $versionguid
     * @return array Wire payload — see execute_returns.
     * @throws \moodle_exception
     */
    public static function execute(string $versionguid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'versionguid' => $versionguid,
        ]);

        $row = $DB->get_record(
            'local_nucleuscommon_version',
            ['guid' => $params['versionguid']],
            'id, guid, versionnumber, hubcourseid, manifest, deprecated, deprecatedreason, timepublished',
            IGNORE_MISSING
        );
        if (!$row) {
            // Distinct error so the spoke can decide between "wait, we
            // haven't synced this version yet" and "the hub doesn't
            // know this guid at all". For now both surface the same
            // way; spokes that want a richer story can pivot off the
            // error string.
            throw new \moodle_exception(
                'versionnotfound',
                'local_nucleushub',
                '',
                $params['versionguid']
            );
        }

        // Manifest column was added in ADR-021 (2026-04-30). Versions
        // published before that have NULL manifests; the spoke treats
        // null as "no manifest, allow pull but log Tier C note". We
        // surface that explicitly so the spoke doesn't have to
        // distinguish between "valid empty manifest" and "we never
        // had a manifest for this version".
        $manifestjson = (string) ($row->manifest ?? '');
        $hasmanifest = $manifestjson !== '';

        return [
            'versionguid' => (string) $row->guid,
            'versionnumber' => (string) $row->versionnumber,
            'hubcourseid' => (int) $row->hubcourseid,
            'deprecated' => (int) $row->deprecated === 1,
            'deprecatedreason' => (string) ($row->deprecatedreason ?? ''),
            'timepublished' => (int) $row->timepublished,
            'hasmanifest' => $hasmanifest,
            // Manifest is returned as opaque JSON text so callers don't
            // have to commit to a fixed wire schema before we know
            // which Tier C fields will land. Spoke parses on receipt.
            'manifest' => $manifestjson,
        ];
    }

    /**
     * Describe the return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'versionguid' => new external_value(PARAM_ALPHANUMEXT, 'Echo of the requested guid.'),
            'versionnumber' => new external_value(PARAM_TEXT, 'Semver-lite version number, e.g. 1.2.0.'),
            'hubcourseid' => new external_value(PARAM_INT, 'mdl_course.id this snapshot was taken from.'),
            'deprecated' => new external_value(PARAM_BOOL, 'Whether the hub has flagged this version deprecated.'),
            'deprecatedreason' => new external_value(PARAM_RAW, 'Free-text reason; empty when not deprecated.'),
            'timepublished' => new external_value(PARAM_INT, 'Unix time of publish completion.'),
            'hasmanifest' => new external_value(PARAM_BOOL, 'False for legacy rows published pre-ADR-021.'),
            'manifest' => new external_value(PARAM_RAW, 'JSON-encoded dependency manifest, or empty string when hasmanifest=false.'),
        ]);
    }
}
