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
 * Settings for auth_nucleus.
 *
 * Nucleus sets these when the federation turns on sign-in with the hub.
 * On sites DK Labs hosts they're shown but can't be changed here. The
 * client secret is never shown, only whether it's set.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use auth_nucleus\local\config;
use local_nucleuscommon\local\site;

if ($ADMIN->fulltree) {
    $hosted = class_exists(site::class) && site::is_hosted();

    $settings->add(new admin_setting_heading('auth_nucleus/intro', '', html_writer::div(
        s(get_string($hosted ? 'settings_intro_hosted' : 'settings_intro', 'auth_nucleus')),
        'alert alert-info'
    )));

    if ($hosted) {
        // Shown, not saved from here: Nucleus sets these.
        $notset = get_string('setting_notset', 'local_nucleuscommon');
        $text = function (string $value) use ($notset): string {
            return s($value !== '' ? $value : $notset);
        };
        $yesno = function (bool $value): string {
            return get_string($value ? 'yes' : 'no');
        };

        $settings->add(new admin_setting_description('auth_nucleus/issuer_view',
            new lang_string('setting_issuer', 'auth_nucleus'), $text(config::issuer())));
        $settings->add(new admin_setting_description('auth_nucleus/clientid_view',
            new lang_string('setting_clientid', 'auth_nucleus'), $text(config::clientid())));
        $settings->add(new admin_setting_description('auth_nucleus/clientsecret_view',
            new lang_string('setting_clientsecret', 'auth_nucleus'),
            s(config::has_clientsecret() ? get_string('setting_secretset', 'local_nucleuscommon') : $notset)));
        $settings->add(new admin_setting_description('auth_nucleus/hubname_view',
            new lang_string('setting_hubname', 'auth_nucleus'),
            $text(trim((string) get_config('auth_nucleus', 'hubname')))));
        $settings->add(new admin_setting_description('auth_nucleus/autoredirect_view',
            new lang_string('setting_autoredirect', 'auth_nucleus'), $yesno(config::autoredirect())));
        $settings->add(new admin_setting_description('auth_nucleus/singlesignout_view',
            new lang_string('setting_singlesignout', 'auth_nucleus'), $yesno(config::singlesignout())));
    } else {
        $settings->add(new admin_setting_configtext('auth_nucleus/issuer',
            new lang_string('setting_issuer', 'auth_nucleus'),
            new lang_string('setting_issuer_desc', 'auth_nucleus'), '', PARAM_URL));
        $settings->add(new admin_setting_configtext('auth_nucleus/clientid',
            new lang_string('setting_clientid', 'auth_nucleus'),
            new lang_string('setting_clientid_desc', 'auth_nucleus'), '', PARAM_RAW_TRIMMED));
        // Stored with \core\encryption; the page only says whether it's set.
        $settings->add(new admin_setting_encryptedpassword('auth_nucleus/clientsecret',
            get_string('setting_clientsecret', 'auth_nucleus'),
            get_string('setting_clientsecret_desc', 'auth_nucleus')));
        $settings->add(new admin_setting_configtext('auth_nucleus/hubname',
            new lang_string('setting_hubname', 'auth_nucleus'),
            new lang_string('setting_hubname_desc', 'auth_nucleus'), '', PARAM_TEXT));
        $settings->add(new admin_setting_configcheckbox('auth_nucleus/autoredirect',
            new lang_string('setting_autoredirect', 'auth_nucleus'),
            new lang_string('setting_autoredirect_desc', 'auth_nucleus'), 1));
        $settings->add(new admin_setting_configcheckbox('auth_nucleus/singlesignout',
            new lang_string('setting_singlesignout', 'auth_nucleus'),
            new lang_string('setting_singlesignout_desc', 'auth_nucleus'), 1));
    }

    $settings->add(new admin_setting_heading('auth_nucleus/locked', new lang_string('setting_locked', 'auth_nucleus'),
        new lang_string('setting_locked_desc', 'auth_nucleus')));
}
