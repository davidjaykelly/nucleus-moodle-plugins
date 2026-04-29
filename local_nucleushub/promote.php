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
 * Promote a Moodle course into the federation as a new family.
 *
 * Phase 1 of the two-step publishing flow: create the family stub
 * (slug + guid) without publishing a version. The Publish action
 * (publish.php) then takes over for v1.0.0 onwards.
 *
 * Reached from the Nucleus status bar's "+ Add to federation"
 * action when a hub course has no family row yet.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/formslib.php');

use local_nucleushub\version\publisher;

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/nucleushub:publish', context_system::instance());

$pageurl = new moodle_url('/local/nucleushub/promote.php', ['courseid' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_title(get_string('promote_title', 'local_nucleushub', format_string($course->fullname)));
$PAGE->set_heading(get_string('promote_heading', 'local_nucleushub'));
$PAGE->set_pagelayout('incourse');
$PAGE->add_body_class('limitedwidth');

$federationid = (string) get_config('local_nucleuscommon', 'federationid');
if ($federationid === '') {
    throw new \moodle_exception('federationidunset', 'local_nucleushub');
}

// If a family already exists for this course, bounce straight to publish.
$existingdraft = $DB->get_record('local_nucleushub_draft', ['hubcourseid' => $courseid]);
if ($existingdraft) {
    redirect(
        new moodle_url('/local/nucleushub/publish.php', ['id' => $courseid]),
        get_string('promote_already', 'local_nucleushub'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

class local_nucleushub_promote_form extends moodleform {

    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('text', 'slug',
            get_string('promote_slug', 'local_nucleushub'),
            ['size' => 50, 'maxlength' => 120, 'class' => 'nfp-slug-input']);
        $mform->setType('slug', PARAM_RAW_TRIMMED);
        $mform->setDefault('slug', $this->_customdata['suggestedslug']);
        $mform->addRule('slug', null, 'required', null, 'client');
        $mform->addHelpButton('slug', 'promote_slug', 'local_nucleushub');

        // Live preview slot — JS updates the inner text as the user
        // types in the slug field.
        $mform->addElement('html',
            '<div class="nfp-slug-preview" id="nfp-slug-preview">'
            . '<span class="nfp-slug-preview-label">'
                . s(get_string('promote_slug_preview_label', 'local_nucleushub'))
            . '</span>'
            . '<code class="nfp-slug-preview-value">'
                . s($this->_customdata['suggestedslug'])
            . '</code>'
            . '</div>'
        );

        $mform->addElement('hidden', 'courseid', $this->_customdata['courseid']);
        $mform->setType('courseid', PARAM_INT);

        $this->add_action_buttons(true, get_string('promote_submit', 'local_nucleushub'));
    }

    public function validation($data, $files): array {
        global $DB;
        $errors = parent::validation($data, $files);
        $slug = publisher::slugify((string) ($data['slug'] ?? ''));
        if ($slug === '' || $slug === 'course') {
            $errors['slug'] = get_string('promote_slug_invalid', 'local_nucleushub');
        } else {
            $clash = $DB->record_exists('local_nucleuscommon_family', [
                'hubfederationid' => $this->_customdata['federationid'],
                'slug' => $slug,
            ]);
            if ($clash) {
                $errors['slug'] = get_string('promote_slug_clash', 'local_nucleushub', $slug);
            }
        }
        return $errors;
    }
}

$suggested = publisher::slugify($course->shortname);
$form = new local_nucleushub_promote_form($pageurl->out(false), [
    'courseid' => $courseid,
    'coursefullname' => $course->fullname,
    'suggestedslug' => $suggested,
    'federationid' => $federationid,
]);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
}

if ($data = $form->get_data()) {
    $slug = publisher::slugify((string) $data->slug);
    $now = time();

    $transaction = $DB->start_delegated_transaction();
    try {
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
    } catch (\Throwable $e) {
        $transaction->rollback($e);
    }

    redirect(
        new moodle_url('/course/view.php', ['id' => $courseid]),
        get_string('promote_success', 'local_nucleushub', $slug),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Course content summary — best-effort, fall through if modinfo errs.
$sectioncount = 0;
$modulecount = 0;
try {
    $modinfo = get_fast_modinfo($course);
    $modulecount = count($modinfo->get_cms());
    $sectioncount = count($modinfo->get_section_info_all());
} catch (\Throwable $e) {
    // Fall through silently.
}

echo $OUTPUT->header();
?>
<style>
.nfp-card {
  border: 1px solid #e1e4e8;
  border-radius: 5px;
  padding: 12px 14px;
  background: #fff;
  color: #1d2326;
  font-size: 13px;
  margin-bottom: 18px;
}
.nfp-card-line {
  display: flex;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
  font-size: 12.5px;
}
.nfp-card-title {
  margin: 0;
  font-size: 14px;
  font-weight: 600;
  color: #d97706;
}
.nfp-card-guid { color: #6a6e72; font-weight: 400; font-size: 11px; }
.nfp-card-pill {
  background: #f8f9fa;
  border: 1px solid #e1e4e8;
  border-radius: 10px;
  padding: 2px 9px;
  font-size: 11px;
  color: #4a5258;
}
.nfp-intro {
  font-size: 13px;
  color: #4a5258;
  line-height: 1.55;
  margin-bottom: 14px;
}
.nfp-slug-input {
  font-family: ui-monospace, "SF Mono", Menlo, monospace !important;
  font-size: 14px !important;
}
.nfp-slug-preview {
  margin: 4px 0 14px 0;
  padding: 8px 12px;
  background: #f8f9fa;
  border: 1px dashed #d0d4d8;
  border-radius: 4px;
  font-size: 12.5px;
}
.nfp-slug-preview-label { color: #6a6e72; margin-right: 8px; }
.nfp-slug-preview-value {
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
  color: #d97706;
  font-weight: 600;
  background: transparent;
  padding: 0;
  font-size: 13px;
}

/* See publish.php for rationale — stack form rows full-width. */
.nfp-form .col-md-3,
.nfp-form .col-md-9 {
  flex: 0 0 100%;
  max-width: 100%;
}
.nfp-form .col-md-3 {
  text-align: left;
  padding: 0 0 4px 0;
}
.nfp-form fieldset[id^="id_buttonar"] .col-md-3,
.nfp-form #fgroup_id_buttonar .col-md-3 { display: none; }
.nfp-form fieldset[id^="id_buttonar"] .col-md-9,
.nfp-form #fgroup_id_buttonar .col-md-9 { margin-left: 0; }
</style>
<?php

echo html_writer::tag('p',
    s(get_string('promote_intro', 'local_nucleushub')),
    ['class' => 'nfp-intro']);

// Course summary card — single compact line; identity + content stats.
echo '<div class="nfp-card">';
echo '<div class="nfp-card-line">';
echo '<h3 class="nfp-card-title">' . s($course->fullname) . '</h3>';
echo '<span class="nfp-card-guid">' . s($course->shortname) . '</span>';
echo '<span class="nfp-card-pill">'
    . get_string('statusbar_hub_coursesummary', 'local_nucleushub', (object) [
        'sections' => $sectioncount, 'modules' => $modulecount,
    ])
    . '</span>';
echo '</div>';
echo '</div>';

echo '<div class="nfp-form">';
$form->display();
echo '</div>';
?>
<script>
(function(){
    var input = document.querySelector('input[name="slug"]');
    var preview = document.querySelector('.nfp-slug-preview-value');
    if (!input || !preview) return;
    var slugify = function(s){
        return (s || '')
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9]+/gi, '-')
            .replace(/^-+|-+$/g, '')
            .substring(0, 120) || 'course';
    };
    input.addEventListener('input', function(){
        preview.textContent = slugify(input.value);
    });
})();
</script>
<?php

echo $OUTPUT->footer();
