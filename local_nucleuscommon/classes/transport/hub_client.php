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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * HTTP client for calling hub external functions from a spoke.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleuscommon\transport;

use local_nucleuscommon\auth\token;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Thin wrapper around Moodle's curl class that speaks the REST flavour of
 * Moodle's webservice endpoint.
 *
 * Lives in local_nucleuscommon so it can be reused by either side of the
 * federation; in Phase 0 only the spoke actually calls the hub.
 */
class hub_client {

    /** @var string Base wwwroot of the hub (used for Host header). */
    private string $wwwroot;

    /**
     * @var string TCP connect URL — defaults to $wwwroot, but can be
     *             a cluster-internal address (e.g. a k8s Service DNS
     *             name) when the browser-facing wwwroot isn't
     *             reachable from where this spoke is running.
     */
    private string $connecturl;

    /** @var string Federation token for this client. */
    private string $token;

    /** @var int Per-request timeout (seconds). */
    private int $timeout;

    /** @var int Max retry attempts on transient failure. */
    private int $maxretries;

    /**
     * @param string $wwwroot Hub wwwroot — the URL the hub believes it is.
     *                        Used to build the Host header, so it must
     *                        match `$CFG->wwwroot` on the hub exactly.
     * @param string $token Bearer-equivalent wstoken.
     * @param int $timeout Per-request timeout in seconds.
     * @param int $maxretries Retry count for transport errors (not HTTP errors).
     * @param string|null $connecturl Optional TCP connect URL — when set,
     *                        HTTPS/TCP goes here but the Host header still
     *                        reflects $wwwroot. Lets us route cluster-
     *                        internal traffic via service DNS without
     *                        tripping Moodle's wwwroot-mismatch 303
     *                        (phase-0 findings gotcha 11, phase-1 re-hit
     *                        when browser URL differs from cluster DNS).
     */
    public function __construct(string $wwwroot, string $token, int $timeout = 30,
            int $maxretries = 2, ?string $connecturl = null) {
        $this->wwwroot = rtrim($wwwroot, '/');
        $this->connecturl = rtrim($connecturl ?? $wwwroot, '/');
        $this->token = $token;
        $this->timeout = $timeout;
        $this->maxretries = $maxretries;
    }

    /**
     * Invoke a hub external function and return the decoded response.
     *
     * @param string $function External function name (e.g. 'local_nucleushub_list_courses').
     * @param array $params Parameter array matching the function's signature.
     * @return array|string|int|float|bool|null Decoded JSON response body.
     * @throws \moodle_exception If the hub returns an error envelope or is unreachable after retries.
     */
    public function call(string $function, array $params = []) {
        // The URL we actually connect to — may be the wwwroot itself
        // (the typical case) or a separate cluster-internal address.
        $url = token::with_wstoken(
            $this->connecturl . '/webservice/rest/server.php',
            $this->token
        );
        $url .= '&moodlewsrestformat=json&wsfunction=' . rawurlencode($function);

        // The Host header value. Parsed from wwwroot so the remote end
        // sees exactly what it expects and skips the mismatch-redirect.
        $wwwrootparts = parse_url($this->wwwroot);
        $hostheader = $wwwrootparts['host'] ?? '';
        if (isset($wwwrootparts['port'])) {
            $hostheader .= ':' . $wwwrootparts['port'];
        }

        $attempt = 0;
        $lasterror = null;
        do {
            // ignoresecurity=true: hubwwwroot is an admin-configured internal
            // address, often a private docker/k8s IP, which Moodle's curl
            // helper blocks by default. Federation traffic is never
            // user-controlled so it's safe to bypass here.
            $curl = new \curl(['ignoresecurity' => true]);
            $curl->setopt([
                'CURLOPT_TIMEOUT' => $this->timeout,
                'CURLOPT_CONNECTTIMEOUT' => min(10, $this->timeout),
                'CURLOPT_FOLLOWLOCATION' => false,
            ]);
            if ($hostheader !== '' && $this->connecturl !== $this->wwwroot) {
                $curl->setHeader(['Host: ' . $hostheader]);
            }
            $response = $curl->post($url, $params);
            $info = $curl->get_info();
            $errno = $curl->get_errno();

            if ($errno === 0 && isset($info['http_code']) && $info['http_code'] >= 200 && $info['http_code'] < 300) {
                return self::decode($response, $function);
            }

            $lasterror = sprintf(
                'hub call %s failed: http=%s, curl_errno=%d, body=%s',
                $function,
                $info['http_code'] ?? 'n/a',
                $errno,
                substr((string)$response, 0, 500)
            );
            $attempt++;
        } while ($attempt <= $this->maxretries);

        throw new \moodle_exception('huberror', 'local_nucleuscommon', '', $lasterror, $lasterror);
    }

    /**
     * Download a binary resource from the hub to a local file.
     *
     * Mirrors `call()` for path/Host handling — TCP goes to
     * $connecturl, Host header matches $wwwroot — but hits a non-WS
     * endpoint and writes the body to disk.
     *
     * @param string $path URL path (e.g. `/local/nucleushub/download.php`).
     * @param array $params Extra query parameters (excluding wstoken).
     * @param string $localpath Destination filesystem path.
     * @return bool True on HTTP 2xx, false otherwise. The file at $localpath is truncated either way.
     * @throws \moodle_exception On transport-level failure after retries.
     */
    public function download_to_file(string $path, array $params, string $localpath): bool {
        $url = $this->connecturl . $path;
        $query = ['wstoken' => $this->token] + $params;
        // Force `&` as the separator. Moodle's PHP config sets
        // arg_separator.output to `&amp;` (right for HTML contexts,
        // wrong for URLs we build ourselves). Without this override the
        // remote endpoint sees one malformed parameter like
        // "wstoken=X&amp;file=Y" and misses our `file` param entirely.
        $url .= '?' . http_build_query($query, '', '&');

        $wwwrootparts = parse_url($this->wwwroot);
        $hostheader = $wwwrootparts['host'] ?? '';
        if (isset($wwwrootparts['port'])) {
            $hostheader .= ':' . $wwwrootparts['port'];
        }

        $attempt = 0;
        $lasterror = null;
        do {
            $curl = new \curl(['ignoresecurity' => true]);
            $curl->setopt([
                'CURLOPT_TIMEOUT' => max($this->timeout, 300),  // larger window for file downloads
                'CURLOPT_CONNECTTIMEOUT' => min(10, $this->timeout),
                'CURLOPT_FOLLOWLOCATION' => false,
            ]);
            if ($hostheader !== '' && $this->connecturl !== $this->wwwroot) {
                $curl->setHeader(['Host: ' . $hostheader]);
            }

            $response = $curl->get($url);
            $info = $curl->get_info();
            $errno = $curl->get_errno();
            $httpcode = $info['http_code'] ?? 0;

            if ($errno === 0 && $httpcode >= 200 && $httpcode < 300) {
                if (file_put_contents($localpath, $response) === false) {
                    throw new \moodle_exception('huberror', 'local_nucleuscommon', '', "could not write download to {$localpath}",
                        "could not write download to {$localpath}");
                }
                return true;
            }

            $lasterror = sprintf(
                'hub download %s failed: http=%s curl_errno=%d body=%s',
                $path, $httpcode, $errno, substr((string)$response, 0, 200)
            );
            $attempt++;
        } while ($attempt <= $this->maxretries);

        throw new \moodle_exception('huberror', 'local_nucleuscommon', '', $lasterror, $lasterror);
    }

    /**
     * Decode a REST-JSON response from Moodle's webservice endpoint.
     *
     * Moodle's JSON WS server returns a bare JSON value on success, or an
     * envelope with an `exception` key on error.
     *
     * @param string $response Raw response body.
     * @param string $function Function name, for error messages.
     * @return array|string|int|float|bool|null
     * @throws \moodle_exception If the envelope indicates a Moodle error.
     */
    private static function decode(string $response, string $function) {
        $decoded = json_decode($response, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            $msg = "hub call {$function} returned non-JSON: " . substr($response, 0, 500);
            throw new \moodle_exception('huberror', 'local_nucleuscommon', '', $msg, $msg);
        }
        if (is_array($decoded) && isset($decoded['exception'])) {
            $msg = $decoded['message'] ?? $decoded['exception'];
            throw new \moodle_exception('huberror', 'local_nucleuscommon', '', "hub call {$function} raised {$decoded['exception']}: {$msg}",
                "hub call {$function} raised {$decoded['exception']}: {$msg}");
        }
        return $decoded;
    }
}
