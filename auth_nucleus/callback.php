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
 * it in. Any failure goes back to the login page with a plain message,
 * except for a silent sign-in check, which goes quietly back to the page
 * it started on. See \auth_nucleus\local\callback.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- this page signs people in.
require_once(__DIR__ . '/../../config.php');

// Raw, then checked against strict patterns.
$state = optional_param('state', '', PARAM_RAW_TRIMMED);
$code = optional_param('code', '', PARAM_RAW_TRIMMED);
$error = optional_param('error', '', PARAM_RAW_TRIMMED);
$iss = optional_param('iss', '', PARAM_RAW_TRIMMED);

$PAGE->set_url(new moodle_url('/auth/nucleus/callback.php'));
$PAGE->set_context(context_system::instance());

redirect(\auth_nucleus\local\callback::handle($state, $code, $error, $iss));
