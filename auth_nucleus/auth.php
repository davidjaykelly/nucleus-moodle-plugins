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
 * Authentication plugin: sign in with the federation's hub.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/authlib.php');

use auth_nucleus\local\config;
use auth_nucleus\local\flow;
use auth_nucleus\local\hub;

/**
 * Sign in with the federation's hub (ADR-023).
 *
 * An OpenID Connect client for the hub's provider. Accounts using it
 * (auth=nucleus) have no local password: they sign in through the hub,
 * starting at /auth/nucleus/login.php. Local accounts, including every
 * site admin, keep working at /login/index.php?local=1.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class auth_plugin_nucleus extends auth_plugin_base {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->authtype = config::AUTH;
        $this->config = get_config(config::COMPONENT);
    }

    /**
     * Passwords never work for hub accounts.
     *
     * @param string $username
     * @param string $password
     * @return bool Always false.
     */
    public function user_login($username, $password) {
        return false;
    }

    /**
     * Hub accounts change their password on the hub.
     *
     * @return bool
     */
    public function can_change_password() {
        return false;
    }

    /**
     * Hub accounts reset their password on the hub.
     *
     * @return bool
     */
    public function can_reset_password() {
        return false;
    }

    /**
     * Not an internal (password) plugin.
     *
     * @return bool
     */
    public function is_internal() {
        return false;
    }

    /**
     * Never store a password hash for hub accounts.
     *
     * @return bool
     */
    public function prevent_local_passwords() {
        return true;
    }

    /**
     * Is there enough configuration to sign in with the hub?
     *
     * @return bool
     */
    public function is_configured() {
        return config::is_configured();
    }

    /**
     * The "Sign in with {hub name}" button, with a "Use a local account"
     * link under it.
     *
     * @param string $wantsurl Where the person was going.
     * @return array
     */
    public function loginpage_idp_list($wantsurl) {
        if (!config::is_configured()) {
            return [];
        }
        $params = [];
        $local = flow::local_url((string) $wantsurl);
        if ($local !== null) {
            $params['wantsurl'] = $local->out(false);
        }
        return [
            [
                'url' => new moodle_url('/auth/nucleus/login.php', $params),
                'name' => get_string('signinwith', 'auth_nucleus', config::hubname()),
                'iconurl' => '',
            ],
            [
                'url' => new moodle_url('/login/index.php', ['local' => 1]),
                'name' => get_string('uselocalaccount', 'auth_nucleus'),
                'iconurl' => '',
            ],
        ];
    }

    /**
     * Send the login page straight to the hub, when that's switched on.
     *
     * Not when ?local=1 asks for the local form (which then shows a
     * note), a form was posted, there's a login error to show, or the
     * person is already signed in.
     */
    public function loginpage_hook() {
        global $SESSION;

        if (!config::is_configured()) {
            return;
        }

        if (optional_param('local', 0, PARAM_BOOL)) {
            // login/index.php shows and clears this after the hooks run.
            if (empty($SESSION->logininfomsg) && !data_submitted()) {
                $SESSION->logininfomsg = get_string('localaccountnote', 'auth_nucleus', config::hubname());
            }
            return;
        }

        if (
            !config::autoredirect()
            || data_submitted()
            || !empty($SESSION->loginerrormsg)
            || optional_param('errorcode', 0, PARAM_INT)
            || optional_param('testsession', 0, PARAM_INT)
            || (isloggedin() && !isguestuser())
        ) {
            return;
        }

        $params = [];
        $local = flow::local_url((string) ($SESSION->wantsurl ?? ''));
        if ($local !== null) {
            $params['wantsurl'] = $local->out(false);
        }
        redirect(new moodle_url('/auth/nucleus/login.php', $params));
    }

    /**
     * After signing out here, sign out of the hub too (single sign-out).
     *
     * Only for hub accounts. login/logout.php signs out locally first,
     * then redirects to $redirect.
     */
    public function logoutpage_hook() {
        global $USER, $SESSION, $redirect;

        if (!isloggedin() || ($USER->auth ?? '') !== config::AUTH) {
            return;
        }
        if (!config::singlesignout() || !config::is_configured()) {
            return;
        }
        $idtoken = $SESSION->{flow::IDTOKENKEY} ?? null;
        $redirect = hub::end_session_url(is_string($idtoken) ? $idtoken : null)->out(false);
    }

    /**
     * The email sent when a hub account asks to reset its password here.
     *
     * @param stdClass $user
     * @return string[] ['subject' => string, 'message' => string]
     */
    public function get_password_change_info(stdClass $user): array {
        $site = get_site();
        $data = (object) [
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'username' => $user->username,
            'sitename' => format_string($site->fullname),
            'hubname' => config::hubname(),
            'admin' => generate_email_signoff(),
        ];
        return [
            'subject' => get_string('emailpasswordchangeinfosubject', 'auth_nucleus', format_string($site->fullname)),
            'message' => get_string('emailpasswordchangeinfo', 'auth_nucleus', $data),
        ];
    }
}
