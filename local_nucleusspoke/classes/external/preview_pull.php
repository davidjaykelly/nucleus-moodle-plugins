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
 * External function: local_nucleusspoke_preview_pull.
 *
 * ADR-021 v1.1 — runs the Tier A pre-flight check against this
 * spoke's environment for a hub version *without* pulling. Used by
 * the operator portal's "Check compatibility" affordance so the
 * operator can fix dependencies before clicking Pull rather than
 * after.
 *
 * Capability: local/nucleusspoke:pull (same as pull_version — if you
 * can't pull, you don't need to know whether you could).
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
use local_nucleusspoke\client\hub_client;
use local_nucleusspoke\version\manifest_checker;

defined('MOODLE_INTERNAL') || die();

/**
 * Returns the structured manifest_checker result for a given
 * version, without side effects. Same shape as the throw-time
 * payload, but explicit so callers don't have to parse a
 * moodle_exception.
 */
class preview_pull extends external_api {

    /**
     * Describe the expected input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'versionguid' => new external_value(
                PARAM_ALPHANUMEXT,
                'Guid of the published version to pre-flight.'
            ),
        ]);
    }

    /**
     * Run the pre-flight + return blockers + notes.
     *
     * @param string $versionguid
     * @return array
     */
    public static function execute(string $versionguid): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'versionguid' => $versionguid,
        ]);

        // Hub round-trip is the same describe call the live pull uses.
        // Not catching here: if the hub is unreachable, surface the
        // error to the caller — preview's value is the operator
        // knowing the situation, and "couldn't reach hub" is itself
        // useful information.
        $describe = hub_client::default()->describe_version($params['versionguid']);

        $check = manifest_checker::check($describe);

        return [
            'versionguid' => $params['versionguid'],
            'manifest_status' => $check['manifest_status'],
            'pullable' => empty($check['blockers']),
            'blockers' => array_map(
                fn ($b) => [
                    'kind' => (string) ($b['kind'] ?? ''),
                    'detail' => (string) ($b['detail'] ?? ''),
                    'remediation' => (string) ($b['remediation'] ?? ''),
                ],
                $check['blockers']
            ),
            'notes' => array_map(fn ($n) => (string) $n, $check['notes']),
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
            'manifest_status' => new external_value(
                PARAM_ALPHA,
                'present | missing | malformed | extractor_error'
            ),
            'pullable' => new external_value(
                PARAM_BOOL,
                'True iff blockers is empty — operator can click Pull and expect success.'
            ),
            'blockers' => new external_multiple_structure(new external_single_structure([
                'kind' => new external_value(PARAM_ALPHANUMEXT, 'Machine-readable blocker kind.'),
                'detail' => new external_value(PARAM_RAW, 'Human-readable description.'),
                'remediation' => new external_value(PARAM_RAW, 'What the operator should do.'),
            ])),
            'notes' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Tier C observation; informational, not blocking.')
            ),
        ]);
    }
}
