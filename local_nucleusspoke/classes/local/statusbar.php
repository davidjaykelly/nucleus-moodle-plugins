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

namespace local_nucleusspoke\local;

use local_nucleuscommon\local\site;
use moodle_page;
use moodle_url;

/**
 * What a pulled course's page says about its version: the Nucleus
 * bar's widget and the "update available" notice.
 *
 * Returns plain data (text and URLs). local_nucleuscommon renders it.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class statusbar {
    /**
     * The pulled course's state, or null when the page isn't a pulled
     * course's page the current user can pull on.
     *
     * @param moodle_page $page
     * @return \stdClass|null {course, instance, family, version, pendingcount, latest, latestversion}
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
        if (!site::can_pull()) {
            return null;
        }
        $instance = $DB->get_record('local_nucleusspoke_instance', ['localcourseid' => $courseid]);
        if (!$instance) {
            return null;
        }
        $family = $DB->get_record('local_nucleuscommon_family', ['id' => $instance->familyid]);
        $version = $DB->get_record('local_nucleuscommon_version', ['id' => $instance->versionid]);
        if (!$family || !$version) {
            return null;
        }

        $state = (object) [
            'course' => $page->course,
            'instance' => $instance,
            'family' => $family,
            'version' => $version,
            'pendingcount' => $DB->count_records(
                'local_nucleusspoke_notification',
                ['familyid' => $instance->familyid, 'state' => 'pending']
            ),
            'latest' => null,
            'latestversion' => null,
        ];
        if ($state->pendingcount > 0) {
            // The newest waiting update is the one to offer; older ones
            // are listed on the Course versions page.
            $rows = $DB->get_records(
                'local_nucleusspoke_notification',
                ['familyid' => $instance->familyid, 'state' => 'pending'],
                'timereceived DESC',
                '*',
                0,
                1
            );
            $state->latest = $rows ? reset($rows) : null;
            if ($state->latest) {
                $state->latestversion = $DB->get_record(
                    'local_nucleuscommon_version',
                    ['id' => $state->latest->versionid]
                ) ?: null;
            }
        }
        return $state;
    }

    /**
     * The Nucleus bar widget for a pulled course's page.
     *
     * @param moodle_page $page
     * @return array|null ['segments' => [['text', 'attention']], 'action' => ['label', 'url'],
     *                    'rows' => [['title', 'lines', 'notes', 'callout']]]
     */
    public static function widget(moodle_page $page): ?array {
        $state = self::course_state($page);
        if (!$state) {
            // Off course pages, the spoke at a glance; on a course page that
            // isn't from the hub, or that the user can't pull for, nothing.
            return str_starts_with((string) $page->pagetype, 'course-view-') ? null : self::site_widget();
        }
        $version = $state->version;
        $deprecated = (int) $version->deprecated === 1;
        $locked = (int) ($version->lockedforspokeedit ?? 0) === 1;
        $statelabel = labels::instance_state((string) $state->instance->state);

        $segments = [
            ['text' => $state->family->slug, 'attention' => false],
            ['text' => 'v' . $version->versionnumber, 'attention' => false],
            ['text' => $statelabel, 'attention' => false],
        ];
        if ($state->pendingcount > 0) {
            $segments[] = [
                'text' => get_string('statusbar_spoke_pending', 'local_nucleusspoke', $state->pendingcount),
                'attention' => true,
            ];
        }
        if ($deprecated) {
            $segments[] = ['text' => get_string('statusbar_spoke_deprecated', 'local_nucleusspoke'), 'attention' => true];
        }
        if ($locked) {
            $segments[] = ['text' => get_string('statusbar_spoke_locked', 'local_nucleusspoke'), 'attention' => false];
        }

        $rows = [];
        $rows[] = [
            'title' => $state->family->slug,
            'lines' => [
                get_string('statusbar_spoke_familystate', 'local_nucleusspoke', (object) [
                    'state' => $statelabel,
                    'when' => format_time(time() - (int) $state->instance->timepulled),
                ]),
                get_string(
                    'statusbar_spoke_localcopy',
                    'local_nucleusspoke',
                    format_string($state->course->fullname, true, ['escape' => false])
                ),
            ],
        ];

        $runninglines = [get_string('statusbar_spoke_runningversion', 'local_nucleusspoke', (object) [
            'version' => $version->versionnumber,
            'severity' => labels::severity((string) $version->severity),
            'when' => format_time(time() - (int) $version->timepublished),
        ])];
        if ($locked) {
            $runninglines[] = get_string('statusbar_spoke_locked_title', 'local_nucleusspoke');
        }
        $rows[] = [
            'title' => get_string('statusbar_spoke_runningtitle', 'local_nucleusspoke'),
            'lines' => $runninglines,
            'notes' => (string) ($version->releasenotes ?? ''),
            'callout' => $deprecated
                ? get_string(
                    'statusbar_spoke_deprecated_reason',
                    'local_nucleusspoke',
                    self::reason((string) ($version->deprecatedreason ?? ''))
                )
                : '',
        ];

        if ($state->latest && $state->latestversion) {
            $lines = [get_string('statusbar_spoke_updateavailable', 'local_nucleusspoke', (object) [
                'version' => $state->latestversion->versionnumber,
                'severity' => labels::severity((string) $state->latestversion->severity),
                'when' => format_time(time() - (int) $state->latest->timereceived),
            ])];
            if ($state->pendingcount > 1) {
                $lines[] = get_string('statusbar_spoke_morepending', 'local_nucleusspoke', $state->pendingcount - 1);
            }
            $rows[] = [
                'title' => get_string('statusbar_spoke_updatestitle', 'local_nucleusspoke'),
                'lines' => $lines,
                'notes' => (string) ($state->latestversion->releasenotes ?? ''),
            ];
            $action = [
                'label' => get_string('statusbar_spoke_pullupdate', 'local_nucleusspoke', $state->latestversion->versionnumber),
                'url' => self::pull_url((int) $state->latest->id),
            ];
        } else {
            $action = [
                'label' => get_string('versions_title', 'local_nucleusspoke'),
                'url' => new moodle_url('/local/nucleusspoke/versions.php'),
            ];
        }

        return [
            'segments' => $segments,
            'action' => $action,
            'rows' => $rows,
        ];
    }

    /**
     * The notice at the top of a pulled course's page when a newer
     * version is waiting, or when the hub has deprecated this one.
     *
     * @param moodle_page $page
     * @return array|null ['message' => string (plain text), 'action' => ['label', 'url']]
     */
    public static function banner(moodle_page $page): ?array {
        $state = self::course_state($page);
        if (!$state) {
            return null;
        }
        if ($state->latest && $state->latestversion) {
            return [
                'message' => get_string('banner_update_available', 'local_nucleusspoke', (object) [
                    'version' => $state->latestversion->versionnumber,
                    'severity' => labels::severity((string) $state->latestversion->severity),
                    'current' => $state->version->versionnumber,
                ]),
                'action' => [
                    'label' => get_string(
                        'statusbar_spoke_pullupdate',
                        'local_nucleusspoke',
                        $state->latestversion->versionnumber
                    ),
                    'url' => self::pull_url((int) $state->latest->id),
                ],
            ];
        }
        if ((int) $state->version->deprecated === 1) {
            return [
                'message' => get_string('banner_running_deprecated', 'local_nucleusspoke', (object) [
                    'version' => $state->version->versionnumber,
                    'reason' => self::reason((string) ($state->version->deprecatedreason ?? '')),
                ]),
                'action' => [
                    'label' => get_string('banner_viewversions', 'local_nucleusspoke'),
                    'url' => new moodle_url('/local/nucleusspoke/versions.php'),
                ],
            ];
        }
        return null;
    }

    /**
     * Where "Pull vX" goes: the Course versions page asks to confirm
     * before anything is pulled.
     *
     * @param int $notificationid
     * @return moodle_url
     */
    public static function pull_url(int $notificationid): moodle_url {
        return new moodle_url('/local/nucleusspoke/versions.php', ['action' => 'pull', 'id' => $notificationid]);
    }

    /**
     * The hub's reason for deprecating a version, or a word when it gave none.
     *
     * @param string $reason
     * @return string
     */
    private static function reason(string $reason): string {
        $reason = trim($reason);
        return $reason !== '' ? $reason : get_string('deprecated_noreason', 'local_nucleusspoke');
    }

    /**
     * The spoke at a glance, for pages that aren't a course: how many
     * courses came from the hub, and how many new versions are waiting.
     *
     * @return array|null The widget, or null when the user can't pull.
     */
    public static function site_widget(): ?array {
        global $DB;

        if (!site::can_pull()) {
            return null;
        }
        $fromhub = $DB->count_records('local_nucleusspoke_instance');
        $waiting = $DB->count_records('local_nucleusspoke_notification', ['state' => 'pending']);

        $segments = [['text' => get_string('statusbar_spoke_site_fromhub', 'local_nucleusspoke', $fromhub), 'attention' => false]];
        $segments[] = $waiting > 0
            ? ['text' => get_string('statusbar_spoke_pending', 'local_nucleusspoke', $waiting), 'attention' => true]
            : ['text' => get_string('statusbar_spoke_site_clean', 'local_nucleusspoke'), 'attention' => false];

        return [
            'segments' => $segments,
            'action' => $waiting > 0
                ? [
                    'label' => get_string('versions_title', 'local_nucleusspoke'),
                    'url' => new moodle_url('/local/nucleusspoke/versions.php'),
                ]
                : [
                    'label' => get_string('catalog_title', 'local_nucleusspoke'),
                    'url' => new moodle_url('/local/nucleusspoke/catalog.php'),
                ],
            'rows' => [[
                'title' => get_string('statusbar_spoke_site_title', 'local_nucleusspoke'),
                'lines' => [
                    get_string('statusbar_spoke_site_fromhub', 'local_nucleusspoke', $fromhub),
                    $waiting > 0
                        ? get_string('statusbar_spoke_site_waiting', 'local_nucleusspoke', $waiting)
                        : get_string('statusbar_spoke_site_clean', 'local_nucleusspoke'),
                ],
            ]],
        ];
    }
}
