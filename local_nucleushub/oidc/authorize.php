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
 * Authorisation endpoint for sign in with the hub (ADR-023).
 *
 * The spoke sends the browser here. In order:
 * 1. The client and its exact registered redirect URI are checked. If
 *    either is wrong, an error page is shown and nothing is sent to the
 *    redirect URI.
 * 2. Any other bad parameter goes back to the redirect URI as an error.
 * 3. The person signs in to the hub if they haven't (Moodle's own login,
 *    which comes back here).
 * 4. Accounts that may not sign in to spokes get access_denied; anyone
 *    else goes back with a single-use code.
 *
 * With `prompt=none` (a spoke's silent sign-in check), steps 1 and 2 are
 * the same, but nothing is ever shown: step 3 is skipped and anyone who
 * isn't signed in, or would be asked for something, goes back at once
 * with an error such as login_required (see
 * provider::authorize_prompt_none()). require_login() is never called on
 * that path, so it can't send the browser to the login page or remember
 * this request as where to go after signing in. A session that hasn't
 * finished multi-factor authentication is answered even earlier, before
 * tool_mfa can show its page (hook_callbacks::after_config()).
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_nucleushub\local\oidc\provider;

require(__DIR__ . '/../../../config.php');

// require_login() sends people back to $PAGE->url once they've signed in
// to the hub, so the page URL must carry the whole request; without it
// they'd come back to a bare authorize.php and be refused. `prompt` isn't
// carried: a prompt=none request never goes to the login page.
$requestparams = [];
foreach (['client_id', 'redirect_uri', 'response_type', 'scope', 'state', 'nonce',
        'code_challenge', 'code_challenge_method', 'response_mode'] as $name) {
    $value = provider::param($_GET, $name);
    if ($value !== null) {
        $requestparams[$name] = $value;
    }
}
$PAGE->set_url(new moodle_url('/local/nucleushub/oidc/authorize.php', $requestparams));
$PAGE->set_context(context_system::instance());

// 1. Throws, so Moodle shows its error page, if the client or redirect URI is wrong.
$request = provider::parse_authorize_request($_GET);

// 2. The rest of the request's problems go back to the client.
if ($request->error !== null) {
    redirect(provider::authorize_error_url($request));
}

// Silent sign-in: a code or an error, never a page.
if ($request->promptnone) {
    try {
        $url = provider::authorize_prompt_none($request, $USER, \core\session\manager::is_loggedinas());
    } catch (\Throwable $e) {
        provider::log_failure('authorize', $e);
        $url = provider::authorize_error_url($request, 'server_error', 'The hub couldn\'t complete the sign-in.');
    }
    @header('Cache-Control: no-store');
    redirect($url);
}

// 3. Moodle's login (and any organisation provider) returns here via wantsurl.
require_login(null, false);

// 4. Checked against a fresh copy of the account, not the session.
try {
    $user = provider::load_user((int) $USER->id) ?? (object) ['id' => 0];
    $url = provider::complete_authorize(
        $request,
        $user,
        (int) ($USER->currentlogin ?? 0),
        \core\session\manager::is_loggedinas()
    );
} catch (\Throwable $e) {
    provider::log_failure('authorize', $e);
    $url = provider::authorize_error_url($request, 'server_error', 'The hub couldn\'t complete the sign-in.');
}

@header('Cache-Control: no-store');
redirect($url);
