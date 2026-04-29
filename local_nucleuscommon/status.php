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
 * JSON endpoint backing the Nucleus status bar's live polling.
 *
 * The status bar's JS (in lib.php) hits this every few seconds
 * to refresh widget state — pending counts, queue health, mode
 * changes — without forcing a full page reload. Same auth gate
 * as the bar itself: federation operators only.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/nucleuscommon/lib.php');

require_login(null, false);

$sys = context_system::instance();
if (!has_capability('local/nucleushub:publish', $sys)
        && !has_capability('local/nucleusspoke:pull', $sys)) {
    throw new \moodle_exception('nopermissions', 'error', '', 'view status bar');
}

// Rehydrate $PAGE from the params the bar baked into its status URL
// at initial render. Widget functions key off $PAGE->pagetype and
// $PAGE->course->id (e.g. "show only on course-view-* pages") and
// would otherwise see the AJAX endpoint's own context — making the
// course-aware widget return null and wiping the actions on poll.
$pagetype = optional_param('pagetype', '', PARAM_TEXT);
$courseid = optional_param('courseid', 0, PARAM_INT);

if ($courseid > 0 && $courseid != SITEID) {
    try {
        $PAGE->set_course(get_course($courseid));
    } catch (\Throwable $e) {
        // Course gone (deleted between render and poll); fall through.
    }
}
if ($pagetype !== '' && preg_match('/^[a-z0-9_-]+$/', $pagetype) === 1) {
    $PAGE->set_pagetype($pagetype);
}

$state = local_nucleuscommon_nsb_render_state();

// Hash so the client can short-circuit DOM updates when nothing changed.
$hash = sha1($state['segments'] . '|' . $state['actions'] . '|' . $state['panel']);

echo json_encode([
    'hash'     => $hash,
    'segments' => $state['segments'],
    'actions'  => $state['actions'],
    'panel'    => $state['panel'],
]);
