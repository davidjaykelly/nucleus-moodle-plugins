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

namespace local_nucleushub\local;

use local_nucleuscommon\local\site;
use local_nucleushub\version\severity_hint;
use moodle_page;
use moodle_url;

/**
 * What a hub course page says about the course's place in the
 * federation: the Nucleus bar's widget and the "changes to publish"
 * notice.
 *
 * Returns plain data (text and URLs). local_nucleuscommon renders it.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class statusbar {
    /** @var string[] Kinds of change the change tracker records, in display order. */
    private const CHANGE_KINDS = [
        'module_added', 'module_updated', 'module_deleted',
        'section_added', 'section_updated', 'section_deleted',
        'file_replaced', 'course_updated',
    ];

    /**
     * The course's federation state, or null when the page isn't a hub
     * course page the current user can publish from.
     *
     * @param moodle_page $page
     * @return \stdClass|null {course, draft, family, lastversion, pending}
     */
    public static function course_state(moodle_page $page): ?\stdClass {
        global $DB;

        if (!str_starts_with((string) $page->pagetype, 'course-view-')) {
            return null;
        }
        $courseid = (int) ($page->course->id ?? 0);
        if ($courseid <= 0 || $courseid === (int) SITEID) {
            return null;
        }
        if (!site::can_publish()) {
            return null;
        }

        $state = (object) [
            'course' => $page->course,
            'draft' => $DB->get_record('local_nucleushub_draft', ['hubcourseid' => $courseid]) ?: null,
            'family' => null,
            'lastversion' => null,
            'pending' => 0,
        ];
        if ($state->draft) {
            $state->family = $DB->get_record('local_nucleuscommon_family', ['id' => $state->draft->familyid]) ?: null;
            $state->pending = (int) $state->draft->pendingchangecount;
            if ($state->draft->lastpublishversionid) {
                $state->lastversion = $DB->get_record(
                    'local_nucleuscommon_version',
                    ['id' => $state->draft->lastpublishversionid]
                ) ?: null;
            }
        }
        return $state;
    }

    /**
     * The Nucleus bar widget for a hub course page.
     *
     * @param moodle_page $page
     * @return array|null ['segments' => [['text', 'attention']], 'action' => ['label', 'url'],
     *                    'rows' => [['title', 'lines', 'notes', 'callout']]]
     */
    public static function widget(moodle_page $page): ?array {
        global $DB;

        $state = self::course_state($page);
        if (!$state) {
            return null;
        }
        $courseid = (int) $state->course->id;

        if (!$state->family) {
            $rows = [[
                'title' => get_string('statusbar_hub_notinfederation', 'local_nucleushub'),
                'lines' => [get_string('statusbar_hub_notinfederation_hint', 'local_nucleushub')],
            ]];
            try {
                $modinfo = get_fast_modinfo($state->course);
                $rows[] = [
                    'title' => get_string('statusbar_hub_coursetitle', 'local_nucleushub'),
                    'lines' => [get_string('statusbar_hub_coursesummary', 'local_nucleushub', (object) [
                        'sections' => count($modinfo->get_section_info_all()),
                        'modules' => count($modinfo->get_cms()),
                    ])],
                ];
            } catch (\Throwable $e) {
                // The content summary is only context; leave it out.
                debugging('Nucleus bar: course summary failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            return [
                'segments' => [[
                    'text' => get_string('statusbar_hub_notinfederation', 'local_nucleushub'),
                    'attention' => true,
                ]],
                'action' => [
                    'label' => get_string('statusbar_hub_addtofederation', 'local_nucleushub'),
                    'url' => new moodle_url('/local/nucleushub/promote.php', ['courseid' => $courseid]),
                ],
                'rows' => $rows,
            ];
        }

        $segments = [['text' => $state->family->slug, 'attention' => false]];
        if ($state->lastversion) {
            $segments[] = ['text' => 'v' . $state->lastversion->versionnumber, 'attention' => false];
        }
        if ($state->pending > 0) {
            $segments[] = [
                'text' => get_string('statusbar_hub_pending', 'local_nucleushub', $state->pending),
                'attention' => true,
            ];
        } else if ($state->lastversion) {
            $segments[] = ['text' => get_string('statusbar_hub_clean', 'local_nucleushub'), 'attention' => false];
        }

        $rows = [];
        $rows[] = [
            'title' => $state->family->slug,
            'lines' => [
                get_string(
                    'statusbar_hub_familycourse',
                    'local_nucleushub',
                    format_string($state->course->fullname, true, ['escape' => false])
                ),
                get_string('statusbar_hub_familymeta', 'local_nucleushub', (object) [
                    'count' => $DB->count_records('local_nucleuscommon_version', ['familyid' => $state->family->id]),
                    'when' => format_time(time() - (int) $state->family->timecreated),
                ]),
            ],
        ];

        if ($state->lastversion) {
            $rows[] = [
                'title' => get_string('statusbar_hub_lastpub_title', 'local_nucleushub'),
                'lines' => [get_string('statusbar_hub_lastpub', 'local_nucleushub', (object) [
                    'version' => $state->lastversion->versionnumber,
                    'severity' => self::severity_label((string) $state->lastversion->severity),
                    'when' => format_time(time() - (int) $state->lastversion->timepublished),
                ])],
                'notes' => (string) ($state->lastversion->releasenotes ?? ''),
            ];
        } else {
            $rows[] = [
                'title' => get_string('statusbar_hub_lastpub_title', 'local_nucleushub'),
                'lines' => [get_string('statusbar_hub_nopublishes', 'local_nucleushub')],
            ];
        }

        if ($state->pending > 0) {
            $hint = severity_hint::for_family((int) $state->family->id);
            $rows[] = [
                'title' => get_string('statusbar_hub_pendingtitle', 'local_nucleushub', $state->pending),
                'lines' => self::change_lines($hint['counts'] ?? []),
                'callout' => !empty($hint['suggested'])
                    ? get_string('severity_suggested', 'local_nucleushub', (object) [
                        'severity' => self::severity_label((string) $hint['suggested']),
                        'rationale' => (string) ($hint['rationale'] ?? ''),
                    ])
                    : '',
            ];
        }

        $spokes = $DB->count_records('local_nucleushub_spokes', ['status' => 'active']);
        $rows[] = [
            'title' => get_string('statusbar_hub_spokestitle', 'local_nucleushub'),
            'lines' => [$spokes > 0
                ? get_string('statusbar_hub_spokesregistered', 'local_nucleushub', $spokes)
                : get_string('statusbar_hub_nospokes', 'local_nucleushub')],
        ];

        return [
            'segments' => $segments,
            'action' => [
                'label' => get_string('publishversion', 'local_nucleushub'),
                'url' => new moodle_url('/local/nucleushub/publish.php', ['id' => $courseid]),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * The notice at the top of a hub course page with changes that
     * haven't been published yet.
     *
     * @param moodle_page $page
     * @return array|null ['message' => string (plain text), 'action' => ['label', 'url']]
     */
    public static function banner(moodle_page $page): ?array {
        $state = self::course_state($page);
        if (!$state || !$state->family || $state->pending <= 0) {
            return null;
        }
        if ($state->lastversion) {
            $key = $state->pending === 1 ? 'banner_dirty_one' : 'banner_dirty_many';
            $message = get_string($key, 'local_nucleushub', (object) [
                'count' => $state->pending,
                'version' => $state->lastversion->versionnumber,
                'when' => format_time(time() - (int) $state->lastversion->timepublished),
            ]);
        } else {
            $message = get_string('banner_dirty_unpublished', 'local_nucleushub', $state->pending);
        }
        return [
            'message' => $message,
            'action' => [
                'label' => get_string('publishversion', 'local_nucleushub'),
                'url' => new moodle_url('/local/nucleushub/publish.php', ['id' => (int) $state->course->id]),
            ],
        ];
    }

    /**
     * Words for a type of change (patch, minor, major).
     *
     * @param string $severity
     * @return string
     */
    public static function severity_label(string $severity): string {
        if (in_array($severity, ['patch', 'minor', 'major'], true)) {
            return get_string('severity_' . $severity, 'local_nucleuscommon');
        }
        return $severity;
    }

    /**
     * One line per kind of change, e.g. "Activities added: 2".
     *
     * @param array $counts eventkind => count, from severity_hint.
     * @return string[]
     */
    public static function change_lines(array $counts): array {
        $lines = [];
        foreach (self::CHANGE_KINDS as $kind) {
            if (!empty($counts[$kind])) {
                $lines[] = get_string('changekind_' . $kind, 'local_nucleushub', (int) $counts[$kind]);
            }
        }
        $other = array_sum(array_diff_key($counts, array_flip(self::CHANGE_KINDS)));
        if ($other > 0) {
            $lines[] = get_string('changekind_other', 'local_nucleushub', $other);
        }
        return $lines;
    }
}
