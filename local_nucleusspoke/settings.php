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
 * Admin tree entries and hub connection settings for local_nucleusspoke.
 *
 * Nucleus sets the hub connection when a spoke joins its hub. On sites
 * DK Labs hosts the values are shown but can't be changed here.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_nucleuscommon\local\site;

site::add_admin_category($ADMIN);

$isspoke = site::is_spoke();
$hosted = site::is_hosted();

// Spoke pages only on sites that are spokes. Registered outside the
// $hassiteconfig check so managers with the pull capability (but not
// site config) can still open them.
if ($isspoke) {
    $ADMIN->add(site::ADMIN_CATEGORY, new admin_externalpage(
        'local_nucleusspoke_catalog',
        new lang_string('catalog_title', 'local_nucleusspoke'),
        new moodle_url('/local/nucleusspoke/catalog.php'),
        'local/nucleusspoke:pull'
    ));
    $ADMIN->add(site::ADMIN_CATEGORY, new admin_externalpage(
        'local_nucleusspoke_versions',
        new lang_string('versions_title', 'local_nucleusspoke'),
        new moodle_url('/local/nucleusspoke/versions.php'),
        'local/nucleusspoke:pull'
    ));
}

// The hub connection. A self-hosted site that isn't a spoke yet still
// needs it, to be pointed at its hub.
if ($hassiteconfig && ($isspoke || !$hosted)) {
    $settings = new admin_settingpage('local_nucleusspoke', new lang_string('settings_hub', 'local_nucleusspoke'));

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_heading('local_nucleusspoke/intro', '', html_writer::div(
            s(get_string($hosted ? 'setting_intro_hosted' : 'setting_intro', 'local_nucleusspoke')),
            'alert alert-info'
        )));
        $settings->add(new admin_setting_heading(
            'local_nucleusspoke/hub_heading',
            new lang_string('setting_hub_heading', 'local_nucleusspoke'),
            new lang_string('setting_hub_desc', 'local_nucleusspoke')
        ));

        if ($hosted) {
            // Shown, not saved from here: only DK Labs changes these.
            $notset = get_string('setting_notset', 'local_nucleuscommon');
            $value = function (string $name) use ($notset): string {
                $v = (string) get_config('local_nucleusspoke', $name);
                return s($v !== '' ? $v : $notset);
            };
            $settings->add(new admin_setting_description(
                'local_nucleusspoke/hubwwwroot_view',
                new lang_string('setting_huburl', 'local_nucleusspoke'),
                $value('hubwwwroot')
            ));
            $settings->add(new admin_setting_description(
                'local_nucleusspoke/hubtoken_view',
                new lang_string('setting_hubtoken', 'local_nucleusspoke'),
                s((string) get_config('local_nucleusspoke', 'hubtoken') !== ''
                    ? get_string('setting_secretset', 'local_nucleuscommon')
                : $notset)
            ));
            $settings->add(new admin_setting_description(
                'local_nucleusspoke/hubconnecturl_view',
                new lang_string('setting_hubconnecturl', 'local_nucleusspoke'),
                $value('hubconnecturl')
            ));
            $settings->add(new admin_setting_description(
                'local_nucleusspoke/spokename_view',
                new lang_string('setting_spokename', 'local_nucleusspoke'),
                $value('spokename')
            ));
        } else {
            $settings->add(new admin_setting_configtext(
                'local_nucleusspoke/hubwwwroot',
                new lang_string('setting_huburl', 'local_nucleusspoke'),
                new lang_string('setting_huburl_desc', 'local_nucleusspoke'),
                '',
                PARAM_URL
            ));
            $settings->add(new admin_setting_configpasswordunmask(
                'local_nucleusspoke/hubtoken',
                new lang_string('setting_hubtoken', 'local_nucleusspoke'),
                new lang_string('setting_hubtoken_desc', 'local_nucleusspoke'),
                ''
            ));
            $settings->add(new admin_setting_configtext(
                'local_nucleusspoke/hubconnecturl',
                new lang_string('setting_hubconnecturl', 'local_nucleusspoke'),
                new lang_string('setting_hubconnecturl_desc', 'local_nucleusspoke'),
                '',
                PARAM_URL
            ));
            $settings->add(new admin_setting_configtext(
                'local_nucleusspoke/spokename',
                new lang_string('setting_spokename', 'local_nucleusspoke'),
                new lang_string('setting_spokename_desc', 'local_nucleusspoke'),
                'default',
                PARAM_ALPHANUMEXT
            ));
        }
    }

    $ADMIN->add(site::ADMIN_CATEGORY, $settings);
}
