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
 * Nucleus federation spoke admin page.
 *
 * Phase 0 UI: one page that shows the hub connection health, lists the
 * courses the hub is offering, and — depending on the current federation
 * mode — lets the admin either pull a local copy (Mode A) or enable
 * federation for a course (Mode B).
 *
 * Deliberately minimal per the Phase 0 plan's anti-goals ("admin pages
 * can look ugly"). Proper UX is a Phase 3 concern.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$action = optional_param('action', '', PARAM_ALPHA);
$hubcourseid = optional_param('hubcourseid', 0, PARAM_INT);

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/nucleusspoke/admin.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('pluginname', 'local_nucleusspoke'));
$PAGE->set_heading(get_string('pluginname', 'local_nucleusspoke'));

$notice = null;
$noticetype = 'info';

// Action handling. POST only, sesskey-guarded.
if ($action !== '' && $hubcourseid > 0 && confirm_sesskey()) {
    try {
        if ($action === 'copy') {
            $result = \local_nucleusspoke\action\copy_locally::run($hubcourseid);
            $notice = $result['message'];
            $noticetype = 'success';
        } else if ($action === 'enable') {
            $result = \local_nucleusspoke\action\enable_federation::run($hubcourseid);
            $notice = $result['message'];
            $noticetype = 'success';
        }
    } catch (\Throwable $e) {
        $notice = 'Action failed: ' . $e->getMessage();
        $noticetype = 'error';
    }
    // PRG redirect so refreshes don't reapply.
    redirect(new moodle_url('/local/nucleusspoke/admin.php'),
        $notice, null, $noticetype === 'error' ? \core\output\notification::NOTIFY_ERROR
                                                : \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();

// --- Health panel ---------------------------------------------------------
try {
    $client = \local_nucleusspoke\client\hub_client::default();
    $health = $client->health_check();
} catch (\moodle_exception $e) {
    $health = ['ok' => false, 'detail' => $e->getMessage(), 'courses_available' => null];
}

$mode = \local_nucleuscommon\mode\dispatcher::current_mode();

echo html_writer::start_tag('div', ['class' => 'box py-3']);
echo html_writer::tag('h4', 'Federation status');
echo html_writer::tag('p', 'Mode: ' . html_writer::tag('strong', ucfirst($mode)));
echo html_writer::tag('p', 'Hub connection: ' . html_writer::tag('strong',
    $health['ok'] ? 'OK' : 'FAILED',
    ['style' => 'color:' . ($health['ok'] ? 'green' : 'darkred')]));
if (!$health['ok']) {
    echo html_writer::tag('pre', s($health['detail']));
}
echo html_writer::end_tag('div');

if (!$health['ok']) {
    echo $OUTPUT->footer();
    exit;
}

// --- Courses table --------------------------------------------------------
$courses = $client->list_courses();

$ispokecourses = $DB->get_records_menu('local_nucleusspoke_courses',
    null, '', 'hubcourseid, localcourseid');

echo html_writer::tag('h4', 'Hub-offered courses (' . count($courses) . ')');

$table = new html_table();
$table->head = ['Hub id', 'Shortname', 'Full name', 'Status', 'Action'];
$table->align = ['right', 'left', 'left', 'left', 'left'];

// 'both' surfaces both buttons via the dual rendering below; the
// single-button path covers content-only and identity-only.
$showidentity = in_array($mode, ['identity', 'both'], true);
$showcontent  = in_array($mode, ['content', 'both'], true);

$buildbutton = function (int $hubcourseid, string $action, string $label): string {
    $url = new moodle_url('/local/nucleusspoke/admin.php', [
        'action'      => $action,
        'hubcourseid' => $hubcourseid,
        'sesskey'     => sesskey(),
    ]);
    return html_writer::link($url, $label, ['class' => 'btn btn-primary btn-sm']);
};

foreach ($courses as $course) {
    $localid = $ispokecourses[$course['id']] ?? null;
    if ($localid) {
        $status = 'Local course id ' . (int)$localid . ' ('. ucfirst($mode) . ')';
        $actioncell = html_writer::tag('em', 'already federated');
    } else {
        $status = 'Not yet federated';
        $buttons = [];
        if ($showidentity) {
            $buttons[] = $buildbutton((int)$course['id'], 'enable', 'Enable federation');
        }
        if ($showcontent) {
            $buttons[] = $buildbutton((int)$course['id'], 'copy', 'Copy locally');
        }
        $actioncell = implode(' ', $buttons) ?: html_writer::tag('em', 'no actions for current mode');
    }
    $table->data[] = [
        (int)$course['id'],
        s($course['shortname']),
        s($course['fullname']),
        $status,
        $actioncell,
    ];
}

echo html_writer::table($table);

echo $OUTPUT->footer();
