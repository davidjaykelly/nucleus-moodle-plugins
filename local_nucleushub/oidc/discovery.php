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
 * OpenID Connect discovery document for sign in with the hub (ADR-023).
 *
 * Called server to server by spokes, often through a cluster-internal
 * address with a Host header, so it never uses cookies. The issuer and
 * endpoint URLs always come from $CFG->wwwroot, never from the request.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_DEBUG_DISPLAY', true);
define('NO_MOODLE_COOKIES', true);

require(__DIR__ . '/../../../config.php');

try {
    $response = new \local_nucleushub\local\oidc\response(
        200,
        \local_nucleushub\local\oidc\provider::discovery(),
        ['Cache-Control' => 'public, max-age=300']
    );
} catch (\Throwable $e) {
    \local_nucleushub\local\oidc\provider::log_failure('discovery', $e);
    $response = \local_nucleushub\local\oidc\response::error(500, 'server_error');
}
$response->send();
