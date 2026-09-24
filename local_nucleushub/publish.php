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
 * Publish a new version of a hub course for spokes to pull.
 *
 * Reached from the course's More menu, the Nucleus bar, the notice on
 * the course page and Course families. A course that isn't in the
 * federation yet is sent to promote.php first.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_nucleuscommon\local\site;
use local_nucleushub\form\publish_form;
use local_nucleushub\local\statusbar;
use local_nucleushub\output\summary;
use local_nucleushub\version\publisher;
use local_nucleushub\version\severity_hint;

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);

require_login($course);
require_capability('local/nucleushub:publish', context_system::instance());

$pageurl = new moodle_url('/local/nucleushub/publish.php', ['id' => $courseid]);
$courseurl = new moodle_url('/course/view.php', ['id' => $courseid]);
$coursename = format_string($course->fullname, true, ['context' => context_course::instance($courseid)]);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('publishversion_title', 'local_nucleushub', $coursename));
$PAGE->set_heading($coursename);
$PAGE->add_body_class('limitedwidth');
$PAGE->navbar->add(get_string('publishversion', 'local_nucleushub'));

if (!site::is_hub()) {
    throw new moodle_exception('notahub', 'local_nucleushub', $courseurl);
}

$draft = $DB->get_record('local_nucleushub_draft', ['hubcourseid' => $courseid]);
if (!$draft) {
    // Not in the federation yet: choose an identifier first.
    redirect(new moodle_url('/local/nucleushub/promote.php', ['courseid' => $courseid]));
}
$family = $DB->get_record('local_nucleuscommon_family', ['id' => $draft->familyid], '*', MUST_EXIST);
$lastversion = $draft->lastpublishversionid
    ? $DB->get_record('local_nucleuscommon_version', ['id' => $draft->lastpublishversionid])
    : null;
$hint = severity_hint::for_family((int) $family->id);
$lastnumber = $lastversion ? $lastversion->versionnumber : null;

$form = new publish_form($pageurl, [
    'id' => $courseid,
    'suggested' => $hint['suggested'],
    'preview' => [
        'patch' => publisher::next_version($lastnumber, 'patch'),
        'minor' => publisher::next_version($lastnumber, 'minor'),
        'major' => publisher::next_version($lastnumber, 'major'),
    ],
]);

if ($form->is_cancelled()) {
    redirect($courseurl);
}

$error = null;
if ($data = $form->get_data()) {
    try {
        $notes = trim((string) $data->notes);
        $result = publisher::publish(
            $courseid,
            (string) $data->severity,
            $notes !== '' ? $notes : null,
            (string) $family->guid,
            (int) $USER->id,
            (int) ($data->lockedforspokeedit ?? 0) === 1
        );
        redirect($courseurl, get_string('publishsuccess', 'local_nucleushub', (object) [
            'version' => $result['versionnumber'],
            'size' => display_size($result['size']),
        ]), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (\moodle_exception $e) {
        debugging('Nucleus publish failed: ' . $e->getMessage() . ' ' . ($e->debuginfo ?? ''), DEBUG_DEVELOPER);
        $error = $e->getMessage();
    }
}

// What's being published, above the form.
$summary = (new summary())->add(get_string('promote_slug', 'local_nucleushub'), $family->slug);
if ($lastversion) {
    $summary->add(
        get_string('statusbar_hub_lastpub_title', 'local_nucleushub'),
        get_string('statusbar_hub_lastpub', 'local_nucleushub', (object) [
            'version' => $lastversion->versionnumber,
            'severity' => statusbar::severity_label((string) $lastversion->severity),
            'when' => format_time(time() - (int) $lastversion->timepublished),
        ])
    );
} else {
    $summary->add(
        get_string('statusbar_hub_lastpub_title', 'local_nucleushub'),
        get_string('statusbar_hub_nopublishes', 'local_nucleushub')
    );
}
$pending = (int) $draft->pendingchangecount;
$summary->add(
    get_string('publish_changes', 'local_nucleushub'),
    (string) $pending,
    statusbar::change_lines($hint['counts'] ?? [])
);
if ($pending > 0 && !empty($hint['suggested'])) {
    $summary->set_note(get_string('severity_suggested', 'local_nucleushub', (object) [
        'severity' => statusbar::severity_label((string) $hint['suggested']),
        'rationale' => (string) ($hint['rationale'] ?? ''),
    ]));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('publishversion_heading', 'local_nucleushub'));
echo $PAGE->get_renderer('local_nucleushub')->render($summary);
if ($error !== null) {
    echo $OUTPUT->notification(
        s(trim(get_string('publishfailed', 'local_nucleushub', $error) . ' ' . site::support_line())),
        \core\output\notification::NOTIFY_ERROR,
        false
    );
}
$form->display();
echo $OUTPUT->footer();
