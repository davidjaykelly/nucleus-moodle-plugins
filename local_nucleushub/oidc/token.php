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
 * Token endpoint for sign in with the hub (ADR-023).
 *
 * POST only, form-encoded, client_secret_basic or client_secret_post,
 * grant_type=authorization_code with PKCE. Called server to server by
 * spokes, so it never uses cookies, and it reads the form body only,
 * never the query string. Every response is JSON and not cacheable.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_DEBUG_DISPLAY', true);
define('NO_MOODLE_COOKIES', true);

require(__DIR__ . '/../../../config.php');

try {
    $response = \local_nucleushub\local\oidc\provider::token(
        \local_nucleushub\local\oidc\http::method(),
        \local_nucleushub\local\oidc\http::content_type(),
        $_POST,
        \local_nucleushub\local\oidc\http::authorization()
    );
} catch (\Throwable $e) {
    \local_nucleushub\local\oidc\provider::log_failure('token', $e);
    $response = \local_nucleushub\local\oidc\response::error(
        500,
        'server_error',
        '',
        \local_nucleushub\local\oidc\provider::NO_STORE
    );
}
$response->send();
