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
 * Admin settings for local_nucleusspoke.
 *
 * Hub-trust values are auto-configured by the wireSpokeToHub stage
 * during provisioning; the page is here for discovery / recovery.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_nucleusspoke',
        get_string('pluginname', 'local_nucleusspoke')
    );

    $settings->add(new admin_setting_heading(
        'local_nucleusspoke/intro',
        '',
        get_string('setting_intro_html', 'local_nucleusspoke')
    ));

    $settings->add(new admin_setting_heading(
        'local_nucleusspoke/hub_heading',
        get_string('setting_hub_heading', 'local_nucleusspoke'),
        get_string('setting_hub_desc', 'local_nucleusspoke')
    ));

    $settings->add(new admin_setting_configtext(
        'local_nucleusspoke/hubwwwroot',
        get_string('setting_hubwwwroot', 'local_nucleusspoke'),
        get_string('setting_hubwwwroot_desc', 'local_nucleusspoke'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_nucleusspoke/hubtoken',
        get_string('setting_hubtoken', 'local_nucleusspoke'),
        get_string('setting_hubtoken_desc', 'local_nucleusspoke'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_nucleusspoke/hubconnecturl',
        get_string('setting_hubconnecturl', 'local_nucleusspoke'),
        get_string('setting_hubconnecturl_desc', 'local_nucleusspoke'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_nucleusspoke/spokename',
        get_string('setting_spokename', 'local_nucleusspoke'),
        get_string('setting_spokename_desc', 'local_nucleusspoke'),
        'default',
        PARAM_ALPHANUMEXT
    ));

    $ADMIN->add('localplugins', $settings);

    // Direct links to the spoke's federation pages — surfaces them
    // in Site administration → Plugins → Local plugins for operators
    // who'd rather navigate via the admin tree than the status bar.
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_nucleusspoke_versions',
        get_string('versions_title', 'local_nucleusspoke'),
        new moodle_url('/local/nucleusspoke/versions.php'),
        'local/nucleusspoke:pull'
    ));
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_nucleusspoke_catalog',
        get_string('catalog_title', 'local_nucleusspoke'),
        new moodle_url('/local/nucleusspoke/catalog.php'),
        'local/nucleusspoke:pull'
    ));
}
