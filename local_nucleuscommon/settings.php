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
 * Admin settings for local_nucleuscommon.
 *
 * Almost every value here is auto-set during tenant provisioning
 * via setup_cp_token.php; the page exists for discovery / recovery
 * rather than first-run config.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_nucleuscommon',
        get_string('pluginname', 'local_nucleuscommon')
    );

    // Top-of-page intro. Explains that almost everything is auto-
    // configured so an admin who opens the page out of curiosity
    // doesn't start changing values they shouldn't.
    $settings->add(new admin_setting_heading(
        'local_nucleuscommon/intro',
        '',
        get_string('setting_intro_html', 'local_nucleuscommon')
    ));

    // ---- Federation identity ----
    $settings->add(new admin_setting_heading(
        'local_nucleuscommon/identity_heading',
        get_string('setting_identity_heading', 'local_nucleuscommon'),
        get_string('setting_identity_desc', 'local_nucleuscommon')
    ));

    // Mode select. The `both` option mirrors what the federation
    // settings allow control-plane side and what set_federation_mode
    // accepts as a valid value. Without it here, a tenant provisioned
    // with mode=both renders the admin page with an "Invalid current
    // value" warning even though the value is correct — the select
    // just had a stale (Mode A | Mode B) shape.
    $settings->add(new admin_setting_configselect(
        'local_nucleuscommon/federationmode',
        get_string('setting_mode', 'local_nucleuscommon'),
        get_string('setting_mode_desc', 'local_nucleuscommon'),
        'content',
        [
            'content'  => get_string('mode_content', 'local_nucleuscommon'),
            'identity' => get_string('mode_identity', 'local_nucleuscommon'),
            'both'     => get_string('mode_both', 'local_nucleuscommon'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'local_nucleuscommon/federationid',
        get_string('setting_federationid', 'local_nucleuscommon'),
        get_string('setting_federationid_desc', 'local_nucleuscommon'),
        '',
        PARAM_ALPHANUMEXT
    ));

    // ---- Control plane wiring ----
    $settings->add(new admin_setting_heading(
        'local_nucleuscommon/cp_heading',
        get_string('setting_cp_heading', 'local_nucleuscommon'),
        get_string('setting_cp_desc', 'local_nucleuscommon')
    ));

    $settings->add(new admin_setting_configtext(
        'local_nucleuscommon/cpbaseurl',
        get_string('setting_cpbaseurl', 'local_nucleuscommon'),
        get_string('setting_cpbaseurl_desc', 'local_nucleuscommon'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_nucleuscommon/cpsecret',
        get_string('setting_cpsecret', 'local_nucleuscommon'),
        get_string('setting_cpsecret_desc', 'local_nucleuscommon'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_nucleuscommon/cpportalurl',
        get_string('setting_cpportalurl', 'local_nucleuscommon'),
        get_string('setting_cpportalurl_desc', 'local_nucleuscommon'),
        '',
        PARAM_URL
    ));

    // ---- Event transport ----
    $settings->add(new admin_setting_heading(
        'local_nucleuscommon/redis_heading',
        get_string('setting_redis_heading', 'local_nucleuscommon'),
        get_string('setting_redis_desc', 'local_nucleuscommon')
    ));

    $settings->add(new admin_setting_configtext(
        'local_nucleuscommon/redishost',
        get_string('setting_redishost', 'local_nucleuscommon'),
        get_string('setting_redishost_desc', 'local_nucleuscommon'),
        'redis',
        PARAM_HOST
    ));

    $settings->add(new admin_setting_configtext(
        'local_nucleuscommon/redisport',
        get_string('setting_redisport', 'local_nucleuscommon'),
        '',
        6379,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_nucleuscommon/eventstream',
        get_string('setting_eventstream', 'local_nucleuscommon'),
        get_string('setting_eventstream_desc', 'local_nucleuscommon'),
        'nucleus:events',
        PARAM_TEXT
    ));

    $ADMIN->add('localplugins', $settings);
}
