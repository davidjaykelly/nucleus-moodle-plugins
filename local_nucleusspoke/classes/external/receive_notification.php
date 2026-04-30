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
 * External function: local_nucleusspoke_receive_notification.
 *
 * Receiver for the CP fan-out of hub `course_version_published`
 * events (ADR-014 Step 4). Upserts the family + version rows on
 * this spoke and queues a `pending` notification. Idempotent —
 * the unique (familyid, versionid) index silently no-ops re-calls.
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
use local_nucleusspoke\version\registry;

defined('MOODLE_INTERNAL') || die();

class receive_notification extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'family' => new external_single_structure([
                'guid' => new external_value(PARAM_ALPHANUMEXT, 'Family guid.'),
                'slug' => new external_value(PARAM_TEXT, 'Human-readable slug.'),
                'hubfederationid' => new external_value(PARAM_ALPHANUMEXT, 'Control-plane federation id.'),
            ]),
            'version' => new external_single_structure([
                'guid' => new external_value(PARAM_ALPHANUMEXT, 'Version guid.'),
                'versionnumber' => new external_value(PARAM_TEXT, 'Semver-lite version number.'),
                'severity' => new external_value(PARAM_ALPHA, 'major | minor | patch.'),
                'snapshotref' => new external_value(PARAM_TEXT, 'Opaque blob reference for the .mbz.'),
                'snapshothash' => new external_value(PARAM_ALPHANUMEXT, 'SHA-256 hex of the .mbz.'),
                'hubcourseid' => new external_value(PARAM_INT, 'mdl_course.id on the hub.'),
                'timepublished' => new external_value(PARAM_INT, 'Unix time of publish.'),
                'releasenotes' => new external_value(
                    PARAM_RAW,
                    'Author-written release notes, if any.',
                    VALUE_DEFAULT,
                    ''
                ),
                'lockedforspokeedit' => new external_value(
                    PARAM_BOOL,
                    'Hub published with the spoke-edit lock — applied at restore time on subsequent pull.',
                    VALUE_DEFAULT,
                    false
                ),
            ]),
        ]);
    }

    /**
     * @param array $family
     * @param array $version
     * @return array
     */
    public static function execute(array $family, array $version): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'family' => $family,
            'version' => $version,
        ]);

        // Auth relies on the service-level `capabilities` declaration
        // in db/services.php + the token's user. No in-function
        // validate_context: the service-account user often has an
        // incomplete profile (no country/city set), which make
        // validate_context raise 'User not fully set-up'.

        $familyrow = registry::upsert_family($params['family']);
        $versionrow = registry::upsert_version($params['version'], (int) $familyrow->id);

        // Idempotent upsert on (familyid, versionid). The unique
        // index prevents duplicates; handle the race as a "already
        // queued" success.
        $existing = $DB->get_record(
            'local_nucleusspoke_notification',
            ['familyid' => $familyrow->id, 'versionid' => $versionrow->id]
        );
        if ($existing) {
            return [
                'notificationid' => (int) $existing->id,
                'state' => (string) $existing->state,
                'created' => false,
            ];
        }

        $now = time();
        try {
            $id = $DB->insert_record('local_nucleusspoke_notification', (object) [
                'familyid' => (int) $familyrow->id,
                'versionid' => (int) $versionrow->id,
                'state' => 'pending',
                'timereceived' => $now,
                'snoozeuntil' => null,
                'timeresolved' => null,
                'resolvedbyid' => null,
            ]);
        } catch (\dml_write_exception $e) {
            // Race on the unique index — re-read and treat as idempotent.
            $existing = $DB->get_record(
                'local_nucleusspoke_notification',
                ['familyid' => $familyrow->id, 'versionid' => $versionrow->id]
            );
            if ($existing) {
                return [
                    'notificationid' => (int) $existing->id,
                    'state' => (string) $existing->state,
                    'created' => false,
                ];
            }
            throw $e;
        }

        return [
            'notificationid' => (int) $id,
            'state' => 'pending',
            'created' => true,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'notificationid' => new external_value(PARAM_INT, 'Local notification row id.'),
            'state' => new external_value(PARAM_ALPHAEXT, 'Current notification state (pending, snoozed, dismissed, resolved).'),
            'created' => new external_value(PARAM_BOOL, 'True when this call inserted a new row; false on idempotent re-receive.'),
        ]);
    }
}
