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

namespace local_nucleushub\form;

use local_nucleushub\local\statusbar;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Publish a new version: type of change, release notes, editing lock.
 *
 * Custom data: id (course id), suggested (patch|minor|major|null),
 * preview (severity => next version number).
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class publish_form extends \moodleform {
    /** @var string[] Types of change, smallest first. */
    public const SEVERITIES = ['patch', 'minor', 'major'];

    /**
     * Form fields.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $suggested = $this->_customdata['suggested'] ?? null;
        $preview = $this->_customdata['preview'];

        // Type of change: native radios in a fieldset, each with the
        // version it would create and what it's for.
        $radios = [];
        foreach (self::SEVERITIES as $severity) {
            $text = \html_writer::tag('strong', s(statusbar::severity_label($severity)))
                . ' - ' . s(get_string('publish_nextversion', 'local_nucleushub', $preview[$severity]));
            if ($suggested === $severity) {
                $text .= ' ' . \html_writer::span(
                    get_string('severity_suggested_badge', 'local_nucleushub'),
                    'badge text-bg-info'
                );
            }
            $text .= \html_writer::span(
                get_string('severity_' . $severity . '_caption', 'local_nucleushub'),
                'd-block form-text'
            );
            $radios[] = $mform->createElement('radio', 'severity', '', $text, $severity);
        }
        $mform->addGroup(
            $radios,
            'severitygroup',
            get_string('severity', 'local_nucleushub'),
            \html_writer::div('', 'w-100'),
            false
        );
        $mform->setType('severity', PARAM_ALPHA);
        $mform->setDefault('severity', $suggested ?: 'patch');
        $mform->addElement(
            'static',
            'severitynote',
            '',
            \html_writer::tag('p', s(get_string('severity_note', 'local_nucleushub')), ['class' => 'form-text mb-0'])
        );

        $mform->addElement(
            'textarea',
            'notes',
            get_string('releasenotes', 'local_nucleushub'),
            ['rows' => 5, 'cols' => 60, 'wrap' => 'soft']
        );
        $mform->setType('notes', PARAM_TEXT);
        $mform->addHelpButton('notes', 'releasenotes', 'local_nucleushub');

        // Optional lock: spokes that pull this version apply capability
        // overrides so their editing teachers can't change the course.
        $mform->addElement(
            'advcheckbox',
            'lockedforspokeedit',
            get_string('publish_lockedit_label', 'local_nucleushub'),
            get_string('publish_lockedit_caption', 'local_nucleushub'),
            null,
            [0, 1]
        );
        $mform->setDefault('lockedforspokeedit', 0);

        $mform->addElement('hidden', 'id', (int) $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons(true, get_string('publishversion', 'local_nucleushub'));
    }

    /**
     * A type of change is required; minor and major need release notes.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $severity = (string) ($data['severity'] ?? '');
        if (!in_array($severity, self::SEVERITIES, true)) {
            $errors['severitygroup'] = get_string('invalidseverity', 'local_nucleushub');
        } else if ($severity !== 'patch' && trim((string) ($data['notes'] ?? '')) === '') {
            $errors['notes'] = get_string(
                'releasenotesrequired',
                'local_nucleushub',
                \core_text::strtolower(statusbar::severity_label($severity))
            );
        }
        return $errors;
    }
}
