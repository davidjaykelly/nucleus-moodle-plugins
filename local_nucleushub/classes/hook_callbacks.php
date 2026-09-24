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

namespace local_nucleushub;

use core\hook\output\before_http_headers;
use local_nucleushub\local\statusbar;

/**
 * Hook callbacks for local_nucleushub.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Before headers: on a hub course page with unpublished changes,
     * show a notice (in the theme's own style) with a link to publish.
     *
     * @param before_http_headers $hook
     */
    public static function before_http_headers(before_http_headers $hook): void {
        global $PAGE;

        if (during_initial_install() || !isloggedin() || isguestuser()) {
            return;
        }
        $banner = statusbar::banner($PAGE);
        if (!$banner) {
            return;
        }
        \core\notification::warning(s($banner['message']) . ' ' . \html_writer::link(
            $banner['action']['url'],
            s($banner['action']['label']),
            ['class' => 'alert-link']
        ));
    }
}
