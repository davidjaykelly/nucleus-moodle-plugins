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

namespace local_nucleusspoke\local;

/**
 * Where courses pulled from the hub go, and what they're called.
 *
 * Learners see these names, so they never include Nucleus's own words
 * or identifiers.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class courses {
    /** @var string idnumber of the category Nucleus creates when it needs one. */
    public const CATEGORY_IDNUMBER = 'nucleus_federation';

    /**
     * The category to put a pulled course in.
     *
     * The requested category when it exists; otherwise a "Shared
     * courses" category, created once and found again by its idnumber
     * (so renaming it is safe).
     *
     * @param int|null $requested
     * @return int course_categories.id
     */
    public static function category(?int $requested): int {
        global $DB;
        if ($requested !== null && $DB->record_exists('course_categories', ['id' => $requested])) {
            return $requested;
        }
        $existing = $DB->get_record('course_categories', ['idnumber' => self::CATEGORY_IDNUMBER]);
        if ($existing) {
            return (int) $existing->id;
        }
        $created = \core_course_category::create([
            'name' => get_string('sharedcourses_category', 'local_nucleusspoke'),
            'idnumber' => self::CATEGORY_IDNUMBER,
            'description' => get_string('sharedcourses_category_desc', 'local_nucleusspoke'),
        ]);
        return (int) $created->id;
    }

    /**
     * A short name that no other course has: $base, then $base-2, $base-3...
     *
     * @param string $base
     * @return string
     */
    public static function unique_shortname(string $base): string {
        global $DB;
        $candidate = $base;
        $i = 2;
        while ($DB->record_exists('course', ['shortname' => $candidate])) {
            $candidate = $base . '-' . $i;
            $i++;
        }
        return $candidate;
    }

    /**
     * Give a restored course the names it was created with.
     *
     * Moodle's restore adds " copy 1" to the full name, and "_1" to the
     * short name, when another course already has the full name, which
     * is the normal case when a spoke pulls a second version of a
     * course. Learners should see the real name. The short name was
     * checked for uniqueness when the course was created, so it is
     * still free.
     *
     * @param int $courseid
     * @param string $fullname
     * @param string $shortname
     */
    public static function set_names(int $courseid, string $fullname, string $shortname): void {
        global $DB;
        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname', MUST_EXIST);
        $changes = [];
        if ($fullname !== '' && $course->fullname !== $fullname) {
            $changes['fullname'] = $fullname;
        }
        if (
            $shortname !== '' && $course->shortname !== $shortname
                && !$DB->record_exists_select('course', 'shortname = ? AND id <> ?', [$shortname, $courseid])
        ) {
            $changes['shortname'] = $shortname;
        }
        if (!$changes) {
            return;
        }
        $DB->update_record('course', (object) (['id' => $courseid] + $changes));
        rebuild_course_cache($courseid, true);
    }
}
