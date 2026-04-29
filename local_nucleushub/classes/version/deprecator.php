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
 * Deprecation flip for a published version (ADR-014 Phase 2).
 *
 * Sets / clears the `deprecated` flag on a local_nucleuscommon_version
 * row and broadcasts the change on the shared Redis stream so every
 * spoke with a mirror of the version can reflect it locally.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

namespace local_nucleushub\version;

use local_nucleuscommon\events\publisher as event_publisher;

defined('MOODLE_INTERNAL') || die();

class deprecator {

    /**
     * Mark or unmark a version as deprecated.
     *
     * @param string $versionguid Global version identity.
     * @param bool $deprecated true = mark deprecated, false = clear.
     * @param string|null $reason Operator note, shown in UIs.
     * @return \stdClass Updated version row.
     * @throws \moodle_exception
     */
    public static function set(
        string $versionguid,
        bool $deprecated,
        ?string $reason
    ): \stdClass {
        global $DB;

        $version = $DB->get_record(
            'local_nucleuscommon_version',
            ['guid' => $versionguid],
            '*',
            MUST_EXIST
        );
        $family = $DB->get_record(
            'local_nucleuscommon_family',
            ['id' => $version->familyid],
            '*',
            MUST_EXIST
        );

        // Idempotent no-op when the state already matches.
        if ((bool) $version->deprecated === $deprecated
            && ($version->deprecatedreason ?? null) === ($reason ?: null)
        ) {
            return $version;
        }

        $DB->update_record('local_nucleuscommon_version', (object) [
            'id' => $version->id,
            'deprecated' => $deprecated ? 1 : 0,
            'deprecatedreason' => $deprecated ? ($reason ?: null) : null,
        ]);
        $fresh = $DB->get_record(
            'local_nucleuscommon_version',
            ['id' => $version->id],
            '*',
            MUST_EXIST
        );

        // Broadcast so spokes mirror the flag. Best-effort — a
        // stalled Redis shouldn't prevent the local flip (operators
        // can retry the action to re-emit).
        try {
            event_publisher::publish(
                'course_version_deprecated.v1',
                'hub',
                'broadcast',
                [
                    'familyguid' => $family->guid,
                    'familyslug' => $family->slug,
                    'hubfederationid' => $family->hubfederationid,
                    'versionguid' => $fresh->guid,
                    'versionnumber' => $fresh->versionnumber,
                    'deprecated' => $deprecated,
                    'deprecatedreason' => $reason ?? '',
                ]
            );
        } catch (\Throwable $emiterr) {
            debugging(
                'course_version_deprecated event emission failed: '
                    . $emiterr->getMessage(),
                DEBUG_NORMAL
            );
        }

        return $fresh;
    }
}
