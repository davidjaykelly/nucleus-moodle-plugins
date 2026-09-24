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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_nucleusspoke\output;

use local_nucleusspoke\local\labels;
use moodle_url;
use renderable;
use renderer_base;
use single_button;
use templatable;

/**
 * The Catalogue: every course the hub shares, and what this site has.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class catalog_page implements renderable, templatable {
    /** @var array[] Families as returned by the hub's list_families. */
    private array $catalog;

    /** @var moodle_url The catalogue page. */
    private moodle_url $pageurl;

    /**
     * Constructor.
     *
     * @param array[] $catalog Families from the hub.
     * @param moodle_url $pageurl
     */
    public function __construct(array $catalog, moodle_url $pageurl) {
        $this->catalog = $catalog;
        $this->pageurl = $pageurl;
    }

    /**
     * What this site has for each family, keyed by family guid.
     *
     * @return array guid => ['versionguid' => string, 'notes' => array]
     */
    private static function local_instances(): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT i.id, i.pullnotes, i.timepulled, f.guid AS familyguid, v.guid AS versionguid
               FROM {local_nucleusspoke_instance} i
               JOIN {local_nucleuscommon_family} f ON f.id = i.familyid
               JOIN {local_nucleuscommon_version} v ON v.id = i.versionid
           ORDER BY i.timepulled ASC"
        );
        $out = [];
        foreach ($rows as $row) {
            $notes = [];
            if (!empty($row->pullnotes)) {
                $decoded = json_decode((string) $row->pullnotes, true);
                $notes = is_array($decoded) ? $decoded : [];
            }
            // Later pulls overwrite earlier ones: the newest is what counts.
            $out[$row->familyguid] = ['versionguid' => $row->versionguid, 'notes' => $notes];
        }
        return $out;
    }

    /**
     * Words for the kind of a pull note.
     *
     * @param string $kind
     * @return string
     */
    private static function note_kind(string $kind): string {
        $known = ['restore_warning', 'manifest_note', 'edit_locked'];
        return in_array($kind, $known, true)
            ? get_string('notekind_' . $kind, 'local_nucleusspoke')
            : get_string('notekind_other', 'local_nucleusspoke');
    }

    /**
     * Template context.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $local = self::local_instances();
        $catalog = $this->catalog;
        usort($catalog, fn($a, $b) => strcmp((string) $a['slug'], (string) $b['slug']));

        $families = [];
        foreach ($catalog as $family) {
            $versions = $family['versions'] ?? [];
            $latest = $versions ? end($versions) : null;
            $mine = $local[$family['guid']] ?? null;

            if (!$mine) {
                $status = get_string('catalog_notpulled', 'local_nucleusspoke');
            } else if ($latest && $mine['versionguid'] === $latest['guid']) {
                $status = get_string('catalog_uptodate', 'local_nucleusspoke');
            } else {
                $status = get_string('catalog_updatable', 'local_nucleusspoke');
            }

            $pullbutton = '';
            if ($latest && (!$mine || $mine['versionguid'] !== $latest['guid'])) {
                $pullbutton = $output->render(new single_button(
                    new moodle_url($this->pageurl, [
                        'pull' => 1,
                        'familyguid' => $family['guid'],
                        'versionguid' => $latest['guid'],
                    ]),
                    get_string('catalog_pulllatest', 'local_nucleusspoke', $latest['versionnumber']),
                    'post',
                    single_button::BUTTON_PRIMARY
                ));
            }

            $notes = [];
            foreach ($mine['notes'] ?? [] as $note) {
                $notes[] = [
                    'kind' => self::note_kind((string) ($note['kind'] ?? '')),
                    'detail' => (string) ($note['detail'] ?? ''),
                ];
            }

            $hubname = trim((string) ($family['hubcoursefullname'] ?? ''));
            $families[] = [
                'name' => $hubname !== '' ? format_string($hubname, true, ['escape' => false]) : $family['slug'],
                'slug' => $family['slug'],
                'haslatest' => (bool) $latest,
                'latest' => $latest ? get_string('catalog_latest', 'local_nucleusspoke', (object) [
                    'version' => $latest['versionnumber'],
                    'severity' => labels::severity((string) $latest['severity']),
                ]) : '',
                'published' => $latest ? get_string(
                    'catalog_publishedwhen',
                    'local_nucleusspoke',
                    format_time(time() - (int) $latest['timepublished'])
                ) : '',
                'locked' => $latest && !empty($latest['lockedforspokeedit']),
                'releasenotes' => $latest ? (string) ($latest['releasenotes'] ?? '') : '',
                'status' => $status,
                'pullbutton' => $pullbutton,
                'hasnotes' => !empty($notes),
                'notescount' => count($notes),
                'notes' => $notes,
            ];
        }

        return [
            'intro' => count($families) === 1
                ? get_string('catalog_intro_one', 'local_nucleusspoke')
                : get_string('catalog_intro_many', 'local_nucleusspoke', count($families)),
            'families' => $families,
            'versionsurl' => (new moodle_url('/local/nucleusspoke/versions.php'))->out(false),
        ];
    }
}
