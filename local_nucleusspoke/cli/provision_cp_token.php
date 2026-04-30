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
 * ADR-021 follow-up — backfill the CP→spoke web-service token for an
 * external spoke that already joined.
 *
 * Use case: this Moodle ran `join_federation.php` before the join
 * CLI started auto-minting cpWsToken (anything before
 * local_nucleuscommon 0.6.1), so the CP can't call any of this
 * spoke's WS endpoints (course-instances, preview-pull, etc).
 *
 *   php local/nucleusspoke/cli/provision_cp_token.php
 *
 * Idempotent — reuses the existing token if one's already minted,
 * and the CP's setCpToken endpoint is itself idempotent on the row.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');

[$options, $unrecognised] = cli_get_params(
    ['help' => false],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error('Unknown options: ' . implode(', ', $unrecognised));
}

if (!empty($options['help'])) {
    cli_writeln(<<<HELP
Provision the CP→spoke web-service token after the fact.

Run this on a Moodle that's already joined as an external spoke
(via join_federation.php) but is missing the cpWsToken on the
control-plane side. Symptom: "spoke 'X' has no CP token" 404s
when the operator clicks course-instances or preview-pull.

The script:
  1. Mints (or reuses) a permanent token against the
     `nucleus_cp_spoke` external service on this Moodle.
  2. POSTs it to <cp>/external-spokes/cp-token, signed with the
     federation-node shared secret already in this Moodle's config.

No flags. Reads everything from local_nucleuscommon config.
HELP);
    exit(0);
}

// 1. Read CP wiring written by join_federation.php.
$cpbaseurl = (string) get_config('local_nucleuscommon', 'cpbaseurl');
$cpsecret = (string) get_config('local_nucleuscommon', 'cpsecret');
$spokeid = (string) get_config('local_nucleuscommon', 'externalspokeid');

if ($cpbaseurl === '' || $cpsecret === '' || $spokeid === '') {
    cli_error(
        "This Moodle isn't joined to a federation yet (cpbaseurl / cpsecret /\n"
        . "externalspokeid not set in local_nucleuscommon config). Run\n"
        . 'join_federation.php first.'
    );
}

// 2. Mint or reuse the token.
cli_writeln('Provisioning cp_spoke token on this Moodle...');
try {
    $cpwstoken = \local_nucleuscommon\token\cp_provisioner::ensure_token('nucleus_cp_spoke');
} catch (\Throwable $e) {
    cli_error('Failed to mint token: ' . $e->getMessage());
}
cli_writeln('  token: ' . substr($cpwstoken, 0, 8) . '… (' . strlen($cpwstoken) . ' chars)');

// 3. POST to CP. /external-spokes/cp-token is FederationNodeGuard'd —
//    same Bearer secret pattern as the heartbeat endpoint.
$endpoint = rtrim($cpbaseurl, '/') . '/external-spokes/cp-token';
cli_writeln("Uploading to {$endpoint}...");

$curl = new curl();
$curl->setHeader([
    'Content-Type: application/json',
    'Accept: application/json',
    'Authorization: Bearer ' . $cpsecret,
]);
$response = $curl->post(
    $endpoint,
    json_encode(['spokeId' => $spokeid, 'cpwstoken' => $cpwstoken]),
    ['CURLOPT_TIMEOUT' => 30, 'CURLOPT_SSL_VERIFYPEER' => 1]
);
$status = $curl->info['http_code'] ?? 0;

if ($status !== 200) {
    $reason = '(no response body)';
    if (!empty($response)) {
        $decoded = json_decode($response, true);
        $reason = is_array($decoded)
            ? (isset($decoded['message']) ? $decoded['message'] : json_encode($decoded))
            : substr((string) $response, 0, 500);
    }
    cli_error("CP rejected the token (HTTP {$status}): {$reason}");
}

cli_writeln('');
cli_writeln('────────────────────────────────────────────────────────────');
cli_writeln(' CP→spoke token uploaded.');
cli_writeln('────────────────────────────────────────────────────────────');
cli_writeln('');
cli_writeln('  Spoke id : ' . $spokeid);
cli_writeln('  CP base  : ' . $cpbaseurl);
cli_writeln('');
cli_writeln('  The operator portal can now call into this spoke');
cli_writeln('  (course-instances, preview-pull, etc).');
cli_writeln('');
exit(0);
