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
 * Publish a new version of a course family (ADR-014 Phase 1).
 *
 * Severity-as-cards picker + release notes; runs the publisher
 * synchronously and redirects with a success banner. Reachable from
 * the Nucleus status bar's "Publish version" action and from the
 * course admin menu.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/formslib.php');

use local_nucleushub\version\publisher;

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/nucleushub:publish', context_system::instance());

$pageurl = new moodle_url('/local/nucleushub/publish.php', ['id' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_title(get_string('publishversion_title', 'local_nucleushub', format_string($course->fullname)));
$PAGE->set_heading(get_string('publishversion', 'local_nucleushub'));
$PAGE->set_pagelayout('incourse');
// Boost honours `limitedwidth` to constrain the content column to
// a comfortable reading width — same behaviour as Moodle's own
// settings/admin forms.
$PAGE->add_body_class('limitedwidth');

// Draft / family / last-published state. Used both to pre-compute
// version previews for the picker cards and to render the info
// card above the form.
$draft = $DB->get_record('local_nucleushub_draft', ['hubcourseid' => $courseid]);
$family = $draft
    ? $DB->get_record('local_nucleuscommon_family', ['id' => $draft->familyid])
    : null;
$lastversion = $draft && $draft->lastpublishversionid
    ? $DB->get_record('local_nucleuscommon_version', ['id' => $draft->lastpublishversionid])
    : null;

$hint = $draft
    ? \local_nucleushub\version\severity_hint::for_family((int) $draft->familyid)
    : ['suggested' => null, 'counts' => [], 'rationale' => null];

// Pre-compute next-version preview per severity so the picker cards
// can show "v1.0.2 → v1.0.3" instead of just "Patch".
$lastnumber = $lastversion ? $lastversion->versionnumber : null;
$preview = [
    'patch' => publisher::next_version($lastnumber, 'patch'),
    'minor' => publisher::next_version($lastnumber, 'minor'),
    'major' => publisher::next_version($lastnumber, 'major'),
];

/**
 * Form: hidden severity (driven by JS card clicks) + release notes.
 * The visible severity picker is rendered as raw HTML before the
 * form fields by way of an `'html'` element — keeps the moodleform
 * submit/validation pipeline intact while letting us style the
 * picker freely.
 */
class local_nucleushub_publish_form extends moodleform {

    protected function definition(): void {
        $mform = $this->_form;
        $cd = $this->_customdata;

        // Picker cards. Each is a button with a data-severity attr;
        // JS swaps the hidden input value + the .selected class.
        $picker = $cd['picker_html'];
        $mform->addElement('html', $picker);

        // Hidden field carries the severity value. Default = the
        // hint-suggested severity (or 'patch' if no hint).
        $mform->addElement('hidden', 'severity', $cd['suggested'] ?: 'patch');
        $mform->setType('severity', PARAM_ALPHA);

        $mform->addElement('textarea', 'notes',
            get_string('releasenotes', 'local_nucleushub'),
            ['rows' => 5, 'wrap' => 'soft', 'class' => 'nfp-notes-input', 'style' => 'width:100%']);
        $mform->setType('notes', PARAM_RAW);
        $mform->addHelpButton('notes', 'releasenotes', 'local_nucleushub');

        $mform->addElement('hidden', 'id', $cd['id']);
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons(true, get_string('publishversion', 'local_nucleushub'));
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (!in_array($data['severity'], ['patch', 'minor', 'major'], true)) {
            $errors['severity'] = get_string('invalidseverity', 'local_nucleushub', $data['severity']);
        }
        if (in_array($data['severity'], ['minor', 'major'], true)
            && trim((string) ($data['notes'] ?? '')) === ''
        ) {
            $errors['notes'] = get_string('releasenotesrequired', 'local_nucleushub', $data['severity']);
        }
        return $errors;
    }
}

// Build the picker HTML — three cards. The "suggested" card gets a
// star badge; all are clickable, JS-driven.
$severities = [
    'patch' => [
        'label'   => get_string('severity_patch', 'local_nucleushub'),
        'caption' => get_string('severity_patch_caption', 'local_nucleushub'),
    ],
    'minor' => [
        'label'   => get_string('severity_minor', 'local_nucleushub'),
        'caption' => get_string('severity_minor_caption', 'local_nucleushub'),
    ],
    'major' => [
        'label'   => get_string('severity_major', 'local_nucleushub'),
        'caption' => get_string('severity_major_caption', 'local_nucleushub'),
    ],
];
$initial = $hint['suggested'] ?: 'patch';
$pickerhtml = '<div class="nfp-severity-picker" role="radiogroup" aria-label="'
    . s(get_string('severity', 'local_nucleushub')) . '">';
foreach ($severities as $key => $meta) {
    $isselected = $key === $initial ? ' nfp-sev-selected' : '';
    $issuggested = $hint['suggested'] === $key ? ' nfp-sev-suggested' : '';
    $badge = $hint['suggested'] === $key
        ? '<span class="nfp-sev-badge"><i class="fa fa-star" aria-hidden="true"></i> '
            . s(get_string('severity_suggested_badge', 'local_nucleushub')) . '</span>'
        : '';
    $pickerhtml .= '<button type="button" class="nfp-sev-card' . $isselected . $issuggested . '"'
        . ' data-severity="' . s($key) . '"'
        . ' role="radio" aria-checked="' . ($key === $initial ? 'true' : 'false') . '">'
        . '<div class="nfp-sev-head">'
        . '<span class="nfp-sev-name">' . s($meta['label']) . '</span>'
        . $badge
        . '</div>'
        . '<div class="nfp-sev-version">'
        . ($lastnumber ? 'v' . s($lastnumber) . ' → ' : '')
        . '<strong>v' . s($preview[$key]) . '</strong>'
        . '</div>'
        . '<div class="nfp-sev-caption">' . s($meta['caption']) . '</div>'
        . '</button>';
}
$pickerhtml .= '</div>';

$form = new local_nucleushub_publish_form(
    $pageurl->out(false),
    [
        'id' => $courseid,
        'suggested' => $hint['suggested'],
        'picker_html' => $pickerhtml,
    ]
);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
}

$publisherror = null;
if ($data = $form->get_data()) {
    try {
        $result = publisher::publish(
            $courseid,
            (string) $data->severity,
            trim((string) $data->notes) !== '' ? trim((string) $data->notes) : null,
            null,
            (int) $USER->id
        );
        redirect(
            $pageurl,
            get_string('publishsuccess', 'local_nucleushub', (object) [
                'version' => $result['versionnumber'],
                'size' => display_size($result['size']),
            ]),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\Throwable $e) {
        $publisherror = $e->getMessage();
    }
}

echo $OUTPUT->header();

// ---------- Top info card (Nucleus chrome) ----------
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
  line-height: 1.55;
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
  font-size: 14px;
  font-weight: 600;
  color: #d97706;
  margin: 0;
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
.nfp-card-pill-warn {
  background: #fffbeb;
  border-color: #fde68a;
  color: #92400e;
}
.nfp-card-muted { color: #8a8d8a; }
.nfp-card-callout {
  margin-top: 10px;
  padding: 8px 12px;
  background: #fffbeb;
  border-left: 3px solid #f59e0b;
  color: #92400e;
  font-size: 12px;
  border-radius: 0 3px 3px 0;
}

.nfp-severity-picker {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  margin: 4px 0 18px 0;
}
@media (max-width: 720px) {
  .nfp-severity-picker { grid-template-columns: 1fr; }
}
.nfp-sev-card {
  background: #fff;
  border: 1px solid #d0d4d8;
  border-radius: 5px;
  padding: 11px 12px;
  text-align: left;
  cursor: pointer;
  transition: all 0.12s ease;
  font-family: inherit;
  position: relative;
  min-width: 0;
}
.nfp-sev-card:hover {
  border-color: #b0b4b8;
  background: #fafbfc;
}
.nfp-sev-card.nfp-sev-selected {
  border-color: #f0a255;
  background: #fffaf3;
  box-shadow: 0 0 0 2px rgba(240, 162, 85, 0.15);
}
.nfp-sev-head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 8px;
  margin-bottom: 8px;
}
.nfp-sev-name {
  font-weight: 600;
  font-size: 14px;
  color: #1d2326;
  text-transform: capitalize;
}
.nfp-sev-version {
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
  font-size: 13px;
  color: #6a6e72;
  margin-bottom: 6px;
}
.nfp-sev-version strong {
  color: #d97706;
  font-weight: 600;
}
.nfp-sev-caption {
  font-size: 12px;
  color: #6a6e72;
  line-height: 1.4;
}
.nfp-sev-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 7px;
  background: #fef3c7;
  color: #92400e;
  border-radius: 10px;
  font-size: 10.5px;
  font-weight: 500;
}
.nfp-sev-badge i { font-size: 9px; }

.nfp-notes-input { font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 13px; }

/* Stack form rows: label on top, field full-width below. Avoids the
   Moodle default 3/9 split that leaves big gutters next to text
   inputs on full-width pages. Scoped to .nfp-form so we don't bleed
   onto other forms (we don't render any but it's good hygiene). */
.nfp-form .col-md-3,
.nfp-form .col-md-9 {
  flex: 0 0 100%;
  max-width: 100%;
}
.nfp-form .col-md-3 {
  text-align: left;
  padding: 0 0 4px 0;
}
/* Action-buttons row: the label column is empty/decorative — collapse it
   so the submit + cancel start at the form's left edge. */
.nfp-form fieldset[id^="id_buttonar"] .col-md-3,
.nfp-form #fgroup_id_buttonar .col-md-3,
.nfp-form .fitem [data-fieldtype="submit"] ~ * .col-md-3 {
  display: none;
}
.nfp-form fieldset[id^="id_buttonar"] .col-md-9,
.nfp-form #fgroup_id_buttonar .col-md-9 {
  margin-left: 0;
}
</style>
<?php

if ($family) {
    $pending = (int) $draft->pendingchangecount;
    echo '<div class="nfp-card">';
    // Line 1: family identity + last-published pill + pending pill.
    echo '<div class="nfp-card-line">';
    echo '<h3 class="nfp-card-title">' . s($family->slug) . '</h3>';
    echo '<span class="nfp-card-guid">· ' . substr((string) $family->guid, 0, 8) . '</span>';
    if ($lastversion) {
        echo '<span class="nfp-card-pill">v' . s($lastversion->versionnumber)
            . ' · ' . format_time(time() - (int) $lastversion->timepublished) . ' ago</span>';
    } else {
        echo '<span class="nfp-card-pill nfp-card-muted">'
            . s(get_string('familyneverpublished_short', 'local_nucleushub'))
            . '</span>';
    }
    $pendingclass = $pending > 0 ? 'nfp-card-pill nfp-card-pill-warn' : 'nfp-card-pill nfp-card-muted';
    echo '<span class="' . $pendingclass . '">'
        . s(get_string('pendingchanges_short', 'local_nucleushub', $pending))
        . '</span>';
    echo '</div>';
    // Optional callout: severity hint.
    if ($hint['suggested'] && $pending > 0) {
        echo '<div class="nfp-card-callout">'
            . get_string('severity_suggested', 'local_nucleushub', (object) [
                'severity' => s($hint['suggested']),
                'rationale' => s($hint['rationale'] ?? ''),
            ])
            . '</div>';
    }
    echo '</div>';
} else {
    echo '<div class="nfp-card">'
        . s(get_string('firstpublish_hint', 'local_nucleushub'))
        . '</div>';
}

if ($publisherror !== null) {
    echo $OUTPUT->notification($publisherror, \core\output\notification::NOTIFY_ERROR);
}

echo '<div class="nfp-form">';
$form->display();
echo '</div>';

// ---------- JS: card-click → hidden severity field ----------
?>
<script>
(function(){
    var cards = document.querySelectorAll('.nfp-sev-card');
    var hidden = document.querySelector('input[name="severity"]');
    if (!cards.length || !hidden) return;
    cards.forEach(function(card){
        card.addEventListener('click', function(){
            var sev = card.dataset.severity;
            hidden.value = sev;
            cards.forEach(function(c){
                var on = c === card;
                c.classList.toggle('nfp-sev-selected', on);
                c.setAttribute('aria-checked', on ? 'true' : 'false');
            });
        });
        // Keyboard support — space/enter activate.
        card.addEventListener('keydown', function(e){
            if (e.key === ' ' || e.key === 'Enter') {
                e.preventDefault();
                card.click();
            }
        });
    });
})();
</script>
<?php

echo $OUTPUT->footer();
