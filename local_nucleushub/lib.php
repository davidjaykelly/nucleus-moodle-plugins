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
 * Library callbacks for local_nucleushub.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add "Add to federation" or "Publish version" to a hub course's
 * More menu, for people who can publish.
 *
 * A course that isn't in the federation yet goes to promote.php first
 * (to choose its identifier); after that, to publish.php.
 *
 * @param settings_navigation $nav
 * @param context $context
 */
function local_nucleushub_extend_settings_navigation(settings_navigation $nav, context $context): void {
    global $DB;

    if (!($context instanceof context_course) || $context->instanceid == SITEID) {
        return;
    }
    if (!\local_nucleuscommon\local\site::can_publish()) {
        return;
    }
    $coursenode = $nav->find('courseadmin', navigation_node::TYPE_COURSE);
    if (!$coursenode) {
        return;
    }
    $courseid = (int) $context->instanceid;
    if ($DB->record_exists('local_nucleushub_draft', ['hubcourseid' => $courseid])) {
        $label = get_string('publishversion', 'local_nucleushub');
        $url = new moodle_url('/local/nucleushub/publish.php', ['id' => $courseid]);
    } else {
        $label = get_string('statusbar_hub_addtofederation', 'local_nucleushub');
        $url = new moodle_url('/local/nucleushub/promote.php', ['courseid' => $courseid]);
    }
    $coursenode->add(
        $label,
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'nucleushub_publishversion',
        new pix_icon('i/upload', '')
    );
}
