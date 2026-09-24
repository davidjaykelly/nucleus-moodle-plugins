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

namespace local_nucleusspoke;

use core\hook\output\before_http_headers;
use local_nucleusspoke\local\lock;
use local_nucleusspoke\local\statusbar;

/**
 * Hook callbacks for local_nucleusspoke.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Before headers, on a pulled course's page:
     * - for people who can pull, a notice when a newer version is
     *   waiting or the hub has deprecated this one;
     * - for teachers and managers, a notice (and a header badge) when
     *   the hub locked editing on this course. Learners see neither.
     *
     * Notices use the theme's own style, below the navbar.
     *
     * @param before_http_headers $hook
     */
    public static function before_http_headers(before_http_headers $hook): void {
        global $PAGE;

        if (during_initial_install() || !isloggedin() || isguestuser()) {
            return;
        }
        if (!str_starts_with((string) $PAGE->pagetype, 'course-view-')) {
            return;
        }
        $courseid = (int) ($PAGE->course->id ?? 0);
        if ($courseid <= 0 || $courseid === (int) SITEID) {
            return;
        }

        $banner = statusbar::banner($PAGE);
        if ($banner) {
            \core\notification::warning(s($banner['message']) . ' ' . \html_writer::link(
                $banner['action']['url'],
                s($banner['action']['label']),
                ['class' => 'alert-link']
            ));
        }

        $context = \context_course::instance($courseid);
        if (has_capability('moodle/course:viewhiddenactivities', $context) && lock::is_locked($courseid)) {
            \core\notification::info(s(get_string('locked_banner_body', 'local_nucleusspoke')));
            $PAGE->add_header_action(\html_writer::span(
                get_string('lockedbadge', 'local_nucleusspoke'),
                'badge text-bg-secondary text-wrap'
            ));
        }
    }
}
