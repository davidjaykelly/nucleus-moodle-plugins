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
use local_nucleusspoke\local\version_actions;
use moodle_url;
use renderable;
use renderer_base;
use single_button;
use templatable;

/**
 * The Course versions page: updates waiting, snoozed updates, the
 * courses this spoke has pulled, and a short history.
 *
 * Every action is a button. Pull, pull as hidden, snooze, show again,
 * make visible and reopen post straight away; roll back, close to
 * enrolment and dismiss go to a confirmation page first.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class versions_page implements renderable, templatable {
    /** @var moodle_url The Course versions page. */
    private moodle_url $pageurl;

    /**
     * Constructor.
     *
     * @param moodle_url $pageurl
     */
    public function __construct(moodle_url $pageurl) {
        $this->pageurl = $pageurl;
    }

    /**
     * A button for an action on this page.
     *
     * @param renderer_base $output
     * @param string $action
     * @param int $id Notification or instance id.
     * @param string $label
     * @param bool $primary
     * @param bool $confirmfirst Go to the confirmation page (GET) instead of acting (POST).
     * @return string HTML
     */
    private function button(
        renderer_base $output,
        string $action,
        int $id,
        string $label,
        bool $primary = false,
        bool $confirmfirst = false
    ): string {
        $params = ['action' => $action, 'id' => $id];
        if (!$confirmfirst) {
            $params['confirm'] = 1;
        }
        $button = new single_button(
            new moodle_url($this->pageurl, $params),
            $label,
            $confirmfirst ? 'get' : 'post',
            $primary ? single_button::BUTTON_PRIMARY : single_button::BUTTON_SECONDARY
        );
        return $output->render($button);
    }

    /**
     * A date in the site's short format.
     *
     * @param int|null $time
     * @return string
     */
    private static function date(?int $time): string {
        return $time ? userdate($time, get_string('strftimedatetimeshort', 'langconfig')) : '-';
    }

    /**
     * Template context.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        global $DB;

        // Updates waiting.
        $pending = [];
        $rows = $DB->get_records_sql(
            "SELECT n.id, n.timereceived, f.slug, v.versionnumber, v.severity, v.releasenotes, v.lockedforspokeedit
               FROM {local_nucleusspoke_notification} n
               JOIN {local_nucleuscommon_family} f ON f.id = n.familyid
               JOIN {local_nucleuscommon_version} v ON v.id = n.versionid
              WHERE n.state = 'pending'
           ORDER BY n.timereceived ASC"
        );
        foreach ($rows as $row) {
            $pending[] = [
                'slug' => $row->slug,
                'version' => 'v' . $row->versionnumber,
                'severity' => labels::severity((string) $row->severity),
                'locked' => (int) $row->lockedforspokeedit === 1,
                'received' => self::date((int) $row->timereceived),
                'releasenotes' => (string) ($row->releasenotes ?? ''),
                'buttons' => implode(' ', [
                    $this->button(
                        $output,
                        'pull',
                        (int) $row->id,
                        get_string('pull_version', 'local_nucleusspoke', $row->versionnumber),
                        true
                    ),
                    $this->button($output, 'pullhidden', (int) $row->id, get_string('pull_staging', 'local_nucleusspoke')),
                    $this->button($output, 'snooze', (int) $row->id, get_string('snooze', 'local_nucleusspoke')),
                    $this->button($output, 'dismiss', (int) $row->id, get_string('dismiss', 'local_nucleusspoke'), false, true),
                ]),
            ];
        }

        // Snoozed.
        $snoozed = [];
        $rows = $DB->get_records_sql(
            "SELECT n.id, n.snoozeuntil, f.slug, v.versionnumber, v.severity
               FROM {local_nucleusspoke_notification} n
               JOIN {local_nucleuscommon_family} f ON f.id = n.familyid
               JOIN {local_nucleuscommon_version} v ON v.id = n.versionid
              WHERE n.state = 'snoozed'
           ORDER BY n.snoozeuntil ASC"
        );
        foreach ($rows as $row) {
            $snoozed[] = [
                'slug' => $row->slug,
                'version' => 'v' . $row->versionnumber,
                'severity' => labels::severity((string) $row->severity),
                'until' => self::date($row->snoozeuntil ? (int) $row->snoozeuntil : null),
                'buttons' => implode(' ', [
                    $this->button($output, 'reactivate', (int) $row->id, get_string('reactivate', 'local_nucleusspoke')),
                    $this->button($output, 'dismiss', (int) $row->id, get_string('dismiss', 'local_nucleusspoke'), false, true),
                ]),
            ];
        }

        // Pulled courses, newest first within each course family.
        $instances = [];
        $rows = $DB->get_records_sql(
            "SELECT i.id, i.state, i.timepulled, i.localcourseid, c.fullname,
                    f.id AS familyid, f.slug,
                    v.versionnumber, v.severity, v.deprecated, v.deprecatedreason, v.timepublished
               FROM {local_nucleusspoke_instance} i
               JOIN {course} c ON c.id = i.localcourseid
               JOIN {local_nucleuscommon_family} f ON f.id = i.familyid
               JOIN {local_nucleuscommon_version} v ON v.id = i.versionid
           ORDER BY f.slug ASC, i.timepulled DESC"
        );
        foreach ($rows as $row) {
            $deprecated = (int) $row->deprecated === 1;
            $buttons = [];
            if ($deprecated) {
                $target = version_actions::rollback_target((int) $row->familyid, (int) $row->timepublished);
                if ($target) {
                    $buttons[] = $this->button(
                        $output,
                        'rollback',
                        (int) $row->id,
                        get_string('rollback_to', 'local_nucleusspoke', $target->versionnumber),
                        false,
                        true
                    );
                }
            }
            if ($row->state === 'staging') {
                $buttons[] = $this->button(
                    $output,
                    'makevisible',
                    (int) $row->id,
                    get_string('promote', 'local_nucleusspoke'),
                    true
                );
            } else if ($row->state === 'active') {
                $buttons[] = $this->button(
                    $output,
                    'close',
                    (int) $row->id,
                    get_string('close_to_enrolment', 'local_nucleusspoke'),
                    false,
                    true
                );
            } else if ($row->state === 'closed-to-enrolment') {
                $buttons[] = $this->button($output, 'reopen', (int) $row->id, get_string('reopen', 'local_nucleusspoke'));
            }
            $instances[] = [
                'coursename' => format_string($row->fullname),
                'courseurl' => (new moodle_url('/course/view.php', ['id' => $row->localcourseid]))->out(false),
                'slug' => $row->slug,
                'version' => 'v' . $row->versionnumber,
                'severity' => labels::severity((string) $row->severity),
                'state' => labels::instance_state((string) $row->state),
                'live' => $row->state === 'active',
                'deprecated' => $deprecated,
                'deprecatedreason' => $deprecated ? trim((string) ($row->deprecatedreason ?? '')) : '',
                'pulled' => self::date((int) $row->timepulled),
                'buttons' => implode(' ', $buttons),
            ];
        }

        // History: the latest 20 updates that are no longer waiting.
        $history = [];
        $rows = $DB->get_records_sql(
            "SELECT n.id, n.state, n.timereceived, n.timeresolved, f.slug, v.versionnumber
               FROM {local_nucleusspoke_notification} n
               JOIN {local_nucleuscommon_family} f ON f.id = n.familyid
               JOIN {local_nucleuscommon_version} v ON v.id = n.versionid
              WHERE n.state <> 'pending'
           ORDER BY n.timereceived DESC",
            null,
            0,
            20
        );
        foreach ($rows as $row) {
            $history[] = [
                'slug' => $row->slug,
                'version' => 'v' . $row->versionnumber,
                'received' => self::date((int) $row->timereceived),
                'resolved' => self::date($row->timeresolved ? (int) $row->timeresolved : null),
                'state' => labels::notification_state((string) $row->state),
            ];
        }

        return [
            'catalogueurl' => (new moodle_url('/local/nucleusspoke/catalog.php'))->out(false),
            'haspending' => !empty($pending),
            'pending' => $pending,
            'hassnoozed' => !empty($snoozed),
            'snoozed' => $snoozed,
            'hasinstances' => !empty($instances),
            'instances' => $instances,
            'hashistory' => !empty($history),
            'history' => $history,
        ];
    }
}
