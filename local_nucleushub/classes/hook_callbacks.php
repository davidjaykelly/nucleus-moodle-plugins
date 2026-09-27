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

use core\hook\after_config;
use core\hook\output\before_http_headers;
use local_nucleushub\local\oidc\provider;
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
     * After config: on the authorisation endpoint, answer a `prompt=none`
     * request from a session that hasn't finished multi-factor
     * authentication with login_required, before tool_mfa's own
     * after_config callback sends the browser to its page (a silent check
     * must never show one). Registered with a higher priority than
     * tool_mfa's so it runs first; see provider::prompt_none_before_mfa().
     *
     * @param after_config $hook
     */
    public static function after_config(after_config $hook): void {
        global $CFG, $SCRIPT;

        if (during_initial_install() || !empty($CFG->upgraderunning) || CLI_SCRIPT || AJAX_SCRIPT || WS_SERVER) {
            return;
        }
        if ($SCRIPT !== provider::PATH . '/authorize.php') {
            return;
        }
        $url = provider::prompt_none_before_mfa($_GET);
        if ($url !== null) {
            @header('Cache-Control: no-store');
            redirect($url);
        }
    }

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
