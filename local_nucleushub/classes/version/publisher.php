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
 * Publish orchestration for course versioning (ADR-014 Phase 1).
 *
 * Runs the full hub-side publish flow: resolve/create family →
 * compute next version → insert version row → Moodle backup →
 * upload snapshot to CP → finalise version → reset draft
 * changelog. Synchronous in Phase 1; async job queue comes later
 * once we've measured real course sizes.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

namespace local_nucleushub\version;

use local_nucleuscommon\transport\cp_client;
use local_nucleuscommon\events\publisher as event_publisher;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/backup/util/includes/backup_includes.php');

/**
 * Orchestrates publishing a course version. Stateless — static
 * methods, caller supplies identity.
 */
class publisher {

    /** @var string[] Severity picker values accepted from callers. */
    public const SEVERITIES = ['major', 'minor', 'patch'];

    /**
     * Publish a new version of a course family.
     *
     * @param int $hubcourseid mdl_course.id of the working hub course.
     * @param string $severity One of SEVERITIES. Drives version-number bump.
     * @param string|null $releasenotes Optional release notes (required for minor+).
     * @param string|null $familyguid If set, publish into the named family; otherwise find-by-course-or-create.
     * @param int $userid Local user id of the publisher.
     * @return array Version metadata: ['familyguid', 'versionguid', 'versionnumber', 'snapshotref', 'snapshothash', 'size', 'timepublished'].
     * @throws \moodle_exception
     */
    public static function publish(
        int $hubcourseid,
        string $severity,
        ?string $releasenotes,
        ?string $familyguid,
        int $userid
    ): array {
        global $DB;

        if (!in_array($severity, self::SEVERITIES, true)) {
            throw new \moodle_exception('invalidseverity', 'local_nucleushub', '', $severity);
        }
        $course = $DB->get_record('course', ['id' => $hubcourseid], '*', MUST_EXIST);

        $family = self::find_or_create_family($course, $familyguid, $userid);
        $last = self::last_version($family->id);
        $versionnumber = self::next_version(
            $last ? $last->versionnumber : null,
            $severity
        );
        // Minor + major require release notes; patch is optional.
        if (($severity === 'minor' || $severity === 'major') && !trim((string) $releasenotes)) {
            throw new \moodle_exception(
                'releasenotesrequired',
                'local_nucleushub',
                '',
                $severity
            );
        }

        $now = time();
        $versionguid = self::uuid_v4();
        $versionid = $DB->insert_record('local_nucleuscommon_version', (object) [
            'guid' => $versionguid,
            'familyid' => $family->id,
            'versionnumber' => $versionnumber,
            'severity' => $severity,
            'snapshotref' => null,
            'snapshothash' => null,
            'hubcourseid' => $course->id,
            'publishedbyid' => $userid,
            'timepublished' => $now,
            'releasenotes' => $releasenotes !== null ? trim($releasenotes) : null,
            'deprecated' => 0,
            'deprecatedreason' => null,
            'timecreated' => $now,
        ]);

        $localbackup = null;
        try {
            $localbackup = self::run_backup($course, $userid);

            $response = cp_client::from_config()->post_file(
                '/course-versions/' . rawurlencode($versionguid) . '/snapshot',
                $localbackup
            );
            if (!isset($response['ref'], $response['hash'], $response['size'])) {
                throw new \moodle_exception(
                    'cpbadresponse',
                    'local_nucleushub',
                    '',
                    json_encode($response)
                );
            }

            $DB->update_record('local_nucleuscommon_version', (object) [
                'id' => $versionid,
                'snapshotref' => (string) $response['ref'],
                'snapshothash' => (string) $response['hash'],
            ]);

            self::finalise_draft($family->id, $versionid, $course->id, $now);

            // Broadcast on the shared Redis stream so the control
            // plane's fan-out worker can notify every spoke in the
            // federation. Best-effort: if Redis is down, the publish
            // still succeeded locally and manual retry / catalog
            // browse still surfaces the version. Don't fail the
            // whole publish just because the fan-out is stalled.
            try {
                event_publisher::publish(
                    'course_version_published.v1',
                    'hub',
                    'broadcast',
                    [
                        'family' => [
                            'guid' => $family->guid,
                            'slug' => $family->slug,
                            'hubfederationid' => $family->hubfederationid,
                        ],
                        'version' => [
                            'guid' => $versionguid,
                            'versionnumber' => $versionnumber,
                            'severity' => $severity,
                            'snapshotref' => (string) $response['ref'],
                            'snapshothash' => (string) $response['hash'],
                            'hubcourseid' => (int) $course->id,
                            'timepublished' => $now,
                            'releasenotes' => $releasenotes,
                        ],
                    ]
                );
            } catch (\Throwable $emiterr) {
                debugging(
                    'course_version_published event emission failed: ' . $emiterr->getMessage(),
                    DEBUG_NORMAL
                );
            }

            return [
                'familyguid' => $family->guid,
                'versionguid' => $versionguid,
                'versionnumber' => $versionnumber,
                'snapshotref' => (string) $response['ref'],
                'snapshothash' => (string) $response['hash'],
                'size' => (int) $response['size'],
                'timepublished' => $now,
            ];
        } catch (\Throwable $e) {
            // Roll back the orphan version row so the next publish
            // can reuse the version number. Leaving a NULL-snapshotref
            // row behind would confuse the listing UI and block the
            // same version number from being re-used.
            $DB->delete_records('local_nucleuscommon_version', ['id' => $versionid]);
            throw $e;
        } finally {
            if ($localbackup !== null && file_exists($localbackup)) {
                @unlink($localbackup);
            }
        }
    }

    /**
     * Compute the next version number.
     *
     * First-ever publish is always 1.0.0 regardless of severity.
     * Otherwise: patch bumps the third, minor bumps the second and
     * zeroes the third, major bumps the first and zeroes the rest.
     *
     * @param string|null $last The last-published version string, or null if none.
     * @param string $severity One of SEVERITIES.
     * @return string Next version number.
     */
    public static function next_version(?string $last, string $severity): string {
        if ($last === null || $last === '') {
            return '1.0.0';
        }
        $parts = explode('.', $last);
        $major = isset($parts[0]) ? (int) $parts[0] : 0;
        $minor = isset($parts[1]) ? (int) $parts[1] : 0;
        $patch = isset($parts[2]) ? (int) $parts[2] : 0;
        switch ($severity) {
            case 'major':
                return ($major + 1) . '.0.0';
            case 'minor':
                return $major . '.' . ($minor + 1) . '.0';
            case 'patch':
            default:
                return $major . '.' . $minor . '.' . ($patch + 1);
        }
    }

    /**
     * Look up a family by hub course + optional guid. If none
     * matches and a guid was supplied, this is an error (caller
     * passed a guid that isn't ours). If no guid was supplied and no
     * family owns this course, create one using the course
     * shortname as the slug.
     *
     * @param \stdClass $course Moodle course row.
     * @param string|null $familyguid Optional caller-supplied guid.
     * @param int $userid Creator user id for auto-creates.
     * @return \stdClass The family row.
     * @throws \moodle_exception
     */
    private static function find_or_create_family(
        \stdClass $course,
        ?string $familyguid,
        int $userid
    ): \stdClass {
        global $DB;

        if ($familyguid !== null && $familyguid !== '') {
            $family = $DB->get_record(
                'local_nucleuscommon_family',
                ['guid' => $familyguid]
            );
            if (!$family) {
                throw new \moodle_exception(
                    'familynotfound',
                    'local_nucleushub',
                    '',
                    $familyguid
                );
            }
            return $family;
        }

        // Auto-create path — first publish of this course.
        $federationid = (string) get_config('local_nucleuscommon', 'federationid');
        if ($federationid === '') {
            throw new \moodle_exception(
                'federationidunset',
                'local_nucleushub'
            );
        }

        // If there's already a draft row for this course, reuse its family.
        $draft = $DB->get_record(
            'local_nucleushub_draft',
            ['hubcourseid' => $course->id]
        );
        if ($draft) {
            $family = $DB->get_record(
                'local_nucleuscommon_family',
                ['id' => $draft->familyid],
                '*',
                MUST_EXIST
            );
            return $family;
        }

        $slug = self::slugify($course->shortname);
        // If slug clashes within the federation, append the course
        // id for disambiguation.
        $exists = $DB->record_exists(
            'local_nucleuscommon_family',
            ['hubfederationid' => $federationid, 'slug' => $slug]
        );
        if ($exists) {
            $slug .= '-' . $course->id;
        }

        $now = time();
        $guid = self::uuid_v4();
        $familyid = $DB->insert_record('local_nucleuscommon_family', (object) [
            'guid' => $guid,
            'slug' => $slug,
            'hubfederationid' => $federationid,
            'catalogvisible' => 1,
            'createdbyid' => $userid,
            'timecreated' => $now,
        ]);
        return $DB->get_record(
            'local_nucleuscommon_family',
            ['id' => $familyid],
            '*',
            MUST_EXIST
        );
    }

    /**
     * Find the most recent version row for a family, or null if
     * this is the first publish.
     *
     * @param int $familyid Family id.
     * @return \stdClass|null Version row or null.
     */
    private static function last_version(int $familyid): ?\stdClass {
        global $DB;
        $rows = $DB->get_records(
            'local_nucleuscommon_version',
            ['familyid' => $familyid],
            'timepublished DESC, id DESC',
            '*',
            0,
            1
        );
        return $rows ? reset($rows) : null;
    }

    /**
     * Run the Moodle backup and return a local path to the `.mbz`.
     * Caller is responsible for cleaning up the returned file.
     *
     * @param \stdClass $course Course row to back up.
     * @param int $userid Acting user id.
     * @return string Absolute path to the .mbz file.
     * @throws \moodle_exception
     */
    private static function run_backup(\stdClass $course, int $userid): string {
        global $CFG;

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            (int) $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $userid
        );
        try {
            $bc->execute_plan();
            $results = $bc->get_results();
            if (empty($results['backup_destination']) ||
                !($results['backup_destination'] instanceof \stored_file)
            ) {
                throw new \moodle_exception(
                    'backupfailed',
                    'local_nucleushub',
                    '',
                    null,
                    'backup_controller returned no stored_file for course ' . $course->id
                );
            }
            /** @var \stored_file $storedfile */
            $storedfile = $results['backup_destination'];

            $tmpdir = make_request_directory();
            $tmppath = $tmpdir . '/snapshot.mbz';
            if (!$storedfile->copy_content_to($tmppath)) {
                throw new \moodle_exception(
                    'backupfailed',
                    'local_nucleushub',
                    '',
                    null,
                    'copy_content_to failed for ' . $tmppath
                );
            }
            return $tmppath;
        } finally {
            $bc->destroy();
        }
    }

    /**
     * Upsert the draft row for this family and clear its pending
     * changelog. Called after a successful publish so the banner
     * resets to "no changes since vN".
     *
     * @param int $familyid Family id.
     * @param int $versionid Newly-published version id.
     * @param int $hubcourseid Hub course id backing this family.
     * @param int $now Unix time of the publish.
     */
    private static function finalise_draft(
        int $familyid,
        int $versionid,
        int $hubcourseid,
        int $now
    ): void {
        global $DB;

        $existing = $DB->get_record(
            'local_nucleushub_draft',
            ['familyid' => $familyid]
        );
        if ($existing) {
            $DB->update_record('local_nucleushub_draft', (object) [
                'id' => $existing->id,
                'lastpublishversionid' => $versionid,
                'pendingchangecount' => 0,
                'timelastedit' => null,
            ]);
        } else {
            $DB->insert_record('local_nucleushub_draft', (object) [
                'familyid' => $familyid,
                'hubcourseid' => $hubcourseid,
                'lastpublishversionid' => $versionid,
                'pendingchangecount' => 0,
                'timelastedit' => null,
                'timecreated' => $now,
            ]);
        }
        // Clear the changelog buffer — it has been rolled into the
        // published version's release notes (Phase 2 feature; for
        // Phase 1 we just discard).
        $DB->delete_records('local_nucleushub_changelog', ['familyid' => $familyid]);
    }

    /**
     * Slugify a Moodle shortname into something safe for the slug
     * column. Lowercase, alphanumerics + hyphens, collapse runs,
     * truncate to 120 chars.
     *
     * @param string $shortname
     * @return string
     */
    public static function slugify(string $shortname): string {
        $slug = strtolower(trim($shortname));
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'course';
        }
        return substr($slug, 0, 120);
    }

    /**
     * Generate a RFC 4122 v4 UUID. Moodle doesn't ship one and we
     * need stable cross-instance identities for families and
     * versions.
     *
     * @return string 36-char UUID.
     */
    public static function uuid_v4(): string {
        $bytes = random_bytes(16);
        // Set version (4) and variant (10xx) bits.
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
