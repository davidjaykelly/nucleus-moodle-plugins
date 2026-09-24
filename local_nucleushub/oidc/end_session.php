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
 * End session endpoint for sign in with the hub (ADR-023).
 *
 * A spoke sends the browser here after its own sign-out. The hub
 * session ends, then the browser goes back to the spoke, but only to a
 * registered post-logout URI (with `state`). With no usable URI, a
 * "You're signed out" page is shown instead.
 *
 * The session ends at once when the `id_token_hint` is a valid ID token
 * for the person signed in. Otherwise they are asked first, so a link
 * on another site can't sign people out of the hub.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_nucleushub\local\oidc\provider;

require(__DIR__ . '/../../../config.php');

// Set again below with the checked request once it's known.
$PAGE->set_url(new moodle_url('/local/nucleushub/oidc/end_session.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('login');

$signedout = optional_param('signedout', 0, PARAM_BOOL);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$sesskey = optional_param('sesskey', '', PARAM_RAW);

if ($signedout && (!isloggedin() || isguestuser())) {
    $PAGE->set_url(new moodle_url('/local/nucleushub/oidc/end_session.php', ['signedout' => 1]));
    $PAGE->set_title(get_string('oidc_signedout_title', 'local_nucleushub'));
    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        get_string('oidc_signedout', 'local_nucleushub', format_string($SITE->fullname)),
        \core\output\notification::NOTIFY_SUCCESS,
        false
    );
    echo $OUTPUT->continue_button(new moodle_url('/'));
    echo $OUTPUT->footer();
    exit;
}

// The confirmation's Continue button posts its parameters, so read both.
$decision = provider::end_session(
    $_POST + $_GET,
    $USER,
    \core\session\manager::is_loggedinas(),
    $confirm && $sesskey !== '' && confirm_sesskey($sesskey)
);

if ($decision['confirm']) {
    // Anything that reloads $PAGE->url (the language menu, for one) must
    // keep the request, or the way back to the spoke would be lost. Only
    // the checked parameters go in, never the sesskey.
    $PAGE->set_url(new moodle_url('/local/nucleushub/oidc/end_session.php', $decision['params']));
    $continue = new moodle_url(
        '/local/nucleushub/oidc/end_session.php',
        $decision['params'] + ['confirm' => 1, 'sesskey' => sesskey()]
    );
    $cancel = $decision['redirect'] ?? new moodle_url('/');
    $PAGE->set_title(get_string('oidc_signout_title', 'local_nucleushub'));
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('oidc_signout_confirm', 'local_nucleushub', format_string($SITE->fullname)),
        $continue,
        $cancel
    );
    echo $OUTPUT->footer();
    exit;
}

if ($decision['logout']) {
    require_logout();
}
if ($decision['redirect']) {
    redirect($decision['redirect']);
}
redirect(new moodle_url('/local/nucleushub/oidc/end_session.php', ['signedout' => 1]));
