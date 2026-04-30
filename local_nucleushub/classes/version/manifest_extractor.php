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
 * ADR-021 Tier A — extract a dependency manifest from a packed `.mbz`.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

namespace local_nucleushub\version;

defined('MOODLE_INTERNAL') || die();

/**
 * Reads `moodle_backup.xml` out of a backup archive and produces a
 * compact dependency manifest the spoke can pre-flight against.
 *
 * Tier A scope is intentionally narrow:
 *   - Moodle version + branch the backup was taken on.
 *   - Distinct `mod_*` plugins referenced by activities in the course.
 *
 * Question types, blocks, themes, filters, and language packs are
 * out of scope for v1 (Tier C territory). The structure leaves room
 * for them: future fields land alongside `mods` without breaking
 * existing readers.
 */
class manifest_extractor {

    /**
     * Manifest schema version. Bumped when the JSON shape changes in
     * a way readers would care about.
     *
     * Schema v2 (2026-04-30, ADR-021 v1.1): mods entries gained a
     * `version` field (Moodle plugin version int from
     * core_plugin_manager). Readers must treat absent / 0 as "unknown,
     * skip version check" so v1 manifests still parse cleanly.
     *
     * @var int
     */
    public const SCHEMA_VERSION = 2;

    /**
     * Extract the manifest from a local `.mbz` path.
     *
     * Never throws on a malformed archive — returns a manifest with
     * `extractor_error` set so the caller can decide what to do.
     * (We'd rather publish a version with a partial manifest than
     * fail the publish over a parse hiccup.)
     *
     * @param string $mbzpath Absolute path to the `.mbz` file.
     * @return array Manifest array suitable for json_encode.
     */
    public static function extract(string $mbzpath): array {
        global $CFG;

        $manifest = self::skeleton();

        if (!is_readable($mbzpath)) {
            $manifest['extractor_error'] = 'mbz_not_readable';
            return $manifest;
        }

        $tmpdir = make_request_directory();
        $packer = get_file_packer('application/vnd.moodle.backup');
        // We only need `moodle_backup.xml` — the activity-level XMLs
        // sit under `activities/` but the top-level file lists them
        // with their `modulename`, which is all Tier A needs.
        $extracted = $packer->extract_to_pathname(
            $mbzpath,
            $tmpdir,
            ['moodle_backup.xml']
        );
        if (!$extracted || empty($extracted['moodle_backup.xml'])) {
            $manifest['extractor_error'] = 'moodle_backup_xml_missing';
            return $manifest;
        }

        $xmlpath = $tmpdir . '/moodle_backup.xml';
        if (!is_readable($xmlpath)) {
            $manifest['extractor_error'] = 'moodle_backup_xml_unreadable';
            return $manifest;
        }

        // SimpleXML parses the file fully into memory; moodle_backup.xml
        // is small (a few KB even for big courses) so this is fine.
        // libxml errors are suppressed and inspected explicitly so a
        // malformed XML doesn't bubble a PHP warning into the publish
        // response.
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_file($xmlpath);
            if ($xml === false) {
                $manifest['extractor_error'] = 'moodle_backup_xml_invalid';
                return $manifest;
            }

            // <information> block carries Moodle version metadata.
            $info = $xml->information ?? null;
            if ($info) {
                $manifest['moodle_version'] = (int) ($info->moodle_version ?? 0);
                $manifest['moodle_release'] = (string) ($info->moodle_release ?? '');
                $manifest['backup_version'] = (int) ($info->backup_version ?? 0);
                $manifest['backup_release'] = (string) ($info->backup_release ?? '');
            }

            // moodle_release is e.g. "5.1.0+ (Build: 20250901)" — derive
            // the major.minor branch ("5.1") for cheap comparisons later.
            if ($manifest['moodle_release'] !== '') {
                if (preg_match(
                    '/^(\d+)\.(\d+)/',
                    $manifest['moodle_release'],
                    $m
                )) {
                    $manifest['moodle_branch'] = $m[1] . '.' . $m[2];
                }
            }

            // <contents><activities><activity><modulename> — distinct
            // set of mod plugins this backup will try to restore.
            // Activity rows include disabled-in-backup ones too, but
            // a missing module on the spoke would still cause silent
            // damage on restore, so we list everything.
            $modulenames = [];
            $activities = $xml->information->contents->activities->activity ?? null;
            if ($activities) {
                foreach ($activities as $activity) {
                    $name = (string) ($activity->modulename ?? '');
                    if ($name !== '') {
                        $modulenames[$name] = true;
                    }
                }
            }
            ksort($modulenames);

            // Schema v2 — pair each mod with its installed version on
            // the hub right now. Readers can flag a Tier C "spoke is
            // older" warning. Lookup is one core_plugin_manager call
            // total (cached); per-mod retrieval is map access.
            $modplugins = \core_plugin_manager::instance()->get_plugins_of_type('mod');
            $modentries = [];
            foreach (array_keys($modulenames) as $name) {
                $version = 0;
                if (isset($modplugins[$name]) && isset($modplugins[$name]->versiondisk)) {
                    $version = (int) $modplugins[$name]->versiondisk;
                }
                $modentries[] = [
                    'name' => $name,
                    'version' => $version,
                ];
            }
            $manifest['mods'] = $modentries;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        // Stamp who produced this manifest so consumers can detect
        // version skew between hub and spoke extractors. CFG->release
        // is "5.1.4 (Build: 20250901)"-shaped so we keep the full
        // string for diagnostic value.
        $manifest['extractor'] = [
            'plugin' => 'local_nucleushub',
            'schema' => self::SCHEMA_VERSION,
            'moodle_release' => isset($CFG->release) ? (string) $CFG->release : '',
        ];

        return $manifest;
    }

    /**
     * Empty-but-shaped manifest. Returned when extraction fails so
     * downstream readers don't need to special-case "no manifest at
     * all". `extractor_error` is set on the failure paths.
     *
     * @return array
     */
    private static function skeleton(): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'moodle_version' => 0,
            'moodle_release' => '',
            'moodle_branch' => '',
            'backup_version' => 0,
            'backup_release' => '',
            // Schema v2 — entries are {name, version}. Empty list is
            // valid (course with no activities, or extractor failed
            // before the activity walk).
            'mods' => [],
        ];
    }
}
