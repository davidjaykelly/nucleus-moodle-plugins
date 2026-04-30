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
 * ADR-021 Tier A — pre-flight a hub version manifest against this
 * spoke's environment.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

namespace local_nucleusspoke\version;

defined('MOODLE_INTERNAL') || die();

/**
 * Compare a hub-published manifest against what's installed on the
 * spoke. Tier A scope:
 *   - Activity-mod plugins listed in the manifest must exist locally.
 *   - The spoke's Moodle major-version must be >= the major-version
 *     the backup was taken on.
 *
 * Anything else (qtype mismatches, theme deps, minor-version drift)
 * is Tier C and either logged or deferred to v1.1.
 */
class manifest_checker {

    /**
     * Run the Tier A check against a manifest payload returned by the
     * hub's `local_nucleushub_describe_version`. Returns a structured
     * result; never throws on its own — caller decides the action.
     *
     * Caller contract: the pull is allowed iff `blockers` is empty.
     * `manifest_status` is for logging / telemetry only.
     *
     * @param array $describe Wire payload from describe_version. Must
     *                        contain at least `hasmanifest` + `manifest`.
     * @return array {
     *   manifest_status: 'present' | 'missing' | 'malformed' | 'extractor_error',
     *   blockers: array<int, array{kind: string, detail: string, remediation: string}>,
     *   notes: array<int, string>,        // Tier C — log only for v1
     *   manifest: array|null              // parsed manifest, null if unusable
     * }
     */
    public static function check(array $describe): array {
        $result = [
            'manifest_status' => 'missing',
            'blockers' => [],
            'notes' => [],
            'manifest' => null,
        ];

        if (empty($describe['hasmanifest'])) {
            // Pre-ADR-021 versions have no manifest. v1 policy: allow
            // pull, surface a Tier C note. Customers with truly old
            // versions can re-publish once they've upgraded.
            $result['notes'][] = 'No manifest on this version (published before ADR-021); '
                . 'plugin compatibility was not pre-flighted. Restore precheck still '
                . 'runs as the second line of defence.';
            return $result;
        }

        $raw = (string) ($describe['manifest'] ?? '');
        if ($raw === '') {
            $result['manifest_status'] = 'missing';
            $result['notes'][] = 'Manifest field empty despite hasmanifest=true; treating as missing.';
            return $result;
        }

        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) {
            $result['manifest_status'] = 'malformed';
            $result['notes'][] = 'Manifest JSON failed to parse on the spoke side.';
            return $result;
        }
        $result['manifest'] = $manifest;

        // Hub-side extractor signals failure by setting `extractor_error`.
        // Tier A is conservative — when the hub couldn't read the
        // backup, we don't have enough info to block, so we allow but
        // flag.
        if (!empty($manifest['extractor_error'])) {
            $result['manifest_status'] = 'extractor_error';
            $result['notes'][] = 'Hub-side extractor reported: ' . (string) $manifest['extractor_error'];
            return $result;
        }

        $result['manifest_status'] = 'present';

        // Major-version check — spoke must be on the same Moodle major
        // as the hub-at-backup-time, or newer. Older spoke trying to
        // restore a newer-Moodle backup is the version-warning class
        // we currently force-proceed past; Tier A blocks it cleanly.
        $hubbranch = (string) ($manifest['moodle_branch'] ?? '');
        if ($hubbranch !== '') {
            $spokebranch = self::current_moodle_branch();
            if ($spokebranch !== '' && self::is_older($spokebranch, $hubbranch)) {
                $result['blockers'][] = [
                    'kind' => 'moodle_version_too_old',
                    'detail' => sprintf(
                        'This backup was taken on Moodle %s; this spoke is on %s.',
                        $hubbranch,
                        $spokebranch
                    ),
                    'remediation' => sprintf(
                        'Upgrade this spoke to Moodle %s or newer, then retry the pull.',
                        $hubbranch
                    ),
                ];
            }
        }

        // Plugin check — every `mod_*` listed in the manifest must
        // exist locally. Anything missing blocks; older-than-manifest
        // versions emit Tier C notes (Moodle restore handles minor
        // drift, but the operator deserves to know).
        $expected = self::expected_mods($manifest);
        $installed = self::installed_mod_versions();
        if ($expected) {
            $missing = [];
            foreach ($expected as $name => $hubversion) {
                if (!isset($installed[$name])) {
                    $missing[] = $name;
                    continue;
                }
                $spokeversion = $installed[$name];
                if ($hubversion > 0 && $spokeversion > 0 && $spokeversion < $hubversion) {
                    $result['notes'][] = sprintf(
                        'mod_%s on this spoke (v%d) is older than the version the backup was '
                            . 'taken with (v%d). Most courses restore cleanly across minor plugin '
                            . 'drift, but specific features may not render identically.',
                        $name,
                        $spokeversion,
                        $hubversion
                    );
                }
            }
            if ($missing) {
                sort($missing);
                $result['blockers'][] = [
                    'kind' => 'missing_plugins',
                    'detail' => sprintf(
                        'This course uses %d Moodle plugin%s not installed on this spoke: %s.',
                        count($missing),
                        count($missing) === 1 ? '' : 's',
                        implode(', ', array_map(fn ($m) => 'mod_' . $m, $missing))
                    ),
                    'remediation' => 'Install the listed plugins on this spoke (Site administration → '
                        . 'Plugins → Install plugins) and retry the pull. Alternatively, ask the '
                        . 'hub admin to publish a version of the course that doesn\'t use these.',
                ];
            }
        }

        return $result;
    }

    /**
     * "5.1" → comparable. Returns the spoke's moodle_branch ("5.1")
     * derived from $CFG->release. Empty string when we can't parse.
     *
     * @return string
     */
    private static function current_moodle_branch(): string {
        global $CFG;
        $release = isset($CFG->release) ? (string) $CFG->release : '';
        if ($release === '') {
            return '';
        }
        if (!preg_match('/^(\d+)\.(\d+)/', $release, $m)) {
            return '';
        }
        return $m[1] . '.' . $m[2];
    }

    /**
     * Compare two Moodle branch strings ("5.1" vs "5.0") numerically.
     * True iff $a is strictly older than $b.
     *
     * @param string $a Branch like "5.0".
     * @param string $b Branch like "5.1".
     * @return bool
     */
    private static function is_older(string $a, string $b): bool {
        $apairs = explode('.', $a);
        $bpairs = explode('.', $b);
        $amajor = (int) ($apairs[0] ?? 0);
        $aminor = (int) ($apairs[1] ?? 0);
        $bmajor = (int) ($bpairs[0] ?? 0);
        $bminor = (int) ($bpairs[1] ?? 0);
        if ($amajor !== $bmajor) {
            return $amajor < $bmajor;
        }
        return $aminor < $bminor;
    }

    /**
     * Normalise the manifest's mods list into name=>hubversion map.
     * Tolerant of both schema v1 (mods is a string list) and schema
     * v2 (mods is a list of `{name, version}` objects). v1 entries
     * yield hubversion=0 — comparators should treat 0 as "unknown,
     * skip version check".
     *
     * Strips a `mod_` prefix if present so we compare on bare names.
     *
     * @param array $manifest
     * @return array<string, int> Map of bare-name => hub plugin version.
     */
    private static function expected_mods(array $manifest): array {
        $mods = $manifest['mods'] ?? [];
        if (!is_array($mods)) {
            return [];
        }
        $out = [];
        foreach ($mods as $entry) {
            $name = '';
            $version = 0;
            if (is_string($entry)) {
                // Schema v1 — bare name string.
                $name = $entry;
            } elseif (is_array($entry)) {
                $name = (string) ($entry['name'] ?? '');
                $version = (int) ($entry['version'] ?? 0);
            } else {
                continue;
            }
            if ($name === '') {
                continue;
            }
            $bare = preg_replace('/^mod_/', '', $name);
            if ($bare === '') {
                continue;
            }
            // Last write wins on duplicate names — should never happen
            // since the hub side ksorts + dedupes, but be defensive.
            $out[$bare] = $version;
        }
        return $out;
    }

    /**
     * Map of bare-name => versiondisk for every `mod_*` plugin
     * installed on this spoke. Uses Moodle's plugin manager so we
     * see only enabled-and-installed plugins (matches what the
     * restore engine would actually find).
     *
     * @return array<string, int>
     */
    private static function installed_mod_versions(): array {
        $installed = \core_plugin_manager::instance()->get_plugins_of_type('mod');
        $out = [];
        foreach ($installed as $name => $info) {
            $out[$name] = isset($info->versiondisk) ? (int) $info->versiondisk : 0;
        }
        return $out;
    }
}
