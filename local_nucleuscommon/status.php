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
 * Fresh state for the Nucleus bar (polled by local_nucleuscommon/statusbar).
 *
 * Returns JSON: the state hash plus the bar's segments, actions and
 * panel as HTML rendered from the plugin's templates.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
// Don't hold the session lock while polling: the bar only reads.
define('READ_ONLY_SESSION', true);

require(__DIR__ . '/../../config.php');

use local_nucleuscommon\local\site;
use local_nucleuscommon\output\statusbar;

require_login(null, false);
if (!site::is_operator()) {
    throw new \required_capability_exception(context_system::instance(), 'local/nucleushub:publish', 'nopermissions', '');
}

// The bar describes the page it was printed on, so take that page's
// type and course from the URL it was given.
$pagetype = optional_param('pagetype', '', PARAM_ALPHANUMEXT);
$courseid = optional_param('courseid', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/nucleuscommon/status.php'));
$course = null;
if ($courseid > 0 && $courseid != SITEID) {
    $course = $DB->get_record('course', ['id' => $courseid]);
    if ($course && !can_access_course($course)) {
        $course = null;
    }
}
if ($course) {
    $PAGE->set_course($course);
} else {
    // The course has gone, or this user can't see it: describe the site only.
    $PAGE->set_context(context_system::instance());
}
if ($pagetype !== '') {
    $PAGE->set_pagetype($pagetype);
}

$renderer = $PAGE->get_renderer('local_nucleuscommon');
echo json_encode($renderer->statusbar_parts(new statusbar($PAGE)));
