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

namespace local_nucleuscommon;

use core\hook\output\before_footer_html_generation;
use core\hook\output\before_http_headers;
use local_nucleuscommon\output\statusbar;

/**
 * Hook callbacks for local_nucleuscommon: the Nucleus bar.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /** @var bool Whether this request decided to show the bar. */
    private static bool $showbar = false;

    /**
     * Before headers: decide whether this page gets the bar, and load
     * its script.
     *
     * @param before_http_headers $hook
     */
    public static function before_http_headers(before_http_headers $hook): void {
        global $PAGE;

        self::$showbar = statusbar::should_show($PAGE);
        if (!self::$showbar) {
            return;
        }
        $PAGE->add_body_class('local-nucleuscommon-hasbar');
        $PAGE->requires->js_call_amd('local_nucleuscommon/statusbar', 'init', ['#local-nucleuscommon-bar']);
    }

    /**
     * Before the footer: print the bar. Its script moves it to the end
     * of the page.
     *
     * @param before_footer_html_generation $hook
     */
    public static function before_footer_html_generation(before_footer_html_generation $hook): void {
        global $PAGE;

        if (!self::$showbar) {
            return;
        }
        self::$showbar = false;
        $renderer = $PAGE->get_renderer('local_nucleuscommon');
        $hook->add_html($renderer->render(new statusbar($PAGE)));
    }
}
