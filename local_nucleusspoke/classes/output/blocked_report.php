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

use local_nucleuscommon\local\site;
use moodle_url;
use renderable;
use renderer_base;
use single_button;
use templatable;

/**
 * Why a pull was refused: the plugins the course needs, what to do,
 * and a way to try again.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class blocked_report implements renderable, templatable {
    /** @var array Family from the catalogue (guid, slug). */
    private array $family;

    /** @var array Version from the catalogue (guid, versionnumber). */
    private array $version;

    /** @var array The structured payload from puller's dependencyblocked exception. */
    private array $payload;

    /** @var moodle_url The catalogue page. */
    private moodle_url $pageurl;

    /**
     * Constructor.
     *
     * @param array $family
     * @param array $version
     * @param array $payload blockers, mod_status, notes.
     * @param moodle_url $pageurl
     */
    public function __construct(array $family, array $version, array $payload, moodle_url $pageurl) {
        $this->family = $family;
        $this->version = $version;
        $this->payload = $payload;
        $this->pageurl = $pageurl;
    }

    /**
     * Template context.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $modules = [];
        foreach ((array) ($this->payload['mod_status'] ?? []) as $mod) {
            $state = (string) ($mod['state'] ?? 'present');
            if (!in_array($state, ['present', 'missing', 'older'], true)) {
                $state = 'present';
            }
            $expected = (int) ($mod['expected_version'] ?? 0);
            $local = $mod['spoke_version'] ?? null;
            $modules[] = [
                'plugin' => 'mod_' . (string) ($mod['name'] ?? ''),
                'state' => get_string('catalog_mod_state_' . $state, 'local_nucleusspoke'),
                'missing' => $state === 'missing',
                'older' => $state === 'older',
                'needs' => $expected > 0 ? (string) $expected : get_string('catalog_mod_anyversion', 'local_nucleusspoke'),
                'local' => $state === 'missing'
                    ? get_string('catalog_mod_notinstalled', 'local_nucleusspoke')
                    : (string) (int) $local,
            ];
        }

        $blockers = [];
        foreach ((array) ($this->payload['blockers'] ?? []) as $blocker) {
            $blockers[] = [
                'detail' => (string) ($blocker['detail'] ?? ''),
                'remedy' => (string) ($blocker['remediation'] ?? ''),
            ];
        }
        $notes = array_map(fn($note) => ['text' => (string) $note], array_values((array) ($this->payload['notes'] ?? [])));

        $retry = new single_button(
            new moodle_url($this->pageurl, [
                'pull' => 1,
                'familyguid' => $this->family['guid'],
                'versionguid' => $this->version['guid'],
            ]),
            get_string('catalog_blocked_retry', 'local_nucleusspoke'),
            'post',
            single_button::BUTTON_PRIMARY
        );

        return [
            'title' => get_string('catalog_blocked_title', 'local_nucleusspoke', (object) [
                'slug' => $this->family['slug'],
                'version' => $this->version['versionnumber'],
            ]),
            'intro' => site::is_hosted()
                ? get_string('catalog_blocked_sub_hosted', 'local_nucleusspoke')
                : get_string('catalog_blocked_sub', 'local_nucleusspoke'),
            'hasmodules' => !empty($modules),
            'modules' => $modules,
            'hasblockers' => !empty($blockers),
            'blockers' => $blockers,
            'hasnotes' => !empty($notes),
            'notes' => $notes,
            'retrybutton' => $output->render($retry),
            'backurl' => $this->pageurl->out(false),
        ];
    }
}
