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

namespace local_nucleuscommon\output;

use local_nucleuscommon\local\site;
use moodle_page;
use moodle_url;
use renderable;
use renderer_base;
use templatable;

/**
 * The Nucleus bar: a small band at the bottom of course pages and
 * Nucleus pages, for the people who run the Nucleus side of a site.
 *
 * The hub and spoke plugins describe the current course through
 * \local_nucleushub\local\statusbar::widget() and
 * \local_nucleusspoke\local\statusbar::widget(). Those return plain
 * data (text and URLs, never HTML); this class adds the site facts and
 * turns it all into a template context.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class statusbar implements renderable, templatable {
    /** @var string[] Page layouts without normal page chrome: no bar there. */
    public const EXCLUDED_LAYOUTS = [
        'embedded', 'frametop', 'login', 'maintenance', 'popup', 'print', 'redirect', 'secure',
    ];

    /** @var string[] Widget providers, in display order. */
    private const PROVIDERS = [
        '\local_nucleushub\local\statusbar',
        '\local_nucleusspoke\local\statusbar',
    ];

    /** @var moodle_page The page the bar describes. */
    private moodle_page $page;

    /**
     * Constructor.
     *
     * @param moodle_page $page The page the bar describes.
     */
    public function __construct(moodle_page $page) {
        $this->page = $page;
    }

    /**
     * Should the bar appear on this page for the current user?
     *
     * Only course pages and Nucleus's own pages, only for people who
     * can publish (hub) or pull (spoke), and never in layouts without
     * normal page chrome.
     *
     * @param moodle_page $page
     * @return bool
     */
    public static function should_show(moodle_page $page): bool {
        if (during_initial_install() || !isloggedin() || isguestuser()) {
            return false;
        }
        if (in_array($page->pagelayout, self::EXCLUDED_LAYOUTS, true)) {
            return false;
        }
        $pagetype = (string) $page->pagetype;
        $iscoursepage = str_starts_with($pagetype, 'course-view-');
        $isnucleuspage = str_contains($pagetype, 'local-nucleus') || str_contains($pagetype, 'local_nucleus');
        if (!$iscoursepage && !$isnucleuspage) {
            return false;
        }
        return site::is_operator();
    }

    /**
     * Collect what the hub and spoke plugins have to say about this page.
     *
     * @return array[] Widget arrays (see the providers' docblocks).
     */
    private function widgets(): array {
        $widgets = [];
        foreach (self::PROVIDERS as $class) {
            if (!class_exists($class)) {
                continue;
            }
            $widget = $class::widget($this->page);
            if ($widget) {
                $widgets[] = $widget;
            }
        }
        return $widgets;
    }

    /**
     * Template context.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $federationid = (string) get_config('local_nucleuscommon', 'federationid');

        $segments = [];
        $actions = [];
        $rows = [];

        $widgets = $this->widgets();
        foreach ($widgets as $widget) {
            foreach ($widget['segments'] ?? [] as $segment) {
                $segments[] = [
                    'text' => (string) $segment['text'],
                    'attention' => !empty($segment['attention']),
                ];
            }
            if (!empty($widget['action'])) {
                $actions[] = [
                    'label' => (string) $widget['action']['label'],
                    'url' => (new moodle_url($widget['action']['url']))->out(false),
                    'primary' => true,
                    'external' => false,
                ];
            }
            foreach ($widget['rows'] ?? [] as $row) {
                $rows[] = self::export_row($row);
            }
        }

        // The site itself, always last in the panel.
        $sitelines = [
            get_string('statusbar_role', 'local_nucleuscommon', site::is_spoke()
                ? get_string('role_spoke', 'local_nucleuscommon')
                : get_string('role_hub', 'local_nucleuscommon')),
            get_string('statusbar_federationid', 'local_nucleuscommon', $federationid !== ''
                ? $federationid
                : get_string('statusbar_nofederation', 'local_nucleuscommon')),
        ];
        if (!$widgets) {
            $sitelines[] = get_string('statusbar_panel_empty', 'local_nucleuscommon');
        }
        $rows[] = self::export_row([
            'title' => get_string('statusbar_thissite', 'local_nucleuscommon'),
            'lines' => $sitelines,
        ]);

        $portal = trim((string) get_config('local_nucleuscommon', 'cpportalurl'));
        if ($portal !== '') {
            $actions[] = [
                'label' => get_string('statusbar_portal', 'local_nucleuscommon'),
                'url' => (new moodle_url(rtrim($portal, '/')))->out(false),
                'primary' => false,
                'external' => true,
            ];
        }

        $context = [
            'segments' => $segments,
            'actions' => $actions,
            'rows' => $rows,
        ];
        $context['hash'] = sha1(json_encode($context));
        $context['statusurl'] = (new moodle_url('/local/nucleuscommon/status.php', [
            'pagetype' => (string) $this->page->pagetype,
            'courseid' => (int) ($this->page->course->id ?? 0),
        ]))->out(false);
        return $context;
    }

    /**
     * Shape one panel row for the template.
     *
     * @param array $row ['title' => string, 'lines' => string[], 'notes' => ?string, 'callout' => ?string]
     * @return array
     */
    private static function export_row(array $row): array {
        return [
            'title' => (string) ($row['title'] ?? ''),
            'lines' => array_map(fn($line) => ['text' => (string) $line], array_values($row['lines'] ?? [])),
            'notes' => (string) ($row['notes'] ?? ''),
            'callout' => (string) ($row['callout'] ?? ''),
        ];
    }
}
