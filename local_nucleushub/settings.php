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
 * Admin settings for local_nucleushub.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_nucleushub',
        get_string('pluginname', 'local_nucleushub')
    );

    $settings->add(new admin_setting_heading(
        'local_nucleushub/intro',
        '',
        get_string('setting_intro_html', 'local_nucleushub')
    ));

    $settings->add(new admin_setting_configtext(
        'local_nucleushub/spoketoken',
        get_string('setting_spoketoken', 'local_nucleushub'),
        get_string('setting_spoketoken_desc', 'local_nucleushub'),
        '',
        PARAM_ALPHANUM
    ));

    $ADMIN->add('localplugins', $settings);

    // Direct link to the families dashboard from the admin tree —
    // mirrors the spoke's Versions / Catalog entries.
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_nucleushub_families',
        get_string('families_title', 'local_nucleushub'),
        new moodle_url('/local/nucleushub/families.php'),
        'local/nucleushub:publish'
    ));
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_nucleushub_spokes',
        get_string('spokes_title', 'local_nucleushub'),
        new moodle_url('/local/nucleushub/spokes.php'),
        'local/nucleushub:publish'
    ));
}
