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

use local_nucleushub\version\publisher;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Add a course to the federation: choose its identifier.
 *
 * Custom data: courseid, suggestedslug, federationid.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class promote_form extends \moodleform {
    /** @var string Id of the live preview under the identifier field. */
    public const PREVIEW_ID = 'local-nucleushub-slugpreview';

    /**
     * Form fields.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $suggested = (string) $this->_customdata['suggestedslug'];

        $mform->addElement('text', 'slug', get_string('promote_slug', 'local_nucleushub'), [
            'size' => 40,
            'maxlength' => 120,
            'aria-describedby' => self::PREVIEW_ID,
        ]);
        $mform->setType('slug', PARAM_RAW_TRIMMED);
        $mform->setDefault('slug', $suggested);
        $mform->addRule('slug', null, 'required', null, 'client');
        $mform->addHelpButton('slug', 'promote_slug', 'local_nucleushub');

        // What spokes will see, updated as the identifier is typed
        // (local_nucleushub/slug_preview).
        $mform->addElement('static', 'slugpreview', '', \html_writer::tag(
            'p',
            get_string('promote_slug_preview_label', 'local_nucleushub') . ' '
                . \html_writer::tag('code', s($suggested), ['data-region' => 'slug']),
            ['id' => self::PREVIEW_ID, 'class' => 'form-text mb-0', 'aria-live' => 'polite']
        ));

        $mform->addElement('hidden', 'courseid', (int) $this->_customdata['courseid']);
        $mform->setType('courseid', PARAM_INT);

        $this->add_action_buttons(true, get_string('promote_submit', 'local_nucleushub'));
    }

    /**
     * The identifier needs a letter or number and must be free in this federation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        global $DB;
        $errors = parent::validation($data, $files);
        $slug = publisher::slugify((string) ($data['slug'] ?? ''));
        if ($slug === '' || $slug === 'course') {
            $errors['slug'] = get_string('promote_slug_invalid', 'local_nucleushub');
        } else if (
            $DB->record_exists('local_nucleuscommon_family', [
            'hubfederationid' => $this->_customdata['federationid'],
            'slug' => $slug,
            ])
        ) {
            $errors['slug'] = get_string('promote_slug_clash', 'local_nucleushub', $slug);
        }
        return $errors;
    }
}
