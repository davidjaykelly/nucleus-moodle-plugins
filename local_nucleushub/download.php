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
 * MBZ download endpoint for Mode A federation.
 *
 * Phase 1 replacement for the Phase 0 shared-docker-volume pattern —
 * a Kind single-node cluster can't share a RWX volume across pods, and
 * in production we want signed URLs anyway. This endpoint is the
 * middle step: authenticate the caller with the same WS token they
 * used to call `request_course_copy`, then stream the MBZ from
 * `$CFG->dataroot/nucleushub_backups/`.
 *
 * Phase 6 replaces this with an S3 signed-URL indirection via the
 * `objectfs` plugin; the spoke-side fetch logic stays unchanged
 * (both return absolute/relative URLs to HTTP-fetch).
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Keep this lightweight — no session, no $PAGE, no output buffering.
define('NO_MOODLE_COOKIES', true);
define('NO_OUTPUT_BUFFERING', true);

require(__DIR__ . '/../../config.php');

$token = required_param('wstoken', PARAM_ALPHANUM);
$filename = required_param('file', PARAM_FILE);

global $DB;

// ---- Auth --------------------------------------------------------------
// Must be a permanent WS token on the nucleus_federation service. This
// mirrors what Moodle's webservice/rest/server.php does internally but
// avoids invoking the entire WS stack for a file download.
$service = $DB->get_record('external_services',
    ['shortname' => 'nucleus_federation'], '*', IGNORE_MISSING);
if (!$service) {
    send_header_404();
}
$tokenrow = $DB->get_record('external_tokens', [
    'token'             => $token,
    'externalserviceid' => $service->id,
    'tokentype'         => EXTERNAL_TOKEN_PERMANENT,
]);
if (!$tokenrow) {
    send_header_401();
}

// ---- File resolution ---------------------------------------------------
// Defence in depth: realpath + prefix check so a crafted `file=` can't
// escape the backups directory.
$basedir = rtrim($CFG->dataroot, '/') . '/nucleushub_backups';
$candidate = $basedir . '/' . $filename;
$real = realpath($candidate);
if (!$real || strpos($real, $basedir . '/') !== 0 || !is_file($real)) {
    send_header_404();
}

// ---- Stream out --------------------------------------------------------
$size = filesize($real);
header('Content-Type: application/vnd.moodle.backup');
header('Content-Length: ' . $size);
header('Content-Disposition: attachment; filename="' . basename($real) . '"');
header('Cache-Control: no-store');

// readfile() handles large files without buffering the whole thing in memory.
readfile($real);
exit;

/**
 * Tiny helpers keep the top-of-file control flow linear. We don't use
 * moodle_exception here because this endpoint's clients are scripts,
 * not users — a plain HTTP status is what they expect.
 */
function send_header_401(): never {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo "401 unauthorised\n";
    exit;
}

function send_header_404(): never {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 not found\n";
    exit;
}
