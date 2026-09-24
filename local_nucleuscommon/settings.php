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
 * Federation settings for local_nucleuscommon.
 *
 * Nucleus sets these when it creates a site or the site joins a
 * federation. On sites DK Labs hosts (local_nucleuscommon/hosted,
 * forced in the image's config.php) the federation and connection
 * values are shown but can't be changed here, the portal link and
 * support contact are forced in config.php, and the event transport
 * section is hidden. Self-hosted and customer-run sites can edit them.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_nucleuscommon\local\site;

site::add_admin_category($ADMIN);

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_nucleuscommon', new lang_string('settings_federation', 'local_nucleuscommon'));

    if ($ADMIN->fulltree) {
        $hosted = site::is_hosted();
        $notset = get_string('setting_notset', 'local_nucleuscommon');

        $settings->add(new admin_setting_heading('local_nucleuscommon/intro', '', html_writer::div(
            s(get_string($hosted ? 'setting_intro_hosted' : 'setting_intro', 'local_nucleuscommon')),
            'alert alert-info'
        )));

        // Federation.
        $settings->add(new admin_setting_heading(
            'local_nucleuscommon/identity_heading',
            new lang_string('setting_identity_heading', 'local_nucleuscommon'),
            new lang_string('setting_identity_desc', 'local_nucleuscommon')
        ));
        if ($hosted) {
            // Shown, not saved from here: only DK Labs changes it.
            $federationid = (string) get_config('local_nucleuscommon', 'federationid');
            $settings->add(new admin_setting_description(
                'local_nucleuscommon/federationid_view',
                new lang_string('setting_federationid', 'local_nucleuscommon'),
                s($federationid !== '' ? $federationid : $notset)
            ));
        } else {
            $settings->add(new admin_setting_configtext(
                'local_nucleuscommon/federationid',
                new lang_string('setting_federationid', 'local_nucleuscommon'),
                new lang_string('setting_federationid_desc', 'local_nucleuscommon'),
                '',
                PARAM_ALPHANUMEXT
            ));
        }

        // Connection to Nucleus.
        $settings->add(new admin_setting_heading(
            'local_nucleuscommon/cp_heading',
            new lang_string('setting_cp_heading', 'local_nucleuscommon'),
            new lang_string('setting_cp_desc', 'local_nucleuscommon')
        ));
        if ($hosted) {
            $cpbaseurl = (string) get_config('local_nucleuscommon', 'cpbaseurl');
            $settings->add(new admin_setting_description(
                'local_nucleuscommon/cpbaseurl_view',
                new lang_string('setting_cpbaseurl', 'local_nucleuscommon'),
                s($cpbaseurl !== '' ? $cpbaseurl : $notset)
            ));
            $settings->add(new admin_setting_description(
                'local_nucleuscommon/cpsecret_view',
                new lang_string('setting_cpsecret', 'local_nucleuscommon'),
                s((string) get_config('local_nucleuscommon', 'cpsecret') !== ''
                    ? get_string('setting_secretset', 'local_nucleuscommon')
                    : $notset)
            ));
        } else {
            $settings->add(new admin_setting_configtext(
                'local_nucleuscommon/cpbaseurl',
                new lang_string('setting_cpbaseurl', 'local_nucleuscommon'),
                new lang_string('setting_cpbaseurl_desc', 'local_nucleuscommon'),
                '',
                PARAM_URL
            ));
            $settings->add(new admin_setting_configpasswordunmask(
                'local_nucleuscommon/cpsecret',
                new lang_string('setting_cpsecret', 'local_nucleuscommon'),
                new lang_string('setting_cpsecret_desc', 'local_nucleuscommon'),
                ''
            ));
        }
        // Forced in config.php on hosted sites, so Moodle shows them read-only there.
        $settings->add(new admin_setting_configtext(
            'local_nucleuscommon/cpportalurl',
            new lang_string('setting_cpportalurl', 'local_nucleuscommon'),
            new lang_string('setting_cpportalurl_desc', 'local_nucleuscommon'),
            'https://nucleus.dklabs.co.uk',
            PARAM_URL
        ));
        $settings->add(new admin_setting_configtext(
            'local_nucleuscommon/supportcontact',
            new lang_string('setting_supportcontact', 'local_nucleuscommon'),
            new lang_string('setting_supportcontact_desc', 'local_nucleuscommon'),
            '',
            PARAM_EMAIL
        ));

        // Event transport: hosted sites take it from the environment.
        if (!$hosted) {
            $settings->add(new admin_setting_heading(
                'local_nucleuscommon/redis_heading',
                new lang_string('setting_redis_heading', 'local_nucleuscommon'),
                new lang_string('setting_redis_desc', 'local_nucleuscommon')
            ));
            $settings->add(new admin_setting_configtext(
                'local_nucleuscommon/redishost',
                new lang_string('setting_redishost', 'local_nucleuscommon'),
                new lang_string('setting_redishost_desc', 'local_nucleuscommon'),
                'redis',
                PARAM_HOST
            ));
            $settings->add(new admin_setting_configtext(
                'local_nucleuscommon/redisport',
                new lang_string('setting_redisport', 'local_nucleuscommon'),
                new lang_string('setting_redisport_desc', 'local_nucleuscommon'),
                6379,
                PARAM_INT
            ));
            $settings->add(new admin_setting_configtext(
                'local_nucleuscommon/eventstream',
                new lang_string('setting_eventstream', 'local_nucleuscommon'),
                new lang_string('setting_eventstream_desc', 'local_nucleuscommon'),
                'nucleus:events',
                PARAM_TEXT
            ));
        }
    }

    $ADMIN->add(site::ADMIN_CATEGORY, $settings);
}
