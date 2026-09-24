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
 * A JSON response from one of the server-to-server endpoints.
 *
 * The endpoint files build one of these through {@see provider} and
 * send it. Keeping the status, body and headers in an object lets the
 * tests check them without a web server.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class response {
    /** @var int HTTP status code. */
    public readonly int $status;

    /** @var array Encoded as a JSON object. */
    public readonly array $body;

    /** @var array Header name => value, sent as well as the content type. */
    public readonly array $headers;

    /**
     * Constructor.
     *
     * @param int $status HTTP status code.
     * @param array $body Encoded as a JSON object.
     * @param array $headers Header name => value, sent as well as the content type.
     */
    public function __construct(int $status, array $body, array $headers = []) {
        $this->status = $status;
        $this->body = $body;
        $this->headers = $headers;
    }

    /**
     * An error response in the RFC 6749 section 5.2 shape.
     *
     * The description is a fixed sentence from this plugin's code, never
     * an exception message, so nothing internal leaks.
     *
     * @param int $status 400, 401 or 500.
     * @param string $error OAuth error code.
     * @param string $description Plain, fixed description.
     * @param array $headers Extra headers.
     * @return self
     */
    public static function error(int $status, string $error, string $description = '', array $headers = []): self {
        $body = ['error' => $error];
        if ($description !== '') {
            $body['error_description'] = $description;
        }
        return new self($status, $body, $headers);
    }

    /**
     * Send the response and stop.
     *
     * @return void
     */
    public function send(): void {
        if (!headers_sent()) {
            http_response_code($this->status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo json_encode((object) $this->body, JSON_UNESCAPED_SLASHES);
    }
}
