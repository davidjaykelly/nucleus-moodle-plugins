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

/**
 * Course versions: updates waiting, snoozed updates, the courses this
 * spoke has pulled, and a short history.
 *
 * Actions come in as ?action=<name>&id=<id>. Nothing changes on a GET:
 * pull, pull as hidden, roll back, close to enrolment and dismiss show
 * a confirmation first (the Nucleus bar and course notices link here),
 * and every change is a POST with the session key.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_nucleuscommon\local\site;
use local_nucleusspoke\local\version_actions;
use local_nucleusspoke\output\versions_page;
use local_nucleusspoke\version\lifecycle;
use local_nucleusspoke\version\notifications;
use local_nucleusspoke\version\promoter;

admin_externalpage_setup('local_nucleusspoke_versions');
$pageurl = new moodle_url('/local/nucleusspoke/versions.php');

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$confirmed = optional_param('confirm', 0, PARAM_BOOL);

$isnotification = in_array($action, version_actions::NOTIFICATION_ACTIONS, true);
$isinstance = in_array($action, version_actions::INSTANCE_ACTIONS, true);

if (($isnotification || $isinstance) && $id > 0) {
    // Load what the action is about.
    if ($isnotification) {
        $notif = $DB->get_record('local_nucleusspoke_notification', ['id' => $id], '*', MUST_EXIST);
        $family = $DB->get_record('local_nucleuscommon_family', ['id' => $notif->familyid], '*', MUST_EXIST);
        $version = $DB->get_record('local_nucleuscommon_version', ['id' => $notif->versionid], '*', MUST_EXIST);
    } else {
        $instance = $DB->get_record('local_nucleusspoke_instance', ['id' => $id], '*', MUST_EXIST);
        $family = $DB->get_record('local_nucleuscommon_family', ['id' => $instance->familyid], '*', MUST_EXIST);
        $version = $DB->get_record('local_nucleuscommon_version', ['id' => $instance->versionid], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $instance->localcourseid], '*', MUST_EXIST);
    }

    $posted = $confirmed && data_submitted() && confirm_sesskey();

    // Ask first.
    if (!$posted && in_array($action, version_actions::CONFIRM_ACTIONS, true)) {
        $a = (object) ['slug' => $family->slug, 'version' => $version->versionnumber];
        $danger = false;
        switch ($action) {
            case 'pull':
                $message = get_string('confirm_pull', 'local_nucleusspoke', $a);
                $continue = get_string('pull_version', 'local_nucleusspoke', $version->versionnumber);
                break;
            case 'pullhidden':
                $message = get_string('confirm_pullhidden', 'local_nucleusspoke', $a);
                $continue = get_string('pull_staging', 'local_nucleusspoke');
                break;
            case 'dismiss':
                $message = get_string('confirm_dismiss', 'local_nucleusspoke', $a);
                $continue = get_string('dismiss', 'local_nucleusspoke');
                $danger = true;
                break;
            case 'close':
                $message = get_string(
                    'confirm_close',
                    'local_nucleusspoke',
                    format_string($course->fullname, true, ['escape' => false])
                );
                $continue = get_string('close_to_enrolment', 'local_nucleusspoke');
                $danger = true;
                break;
            case 'rollback':
                $target = version_actions::rollback_target((int) $instance->familyid, (int) $version->timepublished);
                if (!$target) {
                    redirect(
                        $pageurl,
                        get_string('rollback_notarget', 'local_nucleusspoke'),
                        null,
                        \core\output\notification::NOTIFY_ERROR
                    );
                }
                $message = get_string('confirm_rollback', 'local_nucleusspoke', (object) [
                    'slug' => $family->slug,
                    'from' => $version->versionnumber,
                    'to' => $target->versionnumber,
                ]);
                $continue = get_string('rollback', 'local_nucleusspoke');
                break;
        }
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('versions_title', 'local_nucleusspoke'));
        echo $OUTPUT->confirm(
            s($message),
            new moodle_url($pageurl, ['action' => $action, 'id' => $id, 'confirm' => 1]),
            $pageurl,
            [
                'continuestr' => $continue,
                'type' => $danger ? single_button::BUTTON_DANGER : single_button::BUTTON_PRIMARY,
            ]
        );
        echo $OUTPUT->footer();
        exit;
    }

    if (!$posted) {
        redirect($pageurl);
    }

    // Do it.
    try {
        switch ($action) {
            case 'pull':
            case 'pullhidden':
                $hidden = $action === 'pullhidden';
                $result = version_actions::pull($family, $version, $hidden, (int) $USER->id);
                $message = s(get_string(
                    $hidden ? 'pullstagingsuccess' : 'pullsuccess',
                    'local_nucleusspoke',
                    (object) ['slug' => $family->slug, 'version' => $version->versionnumber]
                ))
                    . ' ' . html_writer::link(
                        new moodle_url('/course/view.php', ['id' => $result['localcourseid']]),
                        get_string('opencourse', 'local_nucleusspoke')
                    );
                break;
            case 'snooze':
            case 'dismiss':
            case 'reactivate':
                notifications::apply($id, $action, null, (int) $USER->id);
                $message = s(get_string('notification_' . $action . '_success', 'local_nucleusspoke'));
                break;
            case 'makevisible':
                promoter::promote_instance($id, (int) $USER->id);
                $message = s(get_string('promotesuccess', 'local_nucleusspoke'));
                break;
            case 'close':
                lifecycle::close_to_enrolment($id);
                $message = s(get_string('closesuccess', 'local_nucleusspoke'));
                break;
            case 'reopen':
                lifecycle::reopen($id);
                $message = s(get_string('reopensuccess', 'local_nucleusspoke'));
                break;
            case 'rollback':
                $target = version_actions::rollback_target((int) $instance->familyid, (int) $version->timepublished);
                if (!$target) {
                    throw new moodle_exception('rollback_notarget', 'local_nucleusspoke');
                }
                $result = version_actions::pull($family, $target, false, (int) $USER->id);
                $message = s(get_string('rollback_success', 'local_nucleusspoke', (object) [
                    'slug' => $family->slug,
                    'from' => $version->versionnumber,
                    'to' => $target->versionnumber,
                ])) . ' ' . html_writer::link(
                    new moodle_url('/course/view.php', ['id' => $result['localcourseid']]),
                    get_string('opencourse', 'local_nucleusspoke')
                );
                break;
        }
        redirect($pageurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (\moodle_exception $e) {
        debugging('Nucleus ' . $action . ' failed: ' . $e->getMessage() . ' ' . ($e->debuginfo ?? ''), DEBUG_DEVELOPER);
        $key = in_array($action, ['pull', 'pullhidden'], true) ? 'pullfailure'
            : ($action === 'rollback' ? 'rollback_failure' : 'actionfailure');
        redirect(
            $pageurl,
            s(trim(get_string($key, 'local_nucleusspoke', $e->getMessage()) . ' ' . site::support_line())),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('versions_title', 'local_nucleusspoke'));
echo $PAGE->get_renderer('local_nucleusspoke')->render(new versions_page($pageurl));
echo $OUTPUT->footer();
