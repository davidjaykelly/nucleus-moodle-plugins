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
 * Event observers for local_nucleushub.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_completed',
        'callback'  => '\local_nucleushub\observer\completion_publisher::handle',
        'internal'  => false,
    ],
    // ADR-014 change detection. Every course-edit event on a
    // versioned hub course bumps the draft's pendingchangecount
    // and appends a changelog row. The observer filters to the
    // events that actually mean "the published snapshot and the
    // working course have diverged".
    [
        'eventname' => '\core\event\course_updated',
        'callback'  => '\local_nucleushub\observer\change_tracker::course_updated',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\course_module_created',
        'callback'  => '\local_nucleushub\observer\change_tracker::module_added',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\course_module_updated',
        'callback'  => '\local_nucleushub\observer\change_tracker::module_updated',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback'  => '\local_nucleushub\observer\change_tracker::module_deleted',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\course_section_updated',
        'callback'  => '\local_nucleushub\observer\change_tracker::section_updated',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\course_section_created',
        'callback'  => '\local_nucleushub\observer\change_tracker::section_created',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\course_section_deleted',
        'callback'  => '\local_nucleushub\observer\change_tracker::section_deleted',
        'internal'  => false,
    ],
    // Phase B1 Step 5 — hub-initiated GDPR cascade. Fires when an
    // admin deletes a shadow user directly on the hub; CP fans the
    // signal back out to the spoke for mirror-enrolment cleanup.
    [
        'eventname' => '\core\event\user_deleted',
        'callback'  => '\local_nucleushub\observer\revocation_recorder::handle',
        'internal'  => false,
    ],
];
