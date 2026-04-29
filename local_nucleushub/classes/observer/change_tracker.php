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
 * Event observers that detect edits on a versioned hub course and
 * accumulate them as pending changes on the draft (ADR-014
 * Phase 1). The status bar reads `local_nucleushub_draft.pendingchangecount`
 * to show "N changes since v1.0.0". publisher::publish clears the
 * counter + changelog on a successful publish.
 *
 * No-op when the course isn't registered as a versioned draft —
 * regular Moodle courses behave unchanged. Failures are swallowed
 * with `debugging()` so a bad observer never breaks the triggering
 * action.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

namespace local_nucleushub\observer;

defined('MOODLE_INTERNAL') || die();

/**
 * Observers for course/module/section change events. Each method
 * is named after the event it handles so the mapping in
 * `db/events.php` reads as a table of contents.
 */
class change_tracker {

    /**
     * Record a change for a versioned hub course. Idempotent-ish:
     * the counter always increments; duplicate events mean
     * duplicate log rows, which the release-notes summariser will
     * dedupe later. For Phase 1 we just count.
     *
     * @param int $hubcourseid Moodle course id.
     * @param string $eventkind Normalised event name.
     * @param string|null $objecttype 'cm' | 'section' | 'course' | etc.
     * @param int|null $objectid Object id the event targeted.
     * @param int $actoruserid Acting user.
     */
    private static function record(
        int $hubcourseid,
        string $eventkind,
        ?string $objecttype,
        ?int $objectid,
        int $actoruserid
    ): void {
        global $DB;

        if ($hubcourseid === (int) SITEID) {
            return;
        }
        $draft = $DB->get_record('local_nucleushub_draft', ['hubcourseid' => $hubcourseid]);
        if (!$draft) {
            // Course isn't versioned — no tracking required.
            return;
        }

        try {
            $now = time();
            $DB->insert_record('local_nucleushub_changelog', (object) [
                'familyid' => (int) $draft->familyid,
                'eventkind' => $eventkind,
                'objecttype' => $objecttype,
                'objectid' => $objectid,
                'actoruserid' => $actoruserid,
                'timecreated' => $now,
            ]);
            $DB->execute(
                "UPDATE {local_nucleushub_draft}
                    SET pendingchangecount = pendingchangecount + 1,
                        timelastedit = :now
                  WHERE id = :id",
                ['now' => $now, 'id' => (int) $draft->id]
            );
        } catch (\Throwable $e) {
            debugging(
                'change_tracker failed to record change: ' . $e->getMessage(),
                DEBUG_NORMAL
            );
        }
    }

    public static function course_updated(\core\event\course_updated $event): void {
        self::record(
            (int) $event->courseid,
            'course_updated',
            'course',
            (int) $event->objectid,
            (int) $event->userid
        );
    }

    public static function module_added(\core\event\course_module_created $event): void {
        self::record(
            (int) $event->courseid,
            'module_added',
            'cm',
            (int) $event->objectid,
            (int) $event->userid
        );
    }

    public static function module_updated(\core\event\course_module_updated $event): void {
        self::record(
            (int) $event->courseid,
            'module_updated',
            'cm',
            (int) $event->objectid,
            (int) $event->userid
        );
    }

    public static function module_deleted(\core\event\course_module_deleted $event): void {
        self::record(
            (int) $event->courseid,
            'module_deleted',
            'cm',
            (int) $event->objectid,
            (int) $event->userid
        );
    }

    public static function section_updated(\core\event\course_section_updated $event): void {
        self::record(
            (int) $event->courseid,
            'section_updated',
            'section',
            (int) $event->objectid,
            (int) $event->userid
        );
    }

    public static function section_created(\core\event\course_section_created $event): void {
        self::record(
            (int) $event->courseid,
            'section_added',
            'section',
            (int) $event->objectid,
            (int) $event->userid
        );
    }

    public static function section_deleted(\core\event\course_section_deleted $event): void {
        self::record(
            (int) $event->courseid,
            'section_deleted',
            'section',
            (int) $event->objectid,
            (int) $event->userid
        );
    }
}
