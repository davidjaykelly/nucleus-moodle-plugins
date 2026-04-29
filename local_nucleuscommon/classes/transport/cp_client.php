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
 * HTTP client for calling the Nucleus control plane from a Moodle
 * instance (hub or spoke side). Authenticates with the shared
 * federation-node secret per ADR-014.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

namespace local_nucleuscommon\transport;

defined('MOODLE_INTERNAL') || die();

/**
 * Exception raised by cp_client for any non-2xx HTTP response or
 * transport-level failure.
 */
class cp_client_exception extends \moodle_exception {

    /** @var int HTTP status returned by the remote, 0 if transport failed. */
    public int $httpstatus;

    /** @var string Raw response body, if any. */
    public string $body;

    /**
     * @param string $message Human-readable error message.
     * @param int $httpstatus HTTP status (0 for transport-level errors).
     * @param string $body Response body if any.
     */
    public function __construct(string $message, int $httpstatus = 0, string $body = '') {
        parent::__construct('cperror', 'local_nucleuscommon', '', $message);
        $this->httpstatus = $httpstatus;
        $this->body = $body;
    }
}

/**
 * Thin Moodle-to-CP client. Reads the base URL and node secret from
 * plugin config. All calls send the secret as a Bearer token.
 */
class cp_client {

    /** @var string Base URL, no trailing slash. */
    private string $baseurl;

    /** @var string Federation-node shared secret. */
    private string $secret;

    /** @var int Per-request timeout in seconds. Big enough for snapshot uploads. */
    private int $timeout;

    /**
     * Build a client from plugin config. Throws if the required
     * settings are unset so callers fail early with a clear message.
     *
     * @return cp_client
     * @throws \moodle_exception
     */
    public static function from_config(): cp_client {
        $baseurl = (string) get_config('local_nucleuscommon', 'cpbaseurl');
        $secret = (string) get_config('local_nucleuscommon', 'cpsecret');
        if ($baseurl === '' || $secret === '') {
            throw new \moodle_exception(
                'cpnotconfigured',
                'local_nucleuscommon',
                '',
                'Set local_nucleuscommon/cpbaseurl and /cpsecret before calling the control plane.'
            );
        }
        return new self($baseurl, $secret);
    }

    /**
     * @param string $baseurl CP base URL, no trailing slash.
     * @param string $secret Federation-node bearer.
     * @param int $timeout Per-request timeout seconds.
     */
    public function __construct(string $baseurl, string $secret, int $timeout = 600) {
        $this->baseurl = rtrim($baseurl, '/');
        $this->secret = $secret;
        $this->timeout = $timeout;
    }

    /**
     * Upload a file as the raw body of a POST. Used for .mbz
     * snapshot uploads to `/course-versions/:guid/snapshot`.
     *
     * @param string $path URL path, e.g. "/course-versions/{guid}/snapshot".
     * @param string $filepath Local path to the file to upload.
     * @return array Decoded JSON response.
     * @throws cp_client_exception
     */
    public function post_file(string $path, string $filepath): array {
        if (!is_readable($filepath)) {
            throw new cp_client_exception("local file not readable: {$filepath}");
        }
        $size = filesize($filepath);
        if ($size === false) {
            throw new cp_client_exception("could not stat file: {$filepath}");
        }
        $handle = fopen($filepath, 'rb');
        if ($handle === false) {
            throw new cp_client_exception("could not open file: {$filepath}");
        }

        $url = $this->baseurl . $path;
        $ch = curl_init($url);
        $responsebody = '';
        try {
            // CURLOPT_UPLOAD makes curl stream from CURLOPT_INFILE but
            // also defaults the method to PUT; we force POST so the CP
            // route matches. CURLOPT_POST alone can't be used because
            // it wants CURLOPT_POSTFIELDS, which would buffer the whole
            // .mbz in memory.
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_UPLOAD         => true,
                CURLOPT_INFILE         => $handle,
                CURLOPT_INFILESIZE     => $size,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $this->secret,
                    'Content-Type: application/octet-stream',
                    'Expect:',
                ],
            ]);
            $response = curl_exec($ch);
            if ($response === false) {
                $err = curl_error($ch);
                throw new cp_client_exception("curl error: {$err}");
            }
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $responsebody = is_string($response) ? $response : '';
            if ($status < 200 || $status >= 300) {
                throw new cp_client_exception(
                    "CP returned HTTP {$status}",
                    $status,
                    $responsebody
                );
            }
            $decoded = json_decode($responsebody, true);
            if (!is_array($decoded)) {
                throw new cp_client_exception(
                    'CP response was not JSON',
                    $status,
                    $responsebody
                );
            }
            return $decoded;
        } finally {
            curl_close($ch);
            fclose($handle);
        }
    }

    /**
     * Stream-download a URL into a local file. Used for snapshot
     * pulls on the spoke side (Phase 1 Step 3).
     *
     * @param string $path URL path to GET.
     * @param string $destpath Local path to write the response body to.
     * @return int Size of the downloaded file.
     * @throws cp_client_exception
     */
    public function get_to_file(string $path, string $destpath): int {
        $url = $this->baseurl . $path;
        $handle = fopen($destpath, 'wb');
        if ($handle === false) {
            throw new cp_client_exception("could not open destination: {$destpath}");
        }
        $ch = curl_init($url);
        try {
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $handle,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $this->secret,
                ],
            ]);
            $ok = curl_exec($ch);
            if ($ok === false) {
                $err = curl_error($ch);
                throw new cp_client_exception("curl error: {$err}");
            }
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($status < 200 || $status >= 300) {
                throw new cp_client_exception(
                    "CP returned HTTP {$status}",
                    $status
                );
            }
        } finally {
            curl_close($ch);
            fclose($handle);
        }
        $size = filesize($destpath);
        return $size === false ? 0 : $size;
    }
}
