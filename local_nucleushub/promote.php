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
 * Add a hub course to the federation: give it an identifier that stays
 * the same across every version and every spoke. Publishing comes next
 * (publish.php).
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_nucleuscommon\local\site;
use local_nucleushub\form\promote_form;
use local_nucleushub\output\summary;
use local_nucleushub\version\publisher;

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);

require_login($course);
require_capability('local/nucleushub:publish', context_system::instance());

$pageurl = new moodle_url('/local/nucleushub/promote.php', ['courseid' => $courseid]);
$courseurl = new moodle_url('/course/view.php', ['id' => $courseid]);
$coursename = format_string($course->fullname, true, ['context' => context_course::instance($courseid)]);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('promote_title', 'local_nucleushub', $coursename));
$PAGE->set_heading($coursename);
$PAGE->add_body_class('limitedwidth');
$PAGE->navbar->add(get_string('promote_heading', 'local_nucleushub'));

if (!site::is_hub()) {
    throw new moodle_exception('notahub', 'local_nucleushub', $courseurl);
}

// A course that's already in the federation goes straight to publishing.
if ($DB->record_exists('local_nucleushub_draft', ['hubcourseid' => $courseid])) {
    redirect(
        new moodle_url('/local/nucleushub/publish.php', ['id' => $courseid]),
        get_string('promote_already', 'local_nucleushub'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

$federationid = (string) get_config('local_nucleuscommon', 'federationid');
if ($federationid === '') {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('promote_heading', 'local_nucleushub'));
    echo $OUTPUT->notification(
        s(trim(get_string('federationidunset', 'local_nucleushub') . ' ' . site::support_line())),
        \core\output\notification::NOTIFY_ERROR,
        false
    );
    echo $OUTPUT->continue_button($courseurl);
    echo $OUTPUT->footer();
    exit;
}

$form = new promote_form($pageurl, [
    'courseid' => $courseid,
    'suggestedslug' => publisher::slugify($course->shortname),
    'federationid' => $federationid,
]);

if ($form->is_cancelled()) {
    redirect($courseurl);
}

if ($data = $form->get_data()) {
    $slug = publisher::slugify((string) $data->slug);
    $now = time();
    $transaction = $DB->start_delegated_transaction();
    $familyid = $DB->insert_record('local_nucleuscommon_family', (object) [
        'guid' => publisher::uuid_v4(),
        'slug' => $slug,
        'hubfederationid' => $federationid,
        'catalogvisible' => 1,
        'createdbyid' => (int) $USER->id,
        'timecreated' => $now,
    ]);
    $DB->insert_record('local_nucleushub_draft', (object) [
        'familyid' => $familyid,
        'hubcourseid' => $courseid,
        'lastpublishversionid' => null,
        'pendingchangecount' => 0,
        'timelastedit' => null,
        'timecreated' => $now,
    ]);
    $transaction->allow_commit();

    redirect(
        $courseurl,
        get_string('promote_success', 'local_nucleushub', $slug),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$modinfo = get_fast_modinfo($course);
$summary = (new summary())
    ->add(get_string('course'), format_string($course->fullname, true, ['escape' => false]))
    ->add(get_string('shortnamecourse'), format_string($course->shortname, true, ['escape' => false]))
    ->add(get_string('promote_content', 'local_nucleushub'), get_string(
        'statusbar_hub_coursesummary',
        'local_nucleushub',
        (object) ['sections' => count($modinfo->get_section_info_all()), 'modules' => count($modinfo->get_cms())]
    ));

$PAGE->requires->js_call_amd(
    'local_nucleushub/slug_preview',
    'init',
    ['#id_slug', '#' . promote_form::PREVIEW_ID . ' [data-region="slug"]']
);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('promote_heading', 'local_nucleushub'));
echo html_writer::tag('p', s(get_string('promote_intro', 'local_nucleushub')));
echo $PAGE->get_renderer('local_nucleushub')->render($summary);
$form->display();
echo $OUTPUT->footer();
