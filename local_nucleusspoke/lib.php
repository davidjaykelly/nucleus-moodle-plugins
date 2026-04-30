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
 * Library hooks for local_nucleusspoke.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Spoke-side context widget rendered into the Nucleus status bar
 * (ADR-014 Phase 1, UI pass). Called from local_nucleuscommon's
 * status-bar renderer.
 *
 * Shows the current course's instance metadata + pending-notification
 * count for the same family. Returns null when the current page has
 * no spoke instance.
 *
 * @return array|null ['segments' => [...], 'panel' => '...', 'actions' => [...]]
 */
function local_nucleusspoke_statusbar_widget(): ?array {
    global $PAGE, $DB;

    if (strpos((string) $PAGE->pagetype, 'course-view-') !== 0) {
        return null;
    }
    if (!has_capability('local/nucleusspoke:pull', context_system::instance())) {
        return null;
    }
    $courseid = (int) ($PAGE->course->id ?? 0);
    if ($courseid === 0 || $courseid === (int) SITEID) {
        return null;
    }

    $instance = $DB->get_record(
        'local_nucleusspoke_instance',
        ['localcourseid' => $courseid]
    );
    if (!$instance) {
        return null;
    }
    $family = $DB->get_record(
        'local_nucleuscommon_family',
        ['id' => $instance->familyid]
    );
    $version = $DB->get_record(
        'local_nucleuscommon_version',
        ['id' => $instance->versionid]
    );
    if (!$family || !$version) {
        return null;
    }

    $pendingcount = (int) $DB->count_records(
        'local_nucleusspoke_notification',
        ['familyid' => $instance->familyid, 'state' => 'pending']
    );
    // Newest pending notification — the candidate update we'll
    // surface as actionable. Older pendings are listed on the
    // versions dashboard.
    $latestpending = null;
    $latestpendingversion = null;
    if ($pendingcount > 0) {
        $rows = $DB->get_records(
            'local_nucleusspoke_notification',
            ['familyid' => $instance->familyid, 'state' => 'pending'],
            'timereceived DESC',
            '*',
            0,
            1
        );
        if ($rows) {
            $latestpending = reset($rows);
            $latestpendingversion = $DB->get_record(
                'local_nucleuscommon_version',
                ['id' => $latestpending->versionid]
            );
        }
    }

    // Segments (collapsed bar): family, current version, state,
    // optional pending bell, optional deprecated warning. Tones
    // mirror the hub side: brand for identity, ok/warn for state.
    $segments = [
        [
            'icon' => 'fa-cubes',
            'label' => $family->slug,
            'tone' => 'brand',
        ],
        [
            'icon' => 'fa-code-branch',
            'label' => 'v' . $version->versionnumber,
            'tone' => 'brand',
        ],
        [
            'icon' => $instance->state === 'active' ? 'fa-circle-play' : 'fa-circle-pause',
            'label' => ucfirst(str_replace('-', ' ', (string) $instance->state)),
            'tone' => $instance->state === 'active' ? 'ok' : 'warn',
        ],
    ];
    if ($pendingcount > 0) {
        $segments[] = [
            'icon' => 'fa-bell',
            'label' => get_string('statusbar_spoke_pending', 'local_nucleusspoke', $pendingcount),
            'tone' => 'warn',
        ];
    }
    if ((int) $version->deprecated === 1) {
        $segments[] = [
            'icon' => 'fa-triangle-exclamation',
            'label' => get_string('statusbar_spoke_deprecated', 'local_nucleusspoke'),
            'tone' => 'warn',
            'title' => (string) ($version->deprecatedreason ?? ''),
        ];
    }
    // Content-distribution lock segment — purple/accent tone so it
    // reads as a state badge rather than an alert. Only shown when
    // the local instance was pulled from a locked version.
    if ((int) ($version->lockedforspokeedit ?? 0) === 1) {
        $segments[] = [
            'icon' => 'fa-lock',
            'label' => get_string('statusbar_spoke_locked', 'local_nucleusspoke'),
            'tone' => 'accent',
            'title' => get_string('statusbar_spoke_locked_title', 'local_nucleusspoke'),
        ];
    }

    // Expanded panel — multi-card so it fills horizontal space on
    // wide screens and stacks on narrow ones. Cards mirror the hub
    // side conceptually: identity → running → updates → context.
    $cards = [];

    // Card 1 — family identity.
    $card1 = html_writer::tag(
        'h4',
        s($family->slug)
            . html_writer::tag('span', ' · ' . substr((string) $family->guid, 0, 8),
                ['class' => 'nsb-panel-version']),
        ['class' => 'nsb-panel-title']
    );
    $card1 .= html_writer::tag(
        'div',
        get_string('statusbar_spoke_familystate', 'local_nucleusspoke', (object) [
            'state' => ucfirst(str_replace('-', ' ', (string) $instance->state)),
            'when' => format_time(time() - (int) $instance->timepulled),
        ]),
        ['class' => 'nsb-panel-sub']
    );
    $card1 .= html_writer::tag(
        'div',
        get_string('statusbar_spoke_localcopy', 'local_nucleusspoke',
            format_string($PAGE->course->fullname)),
        ['class' => 'nsb-panel-sub']
    );
    $cards[] = $card1;

    // Card 2 — currently running version + release notes. Surfaces
    // the deprecation reason inline so operators don't have to hunt
    // for it on the versions page.
    $card2 = html_writer::tag('h4',
        get_string('statusbar_spoke_runningtitle', 'local_nucleusspoke'),
        ['class' => 'nsb-panel-title']);
    $card2 .= html_writer::tag(
        'div',
        get_string('statusbar_spoke_runningversion', 'local_nucleusspoke', (object) [
            'version' => s($version->versionnumber),
            'severity' => s($version->severity),
            'when' => format_time(time() - (int) $version->timepublished),
        ]),
        ['class' => 'nsb-panel-sub']
    );
    if (!empty($version->releasenotes)) {
        $card2 .= html_writer::tag(
            'pre',
            s($version->releasenotes),
            ['class' => 'nsb-panel-notes']
        );
    }
    if ((int) $version->deprecated === 1) {
        $card2 .= html_writer::tag(
            'div',
            get_string('statusbar_spoke_deprecated_reason', 'local_nucleusspoke',
                (string) ($version->deprecatedreason ?? '—')),
            ['class' => 'nsb-panel-callout']
        );
    }
    $cards[] = $card2;

    // Card 3 — update available. Only emitted when there's at least
    // one pending notification. Carries the actionable "Pull v1.1.0"
    // button via the action wiring below.
    if ($latestpending && $latestpendingversion) {
        $card3 = html_writer::tag('h4',
            get_string('statusbar_spoke_updatestitle', 'local_nucleusspoke'),
            ['class' => 'nsb-panel-title']);
        $card3 .= html_writer::tag(
            'div',
            get_string('statusbar_spoke_updateavailable', 'local_nucleusspoke', (object) [
                'version' => s($latestpendingversion->versionnumber),
                'severity' => s($latestpendingversion->severity),
                'when' => format_time(time() - (int) $latestpending->timereceived),
            ]),
            ['class' => 'nsb-panel-sub']
        );
        if (!empty($latestpendingversion->releasenotes)) {
            $card3 .= html_writer::tag(
                'pre',
                s($latestpendingversion->releasenotes),
                ['class' => 'nsb-panel-notes']
            );
        }
        if ($pendingcount > 1) {
            $card3 .= html_writer::tag(
                'div',
                get_string('statusbar_spoke_morepending', 'local_nucleusspoke',
                    $pendingcount - 1),
                ['class' => 'nsb-panel-sub']
            );
        }
        $cards[] = $card3;
    }

    // Primary action — the most useful thing to do from this state.
    // Pull when there's an update; otherwise just link to the
    // versions dashboard for history / manage / dismiss.
    if ($latestpending) {
        $pullurl = new moodle_url('/local/nucleusspoke/versions.php', [
            'pull' => $latestpending->id,
            'sesskey' => sesskey(),
        ]);
        $action = [
            'label' => get_string('statusbar_spoke_pullupdate', 'local_nucleusspoke',
                s($latestpendingversion ? $latestpendingversion->versionnumber : '?')),
            'url' => $pullurl->out(false),
            'icon' => 'fa-download',
            'primary' => true,
        ];
    } else {
        $action = [
            'label' => get_string('banner_viewversions', 'local_nucleusspoke'),
            'url' => (new moodle_url('/local/nucleusspoke/versions.php'))->out(false),
            'icon' => 'fa-list',
            'primary' => true,
        ];
    }

    // Top-of-body banner: surface the most-actionable state for
    // this federated course. Update-available wins over deprecated;
    // both are "you should pull the latest". No banner when state
    // is clean — the bottom bar's segments already convey that.
    $banner = null;
    if ($latestpending && $latestpendingversion) {
        $banner = [
            'tone' => 'warn',
            'icon' => 'fa-bell',
            'body' => get_string('banner_update_available', 'local_nucleusspoke', (object) [
                'version' => s($latestpendingversion->versionnumber),
                'severity' => s($latestpendingversion->severity),
                'when' => format_time(time() - (int) $latestpending->timereceived),
                'current' => s($version->versionnumber),
            ]),
            'action' => [
                'label' => get_string('statusbar_spoke_pullupdate', 'local_nucleusspoke',
                    s($latestpendingversion->versionnumber)),
                'url' => (new moodle_url('/local/nucleusspoke/versions.php', [
                    'pull' => $latestpending->id,
                    'sesskey' => sesskey(),
                ]))->out(false),
                'icon' => 'fa-download',
            ],
        ];
    } else if ((int) $version->deprecated === 1) {
        $banner = [
            'tone' => 'warn',
            'icon' => 'fa-triangle-exclamation',
            'body' => get_string('banner_running_deprecated', 'local_nucleusspoke', (object) [
                'version' => s($version->versionnumber),
                'reason' => (string) ($version->deprecatedreason ?? '—'),
            ]),
            'action' => [
                'label' => get_string('banner_viewversions', 'local_nucleusspoke'),
                'url' => (new moodle_url('/local/nucleusspoke/versions.php'))->out(false),
                'icon' => 'fa-list',
            ],
        ];
    } else if ((int) ($version->lockedforspokeedit ?? 0) === 1) {
        // Distinct tone (info, not warn) — locked is a state, not an
        // alert. Editing teachers seeing edit buttons disappear should
        // see *why* before they hit support.
        $banner = [
            'tone' => 'info',
            'icon' => 'fa-lock',
            'body' => get_string('banner_locked_body', 'local_nucleusspoke', (object) [
                'version' => s($version->versionnumber),
            ]),
        ];
    }

    return [
        'segments' => $segments,
        'panel' => $cards,
        'actions' => [$action],
        'banner' => $banner,
    ];
}
