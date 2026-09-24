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
 * The hub sends the browser back here with a code (or an error).
 *
 * Checks the state, exchanges the code with the hub server to server,
 * verifies the ID token, finds or creates the linked account and signs
 * it in. Any failure goes back to the login page with a plain message.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- this page signs people in.
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/authlib.php');

use auth_nucleus\local\accounts;
use auth_nucleus\local\config;
use auth_nucleus\local\flow;
use auth_nucleus\local\hub;
use auth_nucleus\local\id_token;
use auth_nucleus\local\jwks;
use auth_nucleus\local\signin_exception;

// Raw, then checked against strict patterns below.
$state = optional_param('state', '', PARAM_RAW_TRIMMED);
$code = optional_param('code', '', PARAM_RAW_TRIMMED);
$error = optional_param('error', '', PARAM_RAW_TRIMMED);
$iss = optional_param('iss', '', PARAM_RAW_TRIMMED);

$PAGE->set_url(new moodle_url('/auth/nucleus/callback.php'));
$PAGE->set_context(context_system::instance());

// The state is single use: the flow leaves the session here, whatever
// happens next.
$flow = preg_match('/^[A-Za-z0-9_-]{16,128}$/', $state) ? flow::consume($state) : null;

// Someone already signed in here doesn't change who they are.
if ($flow !== null && isloggedin() && !isguestuser()) {
    redirect(flow::return_url((string) $flow->wantsurl));
}

$username = 'unknown';
try {
    if ($flow === null) {
        throw new signin_exception('state', AUTH_LOGIN_FAILED, 'state missing, unknown, expired or already used');
    }
    if (!config::is_enabled() || !config::is_configured()) {
        throw new signin_exception('notenabled', AUTH_LOGIN_FAILED, 'hub sign-in is off or not configured');
    }
    // RFC 9207: when the hub names itself, it must be our issuer.
    if ($iss !== '' && $iss !== config::issuer()) {
        throw new signin_exception('hubrefused', AUTH_LOGIN_FAILED, 'iss parameter is not the configured issuer');
    }
    if ($error !== '') {
        $errorcode = clean_param($error, PARAM_ALPHANUMEXT);
        throw new signin_exception($errorcode === 'access_denied' ? 'accessdenied' : 'hubrefused',
            AUTH_LOGIN_UNAUTHORISED, 'hub returned error ' . $errorcode);
    }
    if (!preg_match('/^[A-Za-z0-9._~-]{16,512}$/', $code)) {
        throw new signin_exception('hubrefused', AUTH_LOGIN_FAILED, 'code missing or malformed');
    }

    $idtoken = hub::exchange_code($code, (string) $flow->verifier);
    $verifier = new id_token(config::issuer(), config::clientid(), new jwks(config::issuer()));
    $claims = $verifier->verify($idtoken, (string) $flow->nonce);
    $username = accounts::username_for((string) $claims->sub);
    $user = accounts::sign_in($claims);
} catch (\Throwable $e) {
    if (!$e instanceof signin_exception) {
        // Anything unexpected is refused too. Only its class is logged:
        // messages from lower layers can carry request data.
        $e = new signin_exception('generic', AUTH_LOGIN_FAILED, 'unexpected ' . get_class($e));
    }
    $other = ['username' => $username, 'reason' => $e->loginreason];
    $eventdata = ['other' => $other];
    if ($e->userid) {
        $eventdata['userid'] = $e->userid;
    }
    \core\event\user_login_failed::create($eventdata)->trigger();
    // For the site's logs: the reason only, never tokens or secrets.
    error_log('auth_nucleus: hub sign-in refused (' . $e->reasoncode . '): ' . $e->debuginfo);

    $SESSION->loginerrormsg = get_string('error_' . $e->reasoncode, 'auth_nucleus', $e->a);
    redirect(new moodle_url('/login/index.php'));
}

complete_user_login($user);
\core\session\manager::apply_concurrent_login_limit($user->id, session_id());

// Kept for single sign-out (id_token_hint).
$SESSION->{flow::IDTOKENKEY} = $idtoken;
unset($SESSION->loginerrormsg, $SESSION->logininfomsg, $SESSION->loginredirect);

redirect(flow::return_url((string) $flow->wantsurl));
