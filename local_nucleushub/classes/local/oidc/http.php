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

namespace local_nucleushub\local\oidc;

/**
 * What the endpoint files need to know about the HTTP request.
 *
 * Kept apart from {@see provider}, which takes these values as
 * arguments, so the protocol logic can be tested without a web server.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class http {
    /**
     * The request method, upper case.
     *
     * @return string
     */
    public static function method(): string {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
    }

    /**
     * The request's media type, lower case, without parameters.
     *
     * @return string For example 'application/x-www-form-urlencoded', or ''.
     */
    public static function content_type(): string {
        $type = (string) ($_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        return strtolower(trim(explode(';', $type, 2)[0]));
    }

    /**
     * The Authorization header, however the web server passed it on.
     *
     * Apache with mod_php hands PHP the header through
     * `getallheaders()` but not `$_SERVER`, and decodes Basic
     * credentials into `PHP_AUTH_USER`/`PHP_AUTH_PW`; PHP-FPM setups
     * usually pass `HTTP_AUTHORIZATION`. All are checked.
     *
     * @return string|null The header value, or null if there is none.
     */
    public static function authorization(): ?string {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $name) {
            if (isset($_SERVER[$name]) && is_string($_SERVER[$name]) && trim($_SERVER[$name]) !== '') {
                return trim($_SERVER[$name]);
            }
        }
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strcasecmp((string) $name, 'Authorization') === 0 && is_string($value) && trim($value) !== '') {
                        return trim($value);
                    }
                }
            }
        }
        if (isset($_SERVER['PHP_AUTH_USER']) && is_string($_SERVER['PHP_AUTH_USER'])) {
            $password = isset($_SERVER['PHP_AUTH_PW']) && is_string($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '';
            return 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . $password);
        }
        return null;
    }
}
