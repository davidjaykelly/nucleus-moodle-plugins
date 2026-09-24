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

/**
 * Install steps for auth_nucleus.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Set the defaults and lock the profile fields the hub owns.
 *
 * On hosted sites the settings page is read-only, so Moodle has no
 * admin setting to take these defaults from.
 *
 * @return bool
 */
function xmldb_auth_nucleus_install(): bool {
    if (get_config('auth_nucleus', 'autoredirect') === false) {
        set_config('autoredirect', 1, 'auth_nucleus');
    }
    if (get_config('auth_nucleus', 'singlesignout') === false) {
        set_config('singlesignout', 1, 'auth_nucleus');
    }
    \auth_nucleus\local\config::lock_profile_fields();
    return true;
}
