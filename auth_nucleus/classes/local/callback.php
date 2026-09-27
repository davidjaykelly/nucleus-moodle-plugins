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

namespace auth_nucleus\local;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/authlib.php');

/**
 * What callback.php does with the hub's answer.
 *
 * Checks the state, exchanges the code with the hub server to server,
 * verifies the ID token, finds or creates the linked account and signs
 * it in. Any failure goes back to the login page with a plain message.
 *
 * A silent check ({@see silent}) goes through exactly the same checks,
 * but never shows an error: whatever stops it, the browser goes back to
 * the page the check started on, with {@see silent::MARKER}. The hub's
 * login_required, interaction_required and consent_required only mean
 * the person isn't signed in there without a click, so they aren't
 * logged; every other failure is logged as for the Log in button. The
 * person can still click Log in to see the real message.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class callback {
    /** @var string[] Hub errors that, for a silent check, just mean "not without a click". */
    public const QUIET_ERRORS = ['login_required', 'interaction_required', 'consent_required'];

    /**
     * Handle the hub's answer and sign the person in.
     *
     * @param string $state The `state` parameter.
     * @param string $code The `code` parameter.
     * @param string $error The `error` parameter.
     * @param string $iss The `iss` parameter (RFC 9207).
     * @return \moodle_url|string Where to send the browser.
     */
    public static function handle(string $state, string $code, string $error, string $iss) {
        global $SESSION;

        // The state is single use: the flow leaves the session here,
        // whatever happens next.
        $flow = preg_match(flow::STATE_PATTERN, $state) ? flow::consume($state) : null;

        // Someone already signed in here doesn't change who they are.
        if ($flow !== null && isloggedin() && !isguestuser()) {
            return flow::return_url((string) $flow->wantsurl);
        }

        // The session's flow says whether it's silent. Only when there's
        // no flow at all does the state's form decide, and then nothing
        // can be signed in anyway.
        $silent = $flow !== null ? !empty($flow->silent) : flow::is_silent_state($state);
        if ($silent) {
            silent::mark_checked();
            if ($flow === null) {
                // A browser that didn't keep the session (or a check that
                // expired). Not logged: a client without cookies would
                // fill the log.
                return silent::return_url('');
            }
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
                if ($silent && in_array($errorcode, self::QUIET_ERRORS, true)) {
                    // Not signed in to the hub, or it needs them there first.
                    return silent::return_url((string) $flow->wantsurl);
                }
                throw new signin_exception($errorcode === 'access_denied' ? 'accessdenied' : 'hubrefused',
                    AUTH_LOGIN_UNAUTHORISED, 'hub returned error ' . $errorcode . ($silent ? ' to a silent check' : ''));
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
            // phpcs:ignore
            error_log('auth_nucleus: hub sign-in refused (' . $e->reasoncode . '): ' . $e->debuginfo);

            if ($silent) {
                return silent::return_url((string) ($flow->wantsurl ?? ''));
            }
            $SESSION->loginerrormsg = get_string('error_' . $e->reasoncode, 'auth_nucleus', $e->a);
            return new \moodle_url('/login/index.php');
        }

        complete_user_login($user);
        \core\session\manager::apply_concurrent_login_limit($user->id, session_id());

        // Kept for single sign-out (id_token_hint).
        $SESSION->{flow::IDTOKENKEY} = $idtoken;
        unset($SESSION->loginerrormsg, $SESSION->logininfomsg, $SESSION->loginredirect);
        // Signed in with the hub again, so silent sign-in is back on in
        // this browser (for when this session ends).
        silent::forget_signed_out();

        return flow::return_url((string) $flow->wantsurl);
    }
}
