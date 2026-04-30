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
 * Library hooks for local_nucleushub.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Inject a "Publish a version" link into the course admin menu for
 * users with local/nucleushub:publish. Keeps the new page
 * discoverable without bolt-on dashboard widgets.
 *
 * @param settings_navigation $nav
 * @param context $context
 */
function local_nucleushub_extend_settings_navigation(
    settings_navigation $nav,
    context $context
): void {
    if (!($context instanceof context_course)) {
        return;
    }
    if (!has_capability('local/nucleushub:publish', context_system::instance())) {
        return;
    }
    // The hub plugin is baked into every Moodle image (hub + spoke
    // share a Dockerfile), so capability alone isn't enough — a
    // Moodle wired as a spoke shouldn't surface "Publish a version"
    // anywhere. `hubwwwroot` set on local_nucleusspoke means we point
    // at another hub, i.e. we ARE a spoke. Skip in that case.
    if ((string) get_config('local_nucleusspoke', 'hubwwwroot') !== '') {
        return;
    }
    // Front page isn't a versionable "course" in any useful sense.
    if ($context->instanceid == SITEID) {
        return;
    }
    $settingsnode = $nav->find('courseadmin', navigation_node::TYPE_COURSE);
    if (!$settingsnode) {
        return;
    }
    $settingsnode->add(
        get_string('publishversion', 'local_nucleushub'),
        new moodle_url('/local/nucleushub/publish.php', ['id' => $context->instanceid]),
        navigation_node::TYPE_SETTING,
        null,
        'nucleushub_publishversion',
        new pix_icon('i/upload', '')
    );
}

/**
 * Hub-side context widget rendered into the Nucleus status bar
 * (ADR-014 Phase 1, UI pass). Called by `local_nucleuscommon`'s
 * status-bar renderer — returns an array describing the hub's
 * state for the current page, or null when not applicable.
 *
 * Kept as a plain function (not a hook) because local_nucleuscommon
 * is a sibling plugin, not a Moodle core boundary.
 *
 * @return array|null ['segments' => [...], 'panel' => '...', 'actions' => [...]]
 */
function local_nucleushub_statusbar_widget(): ?array {
    global $PAGE, $DB;

    // Only show on course-view pages and only when the caller has
    // publish rights (hub_admin+).
    if (strpos((string) $PAGE->pagetype, 'course-view-') !== 0) {
        return null;
    }
    if (!has_capability('local/nucleushub:publish', context_system::instance())) {
        return null;
    }
    // The hub plugin ships in every Moodle image (the Dockerfile
    // bakes both plugins in regardless of the pod's role), so a
    // Moodle wired as a spoke would otherwise see "Publish a version"
    // and "Add to federation" injected on every course page. The
    // canonical "am I a spoke?" check is `hubwwwroot` being set —
    // when present, this Moodle points at another hub and is
    // therefore a spoke; the hub-side widgets must stay quiet.
    // Same gate as nucleuscommon/lib.php uses for the global nav.
    if ((string) get_config('local_nucleusspoke', 'hubwwwroot') !== '') {
        return null;
    }
    // We don't gate on spoke count: a fresh hub with no spokes still
    // needs to promote courses + publish first versions before any
    // spoke can pull. The widget is per-course-page, not per-fanout.
    $courseid = (int) ($PAGE->course->id ?? 0);
    if ($courseid === 0 || $courseid === (int) SITEID) {
        return null;
    }

    $draft = $DB->get_record('local_nucleushub_draft', ['hubcourseid' => $courseid]);
    $lastversion = null;
    if ($draft && $draft->lastpublishversionid) {
        $lastversion = $DB->get_record(
            'local_nucleuscommon_version',
            ['id' => $draft->lastpublishversionid]
        );
    }
    $family = null;
    if ($draft) {
        $family = $DB->get_record('local_nucleuscommon_family', ['id' => $draft->familyid]);
    }

    $publishurl = (new moodle_url('/local/nucleushub/publish.php', ['id' => $courseid]))->out(false);
    $promoteurl = (new moodle_url('/local/nucleushub/promote.php', ['courseid' => $courseid]))->out(false);

    // Segments render inline in the collapsed bar; each is a
    // compact label with optional state colour.
    $segments = [];
    if (!$family) {
        $segments[] = [
            'icon' => 'fa-circle-exclamation',
            'label' => get_string('statusbar_hub_notinfederation', 'local_nucleushub'),
            'tone' => 'warn',
        ];
    } else {
        $segments[] = [
            'icon' => 'fa-cubes',
            'label' => $family->slug,
            'tone' => 'brand',
        ];
        if ($lastversion) {
            $segments[] = [
                'icon' => 'fa-code-branch',
                'label' => 'v' . $lastversion->versionnumber,
                'tone' => 'brand',
            ];
        }
        $pending = (int) ($draft->pendingchangecount ?? 0);
        if ($pending > 0) {
            $segments[] = [
                'icon' => 'fa-pen',
                'label' => get_string('statusbar_hub_pending', 'local_nucleushub', $pending),
                'tone' => 'warn',
            ];
        } else if ($lastversion) {
            $segments[] = [
                'icon' => 'fa-check',
                'label' => get_string('statusbar_hub_clean', 'local_nucleushub'),
                'tone' => 'ok',
            ];
        }
    }

    // Expanded panel — split into multiple cards so the responsive
    // grid (auto-fill, minmax 320px) fills horizontal space on wide
    // screens and stacks gracefully on narrow ones.
    $cards = [];
    if ($family) {
        // Card 1 — family identity. Slug + truncated GUID + creation
        // age + version count. Stable across publishes.
        $versioncount = (int) $DB->count_records(
            'local_nucleuscommon_version',
            ['familyid' => $family->id]
        );
        $card1 = html_writer::tag(
            'h4',
            s($family->slug)
                . html_writer::tag('span', ' · ' . substr((string) $family->guid, 0, 8),
                    ['class' => 'nsb-panel-version']),
            ['class' => 'nsb-panel-title']
        );
        $card1 .= html_writer::tag(
            'div',
            get_string('statusbar_hub_familymeta', 'local_nucleushub', (object) [
                'count' => $versioncount,
                'when' => format_time(time() - (int) $family->timecreated),
            ]),
            ['class' => 'nsb-panel-sub']
        );
        $card1 .= html_writer::tag(
            'div',
            get_string('statusbar_hub_familycourse', 'local_nucleushub',
                format_string($PAGE->course->fullname)),
            ['class' => 'nsb-panel-sub']
        );
        $cards[] = $card1;

        // Card 2 — last publish. The "what's actually live for
        // spokes right now" view. Empty-state still gets a card so
        // the column count stays even.
        $card2 = html_writer::tag('h4',
            get_string('statusbar_hub_lastpub_title', 'local_nucleushub'),
            ['class' => 'nsb-panel-title']);
        if ($lastversion) {
            $card2 .= html_writer::tag(
                'div',
                get_string('statusbar_hub_lastpub', 'local_nucleushub', (object) [
                    'version' => s($lastversion->versionnumber),
                    'severity' => s($lastversion->severity),
                    'when' => format_time(time() - (int) $lastversion->timepublished),
                ]),
                ['class' => 'nsb-panel-sub']
            );
            if (!empty($lastversion->releasenotes)) {
                $card2 .= html_writer::tag(
                    'pre',
                    s($lastversion->releasenotes),
                    ['class' => 'nsb-panel-notes']
                );
            }
        } else {
            $card2 .= html_writer::tag(
                'div',
                get_string('statusbar_hub_nopublishes', 'local_nucleushub'),
                ['class' => 'nsb-panel-sub']
            );
        }
        $cards[] = $card2;

        // Card 3 — pending changes + severity hint. Only emitted
        // when there are pending edits; otherwise the card slot
        // disappears and the grid reflows.
        $pending = (int) ($draft->pendingchangecount ?? 0);
        if ($pending > 0) {
            $hint = \local_nucleushub\version\severity_hint::for_family((int) $draft->familyid);
            $card3 = html_writer::tag('h4',
                get_string('statusbar_hub_pendingtitle', 'local_nucleushub', $pending),
                ['class' => 'nsb-panel-title']);
            $breakdown = local_nucleushub_format_change_counts($hint['counts'] ?? []);
            if ($breakdown !== '') {
                $card3 .= html_writer::tag(
                    'div',
                    s($breakdown),
                    ['class' => 'nsb-panel-sub']
                );
            }
            if (!empty($hint['suggested'])) {
                $card3 .= html_writer::tag(
                    'div',
                    get_string('severity_suggested', 'local_nucleushub', (object) [
                        'severity' => s($hint['suggested']),
                        'rationale' => s($hint['rationale'] ?? ''),
                    ]),
                    ['class' => 'nsb-panel-callout']
                );
            }
            $cards[] = $card3;
        }

        // Card 4 — downstream reach. Spoke count + clear "no
        // spokes" callout so a fresh hub knows nothing's listening.
        $spokecount = (int) $DB->count_records(
            'local_nucleushub_spokes',
            ['status' => 'active']
        );
        $card4 = html_writer::tag('h4',
            get_string('statusbar_hub_spokestitle', 'local_nucleushub'),
            ['class' => 'nsb-panel-title']);
        $card4 .= html_writer::tag(
            'div',
            $spokecount > 0
                ? get_string('statusbar_hub_spokesregistered', 'local_nucleushub', $spokecount)
                : get_string('statusbar_hub_nospokes', 'local_nucleushub'),
            ['class' => 'nsb-panel-sub']
        );
        $cards[] = $card4;
    } else {
        // Card 1 — status / hint.
        $card1 = html_writer::tag(
            'h4',
            get_string('statusbar_hub_notinfederation', 'local_nucleushub'),
            ['class' => 'nsb-panel-title']
        );
        $card1 .= html_writer::tag(
            'div',
            get_string('statusbar_hub_notinfederation_hint', 'local_nucleushub'),
            ['class' => 'nsb-panel-sub']
        );
        $cards[] = $card1;

        // Card 2 — what's about to get federated (course content
        // summary). Best-effort: modinfo can throw transiently.
        try {
            $modinfo = get_fast_modinfo($PAGE->course);
            $modulecount = count($modinfo->get_cms());
            $sectioncount = count($modinfo->get_section_info_all());
            $card2 = html_writer::tag('h4',
                get_string('statusbar_hub_coursetitle', 'local_nucleushub'),
                ['class' => 'nsb-panel-title']);
            $card2 .= html_writer::tag(
                'div',
                get_string('statusbar_hub_coursesummary', 'local_nucleushub', (object) [
                    'sections' => $sectioncount,
                    'modules' => $modulecount,
                ]),
                ['class' => 'nsb-panel-sub']
            );
            $card2 .= html_writer::tag(
                'div',
                format_string($PAGE->course->shortname),
                ['class' => 'nsb-panel-sub']
            );
            $cards[] = $card2;
        } catch (\Throwable $e) {
            // No card 2 — modinfo is best-effort context.
        }
    }

    // Two-step publishing: an unfamilied course gets "Add to
    // federation" → promote.php (creates family stub). A familied
    // course gets "Publish version" → publish.php (creates the
    // next version).
    if (!$family) {
        $action = [
            'label' => get_string('statusbar_hub_addtofederation', 'local_nucleushub'),
            'url' => $promoteurl,
            'icon' => 'fa-plus',
            'primary' => true,
        ];
    } else {
        $action = [
            'label' => get_string('publishversion', 'local_nucleushub'),
            'url' => $publishurl,
            'icon' => 'fa-upload',
            'primary' => true,
        ];
    }

    // Top-of-body banner: only emit when there's something
    // attention-worthy. Sits above the course content as a primary
    // call-to-action; the bottom status bar carries the persistent
    // readout regardless.
    $banner = null;
    if ($family) {
        $pending = (int) ($draft->pendingchangecount ?? 0);
        if ($pending > 0) {
            $body = $lastversion
                ? get_string('banner_dirty', 'local_nucleushub', (object) [
                    'count' => $pending,
                    'version' => s($lastversion->versionnumber),
                    'when' => format_time(time() - (int) $lastversion->timepublished),
                ])
                : get_string('banner_dirty_unpublished', 'local_nucleushub', $pending);
            $banner = [
                'tone' => 'warn',
                'icon' => 'fa-pen',
                'body' => $body,
                'action' => [
                    'label' => get_string('publishversion', 'local_nucleushub'),
                    'url' => $publishurl,
                    'icon' => 'fa-upload',
                ],
            ];
        }
    }

    return [
        'segments' => $segments,
        'panel' => $cards,
        'actions' => [$action],
        'banner' => $banner,
    ];
}

/**
 * Render a `local_nucleushub_changelog` eventkind tally as a
 * compact bullet-separated phrase, e.g. "3 module updates · 1
 * section added". Sorted by descending count for a meaningful
 * top-three when the list is long.
 *
 * @param array<string,int> $counts eventkind => count.
 * @return string Empty string when no counts.
 */
function local_nucleushub_format_change_counts(array $counts): string {
    if (empty($counts)) {
        return '';
    }
    // Eventkinds as logged by the change_tracker observer. Plural
    // form reads better when a count rolls past 1; we keep the
    // singular pretty-form too for the n == 1 case. Anything not
    // listed falls back to "1 module_updated"-style raw output.
    static $labels = [
        'module_added'    => ['module added', 'modules added'],
        'module_updated'  => ['module update', 'module updates'],
        'module_deleted'  => ['module deleted', 'modules deleted'],
        'section_added'   => ['section added', 'sections added'],
        'section_updated' => ['section update', 'section updates'],
        'section_deleted' => ['section deleted', 'sections deleted'],
        'file_replaced'   => ['file replaced', 'file replacements'],
        'course_updated'  => ['course settings update', 'course settings updates'],
    ];
    arsort($counts, SORT_NUMERIC);
    $counts = array_slice($counts, 0, 4, true);
    $parts = [];
    foreach ($counts as $kind => $n) {
        if (isset($labels[$kind])) {
            $label = $labels[$kind][$n === 1 ? 0 : 1];
        } else {
            $label = str_replace('_', ' ', $kind);
        }
        $parts[] = $n . ' ' . $label;
    }
    return implode(' · ', $parts);
}
