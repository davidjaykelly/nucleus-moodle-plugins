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

namespace auth_nucleus;

use auth_nucleus\local\config;
use auth_nucleus\local\silent;
use core\hook\output\before_http_headers;

/**
 * Hook callbacks for auth_nucleus.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Before headers: start silent sign-in (prompt=none) for someone who
     * isn't signed in here, when {@see silent::should_start()} says so.
     *
     * Never throws: if anything goes wrong, the page is shown as it would
     * have been, and the reason goes to the error log.
     *
     * @param before_http_headers $hook
     */
    public static function before_http_headers(before_http_headers $hook): void {
        global $PAGE;

        // Cheap tests first: most page views are by people who are signed
        // in, or in a session that has already been checked, and most sites
        // with this plugin (the hub, for one) don't use hub sign-in.
        if (
            during_initial_install()
            || (isloggedin() && !isguestuser())
            || silent::was_checked()
            || !config::silentsignin()
            || !config::is_enabled()
        ) {
            return;
        }

        try {
            $request = silent::current_request();
            if (!silent::should_start($PAGE, $request)) {
                return;
            }
            $url = silent::start($request['url']);
        } catch (\Throwable $e) {
            // Only the class: messages from lower layers can carry request data.
            // phpcs:ignore
            error_log('auth_nucleus: silent sign-in not started: ' . get_class($e));
            return;
        }
        redirect($url);
    }
}
