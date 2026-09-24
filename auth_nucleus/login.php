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
 * Start signing in with the hub: send the browser to the hub's authorize page.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- this page signs people in.
require_once(__DIR__ . '/../../config.php');

use auth_nucleus\local\config;
use auth_nucleus\local\flow;
use auth_nucleus\local\hub;

$wantsurl = optional_param('wantsurl', '', PARAM_LOCALURL);

$PAGE->set_url(new moodle_url('/auth/nucleus/login.php'));
$PAGE->set_context(context_system::instance());

// Already signed in: nothing to do.
if (isloggedin() && !isguestuser()) {
    redirect(flow::return_url($wantsurl));
}

if (!config::is_enabled() || !config::is_configured() || !hub::issuer_is_on_hub()) {
    $SESSION->loginerrormsg = get_string('error_notenabled', 'auth_nucleus');
    redirect(new moodle_url('/login/index.php'));
}

if ($wantsurl === '' && !empty($SESSION->wantsurl)) {
    $wantsurl = (string) $SESSION->wantsurl;
}

$flow = flow::start($wantsurl);
redirect(hub::authorize_url($flow));
