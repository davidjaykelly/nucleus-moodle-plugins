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
 * Shared checks for the sign-in web services (ADR-023).
 *
 * The sign-in itself lives in auth_nucleus, which depends on this
 * plugin. These services therefore check that it's installed rather
 * than depending on it, so a customer-run spoke without it gets a clear
 * error instead of a missing class.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signin {
    /**
     * Is auth_nucleus installed?
     *
     * @return bool
     */
    public static function plugin_installed(): bool {
        return get_config('auth_nucleus', 'version') !== false
            && class_exists(\auth_nucleus\local\config::class);
    }

    /**
     * Stop unless auth_nucleus is installed.
     *
     * @throws \moodle_exception
     */
    public static function require_plugin(): void {
        if (!self::plugin_installed()) {
            throw new \moodle_exception('signin_pluginmissing', 'local_nucleusspoke');
        }
    }

    /**
     * Stop unless the caller can configure the site.
     *
     * These services change how everyone signs in. The control plane's
     * token belongs to the site admin; nobody else should call them. No
     * validate_context(): the service account's profile is often
     * incomplete, which would make it throw (see receive_notification).
     */
    public static function require_site_config(): void {
        require_capability('moodle/site:config', \context_system::instance());
    }
}
