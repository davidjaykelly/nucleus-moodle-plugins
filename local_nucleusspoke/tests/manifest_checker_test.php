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
 * Unit tests for the ADR-021 Tier A manifest checker.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleusspoke;

use local_nucleusspoke\version\manifest_checker;

defined('MOODLE_INTERNAL') || die();

/**
 * Tier A pre-flight unit tests. Discovered by Moodle's PHPUnit
 * runner when the plugin is installed in a Moodle tree:
 *   php admin/tool/phpunit/cli/init.php
 *   vendor/bin/phpunit local_nucleusspoke
 *
 * @covers \local_nucleusspoke\version\manifest_checker
 */
final class manifest_checker_test extends \advanced_testcase {

    /**
     * Legacy versions published before ADR-021 have hasmanifest=false.
     * Allow the pull but emit a Tier C note.
     */
    public function test_no_manifest_yields_note_no_blocker(): void {
        $describe = ['hasmanifest' => false, 'manifest' => ''];
        $result = manifest_checker::check($describe);

        $this->assertSame('missing', $result['manifest_status']);
        $this->assertSame([], $result['blockers']);
        $this->assertNotEmpty($result['notes']);
    }

    /**
     * hasmanifest=true with empty manifest body — treat as missing,
     * don't crash.
     */
    public function test_empty_manifest_string_is_missing(): void {
        $describe = ['hasmanifest' => true, 'manifest' => ''];
        $result = manifest_checker::check($describe);

        $this->assertSame('missing', $result['manifest_status']);
        $this->assertSame([], $result['blockers']);
    }

    /**
     * Garbage JSON shouldn't crash the puller — record a note and
     * fall through to the restore precheck.
     */
    public function test_malformed_manifest_yields_no_blocker(): void {
        $describe = ['hasmanifest' => true, 'manifest' => '{not-json'];
        $result = manifest_checker::check($describe);

        $this->assertSame('malformed', $result['manifest_status']);
        $this->assertSame([], $result['blockers']);
    }

    /**
     * Hub-side extractor failure shouldn't block — the spoke can't
     * tell what's required, so deferring to the restore precheck is
     * the safer move.
     */
    public function test_extractor_error_yields_no_blocker(): void {
        $manifest = [
            'schema_version' => 1,
            'mods' => [],
            'extractor_error' => 'moodle_backup_xml_missing',
        ];
        $describe = [
            'hasmanifest' => true,
            'manifest' => json_encode($manifest),
        ];
        $result = manifest_checker::check($describe);

        $this->assertSame('extractor_error', $result['manifest_status']);
        $this->assertSame([], $result['blockers']);
        $this->assertNotEmpty($result['notes']);
    }

    /**
     * Manifest lists `mod_h5p` but the spoke doesn't have it →
     * blocker. This is the canonical Tier A scenario from ADR-021.
     */
    public function test_missing_mod_blocks_pull(): void {
        $this->resetAfterTest();

        // Pick a plugin name guaranteed not to exist on a vanilla
        // Moodle install. `nucleus_doesnotexist_xyz123` is a
        // namespaced impossible module so this test stays stable
        // regardless of what actually ships in core.
        $manifest = [
            'schema_version' => 2,
            'moodle_release' => '5.1.0+ (Build: 20250901)',
            'moodle_branch' => '5.1',
            'mods' => [
                ['name' => 'nucleus_doesnotexist_xyz123', 'version' => 2026010100],
                ['name' => 'forum', 'version' => 0],
            ],
        ];
        $describe = [
            'hasmanifest' => true,
            'manifest' => json_encode($manifest),
        ];
        $result = manifest_checker::check($describe);

        $this->assertSame('present', $result['manifest_status']);
        $this->assertNotEmpty($result['blockers']);
        $this->assertSame('missing_plugins', $result['blockers'][0]['kind']);
        $this->assertStringContainsString(
            'nucleus_doesnotexist_xyz123',
            $result['blockers'][0]['detail']
        );
        // Forum exists in core, must NOT be flagged.
        $this->assertStringNotContainsString(
            'mod_forum',
            $result['blockers'][0]['detail']
        );
    }

    /**
     * Manifest's plugins all exist on the spoke → no blocker. Uses
     * `mod_forum` as the canonical "always present in core" check.
     */
    public function test_all_mods_present_yields_no_blocker(): void {
        $this->resetAfterTest();

        $manifest = [
            'schema_version' => 2,
            'moodle_release' => '5.1.0+ (Build: 20250901)',
            'moodle_branch' => '5.1',
            'mods' => [
                ['name' => 'forum', 'version' => 0],
                ['name' => 'page', 'version' => 0],
                ['name' => 'label', 'version' => 0],
            ],
        ];
        $describe = [
            'hasmanifest' => true,
            'manifest' => json_encode($manifest),
        ];
        $result = manifest_checker::check($describe);

        // Note: this test only stays clean while `forum`/`page`/
        // `label` remain shipped in core. They have for >15 years
        // and aren't going anywhere.
        $modblockers = array_filter(
            $result['blockers'],
            fn ($b) => $b['kind'] === 'missing_plugins'
        );
        $this->assertSame([], $modblockers);
    }

    /**
     * `mod_xxx` prefix on the hub side should be tolerated — we
     * compare on bare names. (The publisher uses bare names today
     * but a future hub could switch to fully qualified strings.)
     */
    public function test_prefixed_mod_names_are_normalised(): void {
        $this->resetAfterTest();

        $manifest = [
            'schema_version' => 2,
            'mods' => [
                ['name' => 'mod_forum', 'version' => 0],
                ['name' => 'mod_page', 'version' => 0],
            ],
        ];
        $describe = [
            'hasmanifest' => true,
            'manifest' => json_encode($manifest),
        ];
        $result = manifest_checker::check($describe);

        $modblockers = array_filter(
            $result['blockers'],
            fn ($b) => $b['kind'] === 'missing_plugins'
        );
        $this->assertSame([], $modblockers);
    }

    /**
     * Schema v1 manifests (string-list mods) must still parse
     * cleanly so legacy versions published before ADR-021 v1.1
     * don't break this spoke after upgrade.
     */
    public function test_schema_v1_mods_list_still_parses(): void {
        $this->resetAfterTest();

        $manifest = [
            'schema_version' => 1,
            'mods' => ['forum', 'page'],
        ];
        $describe = [
            'hasmanifest' => true,
            'manifest' => json_encode($manifest),
        ];
        $result = manifest_checker::check($describe);

        $modblockers = array_filter(
            $result['blockers'],
            fn ($b) => $b['kind'] === 'missing_plugins'
        );
        $this->assertSame([], $modblockers);
    }

    /**
     * v1.1 — when the hub manifest pins a newer plugin version than
     * the spoke has, emit a Tier C note (NOT a blocker). Restore
     * generally handles minor drift cleanly.
     */
    public function test_older_spoke_plugin_version_yields_note(): void {
        $this->resetAfterTest();

        // Pick a real installed mod and pin the manifest to a version
        // far in the future so the spoke's version is guaranteed
        // smaller. `versiondisk` for mod_forum is in the 20xxxxxxxx
        // range — 99990101_00 is comfortably newer than anything
        // shipped today.
        $manifest = [
            'schema_version' => 2,
            'mods' => [
                ['name' => 'forum', 'version' => 9999010100],
            ],
        ];
        $describe = [
            'hasmanifest' => true,
            'manifest' => json_encode($manifest),
        ];
        $result = manifest_checker::check($describe);

        $this->assertSame([], $result['blockers']);
        $this->assertNotEmpty($result['notes']);
        $matching = array_filter(
            $result['notes'],
            fn ($n) => str_contains($n, 'mod_forum') && str_contains($n, 'older')
        );
        $this->assertNotEmpty($matching, 'expected a Tier C note about mod_forum being older');
    }

    /**
     * Manifest version of 0 means the hub didn't know — skip the
     * version compare and don't emit a spurious note.
     */
    public function test_zero_manifest_version_skips_compare(): void {
        $this->resetAfterTest();

        $manifest = [
            'schema_version' => 2,
            'mods' => [
                ['name' => 'forum', 'version' => 0],
            ],
        ];
        $describe = [
            'hasmanifest' => true,
            'manifest' => json_encode($manifest),
        ];
        $result = manifest_checker::check($describe);

        $this->assertSame([], $result['blockers']);
        $this->assertSame([], $result['notes']);
    }

    /**
     * v1.1 — mod_status is the per-row data the UI table renders.
     * Missing rows float to the top; present rows have spoke_version
     * populated; missing rows have spoke_version=null.
     */
    public function test_mod_status_shape(): void {
        $this->resetAfterTest();

        $manifest = [
            'schema_version' => 2,
            'mods' => [
                ['name' => 'forum', 'version' => 0],
                ['name' => 'nucleus_doesnotexist_xyz123', 'version' => 2026010100],
            ],
        ];
        $describe = [
            'hasmanifest' => true,
            'manifest' => json_encode($manifest),
        ];
        $result = manifest_checker::check($describe);

        $this->assertNotEmpty($result['mod_status']);
        // Missing should sort first.
        $this->assertSame('missing', $result['mod_status'][0]['state']);
        $this->assertSame('nucleus_doesnotexist_xyz123', $result['mod_status'][0]['name']);
        $this->assertNull($result['mod_status'][0]['spoke_version']);
        $this->assertSame(2026010100, $result['mod_status'][0]['expected_version']);

        // Forum is present.
        $this->assertSame('present', $result['mod_status'][1]['state']);
        $this->assertSame('forum', $result['mod_status'][1]['name']);
        $this->assertNotNull($result['mod_status'][1]['spoke_version']);
    }
}
