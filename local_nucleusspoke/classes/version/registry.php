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
 * Spoke-side upserts for family + version rows — shared by the
 * puller (pulling a version creates local mirrors) and the
 * notification receiver (being told about a version creates the
 * same mirrors). Both paths hit by guid, so race-safety comes from
 * the unique index; we read-then-insert with a defensive re-read
 * on collision.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

namespace local_nucleusspoke\version;

defined('MOODLE_INTERNAL') || die();

/**
 * Shared spoke-side family/version upsert helpers. Immutable rows
 * — find-by-guid or insert; never update.
 */
class registry {

    /**
     * Upsert a family row by guid. Immutable after insert; returns
     * the existing row when present.
     *
     * @param array $dto ['guid', 'slug', 'hubfederationid'].
     * @return \stdClass Family row.
     * @throws \moodle_exception
     */
    public static function upsert_family(array $dto): \stdClass {
        global $DB;

        self::require_keys($dto, ['guid', 'slug', 'hubfederationid'], 'family');

        $existing = $DB->get_record(
            'local_nucleuscommon_family',
            ['guid' => $dto['guid']]
        );
        if ($existing) {
            return $existing;
        }
        $now = time();
        try {
            $id = $DB->insert_record('local_nucleuscommon_family', (object) [
                'guid' => (string) $dto['guid'],
                'slug' => (string) $dto['slug'],
                'hubfederationid' => (string) $dto['hubfederationid'],
                'catalogvisible' => 1,
                'createdbyid' => (int) get_admin()->id,
                'timecreated' => $now,
            ]);
        } catch (\dml_write_exception $e) {
            // Race: someone else inserted the same guid between our
            // check and our insert. Re-read.
            $existing = $DB->get_record(
                'local_nucleuscommon_family',
                ['guid' => $dto['guid']]
            );
            if ($existing) {
                return $existing;
            }
            throw $e;
        }
        return $DB->get_record(
            'local_nucleuscommon_family',
            ['id' => $id],
            '*',
            MUST_EXIST
        );
    }

    /**
     * Upsert a version row by guid.
     *
     * @param array $dto Version DTO.
     * @param int $familyid Local family id (already upserted).
     * @return \stdClass Version row.
     * @throws \moodle_exception
     */
    public static function upsert_version(array $dto, int $familyid): \stdClass {
        global $DB;

        self::require_keys($dto, [
            'guid',
            'versionnumber',
            'severity',
            'snapshotref',
            'snapshothash',
            'hubcourseid',
            'timepublished',
        ], 'version');

        $existing = $DB->get_record(
            'local_nucleuscommon_version',
            ['guid' => $dto['guid']]
        );
        if ($existing) {
            return $existing;
        }
        $now = time();
        try {
            $id = $DB->insert_record('local_nucleuscommon_version', (object) [
                'guid' => (string) $dto['guid'],
                'familyid' => $familyid,
                'versionnumber' => (string) $dto['versionnumber'],
                'severity' => (string) $dto['severity'],
                'snapshotref' => (string) $dto['snapshotref'],
                'snapshothash' => (string) $dto['snapshothash'],
                'hubcourseid' => (int) $dto['hubcourseid'],
                'publishedbyid' => (int) get_admin()->id,
                'timepublished' => (int) $dto['timepublished'],
                'releasenotes' => isset($dto['releasenotes']) && $dto['releasenotes'] !== ''
                    ? (string) $dto['releasenotes']
                    : null,
                'deprecated' => 0,
                'deprecatedreason' => null,
                // Mirror the hub's edit-lock flag so subsequent reads
                // (catalogue chip, course-page banner) can act on it
                // without re-fetching describe_version. Defaults to 0
                // when the publish event predates the field.
                'lockedforspokeedit' => !empty($dto['lockedforspokeedit']) ? 1 : 0,
                'timecreated' => $now,
            ]);
        } catch (\dml_write_exception $e) {
            $existing = $DB->get_record(
                'local_nucleuscommon_version',
                ['guid' => $dto['guid']]
            );
            if ($existing) {
                return $existing;
            }
            throw $e;
        }
        return $DB->get_record(
            'local_nucleuscommon_version',
            ['id' => $id],
            '*',
            MUST_EXIST
        );
    }

    /**
     * Validate a DTO has all required keys, non-empty.
     *
     * @param array $dto
     * @param string[] $keys
     * @param string $label For the error message.
     * @throws \moodle_exception
     */
    public static function require_keys(array $dto, array $keys, string $label): void {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $dto) || $dto[$k] === null || $dto[$k] === '') {
                // Same fix as the other pullfailed throw sites — pass
                // the message as $a so Moodle renders the lang string
                // with the actual reason instead of literal `{$a}`.
                $msg = "{$label} DTO missing '{$k}'";
                throw new \moodle_exception('pullfailed', 'local_nucleusspoke', '', $msg, $msg);
            }
        }
    }
}
