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
 * ADR-020 — join_federation.php.
 *
 * One-shot CLI a Moodle admin runs to register their existing site as
 * an external spoke in a Nucleus federation. The federation owner mints
 * a join token from their operator portal and passes it (plus the hub
 * URL) to the admin out-of-band; this script does the rest.
 *
 * What it does:
 *   1. Verifies the three local_nucleus* plugins are installed.
 *   2. POSTs the token to <hub>/api/external-spokes/register.
 *   3. On success, writes the per-spoke control-plane secret +
 *      federation id + hub coords into local_nucleuscommon /
 *      local_nucleusspoke config.
 *   4. Prints a "joined" summary including the spoke id (the admin
 *      should keep this for support purposes).
 *
 * Idempotent — re-running with the same token returns the same config
 * + re-stamps it. Safe to run twice if the first call errored mid-write.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');

[$options, $unrecognised] = cli_get_params(
    [
        'help'     => false,
        'hub-url'  => null,
        'token'    => null,
        // For testing / reissue scenarios — explicitly opt-in to
        // overwriting an already-joined config.
        'force'    => false,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognised) {
    cli_error("Unknown options: " . implode(', ', $unrecognised));
}

if ($options['help'] || !$options['hub-url'] || !$options['token']) {
    cli_writeln(<<<HELP
Nucleus federation join — register this Moodle as an external spoke.

Usage:
    php join_federation.php --hub-url=<URL> --token=<NUCJ-...>

Options:
    --hub-url   The control-plane base URL of the federation, given to you
                by the federation owner. Example: https://app.nucleuslms.io/api
    --token     The one-time join token from the operator portal. Format
                is NUCJ-XXXX-XXXX-... (or the same value with the dashes
                removed; both are accepted).
    --force     Re-join even if this Moodle is already configured for a
                federation. USE WITH CARE — switching federations mid-
                flight will leave course versions in an inconsistent state.
    --help      Show this help.

After a successful join you'll see a summary block printing the spoke id
and the federation id. Keep these for support purposes; the federation
owner will see your spoke appear in their portal automatically.
HELP);
    exit($options['help'] ? 0 : 1);
}

// 1. Plugin presence check.
$required = ['local_nucleuscommon', 'local_nucleusspoke'];
$missing  = [];
foreach ($required as $component) {
    $plugin = core_plugin_manager::instance()->get_plugin_info($component);
    if (!$plugin) {
        $missing[] = $component;
    }
}
if ($missing) {
    cli_error(
        "Missing plugin(s): " . implode(', ', $missing) . ".\n"
        . "Install them first from https://github.com/davidjaykelly/nucleus-moodle-plugins\n"
        . "and run `php admin/cli/upgrade.php` before re-running this script."
    );
}

// Already-joined guard. local_nucleuscommon/cpbaseurl + cpsecret being
// non-empty means this Moodle is already registered with a control plane;
// silently overwriting would be a bad surprise.
$existingbase = (string) get_config('local_nucleuscommon', 'cpbaseurl');
$existingsecret = (string) get_config('local_nucleuscommon', 'cpsecret');
if (!empty($existingbase) && !empty($existingsecret) && empty($options['force'])) {
    cli_error(
        "This Moodle is already registered with a federation:\n"
        . "  cpbaseurl  = {$existingbase}\n"
        . "  federationid = " . get_config('local_nucleuscommon', 'federationid') . "\n"
        . "Pass --force to overwrite, or contact your federation owner if\n"
        . "you didn't expect this state."
    );
}

// 2. Mint the CP→spoke web-service token *before* registering. The
//    register response will store the same token on the CP's spoke
//    row, which is what every CP→spoke endpoint (course-instances,
//    preview, content-pull) authenticates against. Provisioning here
//    avoids the manual "authorise admin → create token" dance the
//    operator otherwise has to do via the Moodle admin UI.
//    Idempotent: re-running join with the same Moodle reuses the
//    existing token.
cli_writeln('Provisioning control-plane WS token...');
try {
    $cpwstoken = \local_nucleuscommon\token\cp_provisioner::ensure_token('nucleus_cp_spoke');
} catch (\Throwable $e) {
    cli_error(
        'Failed to mint CP web-service token: ' . $e->getMessage() . "\n"
        . "Check that local_nucleusspoke is installed and upgraded\n"
        . "(it registers the `nucleus_cp_spoke` external service)."
    );
}

// 3. Register against the hub.
$registerurl = rtrim($options['hub-url'], '/') . '/external-spokes/register';
cli_writeln("Registering with {$registerurl}...");

$body = json_encode([
    'token'         => $options['token'],
    'spokeBaseUrl'  => $CFG->wwwroot,
    // Allows the CP to call back into this Moodle (preview pulls,
    // course-instance lookups, etc). Validated server-side as 32 hex
    // chars before persistence.
    'cpwstoken'     => $cpwstoken,
    'pluginVersions' => [
        'common' => get_config('local_nucleuscommon', 'version'),
        'spoke'  => get_config('local_nucleusspoke', 'version'),
        'hub'    => get_config('local_nucleushub', 'version') ?: null,
    ],
]);

$curl = new curl();
$curl->setHeader([
    'Content-Type: application/json',
    'Accept: application/json',
]);
$response = $curl->post($registerurl, $body, [
    'CURLOPT_TIMEOUT' => 30,
    'CURLOPT_SSL_VERIFYPEER' => 1,
]);
$status = $curl->info['http_code'] ?? 0;

if ($status !== 200) {
    // 405 from nginx + a non-JSON body strongly suggests the hub-url
    // is missing the `/api` suffix (request hit the SPA's static
    // server instead of the control plane). Print a precise hint
    // before falling through to the generic error path.
    if ($status === 405 || $status === 404) {
        $hub = rtrim($options['hub-url'], '/');
        if (!preg_match('@/api(/|$)@', $hub)) {
            cli_error(
                "Register failed (HTTP {$status}). Looks like the hub-url is missing the /api suffix.\n"
                . "Try: --hub-url={$hub}/api"
            );
        }
    }

    // The control plane returns structured errors — surface them.
    $reason = '(no response body)';
    if (!empty($response)) {
        $decoded = json_decode($response, true);
        $reason = is_array($decoded)
            ? (isset($decoded['message']) ? $decoded['message'] : json_encode($decoded))
            : substr((string) $response, 0, 500);
    }
    cli_error("Register failed (HTTP {$status}): {$reason}");
}

$result = json_decode((string) $response, true);
if (!is_array($result) || empty($result['spokeId']) || empty($result['cpBaseUrl'])
    || empty($result['cpSecret']) || empty($result['hubWwwroot'])) {
    cli_error("Register response was not in the expected shape: " . substr((string) $response, 0, 500));
}

// 3. Stamp the config.
set_config('cpbaseurl',     $result['cpBaseUrl'],   'local_nucleuscommon');
set_config('cpsecret',      $result['cpSecret'],    'local_nucleuscommon');
set_config('cpportalurl',   '',                     'local_nucleuscommon');
set_config('federationid',  $result['federationId'], 'local_nucleuscommon');
set_config('externalspokeid', $result['spokeId'],   'local_nucleuscommon');

set_config('hubwwwroot',    $result['hubWwwroot'],  'local_nucleusspoke');
// hubtoken is the per-spoke Moodle WS token the hub minted via
// local_nucleushub_register_spoke during the register handshake.
// The hub validates this against `external_tokens` on every spoke→hub
// WS call (list_families, request_course_copy, etc.). Falls back to
// cpSecret for older CP versions that don't return hubToken; that
// older fallback only worked when the hub was specifically configured
// to accept the federation-node secret as a wstoken (rare).
if (!empty($result['hubToken'])) {
    set_config('hubtoken', $result['hubToken'], 'local_nucleusspoke');
} else {
    set_config('hubtoken', $result['cpSecret'], 'local_nucleusspoke');
}
// spokename = the slug the operator picked when minting the invite.
// It's the Redis Streams consumer-group identifier; without it the
// settings page renders the default "default" placeholder which would
// collide if more than one external spoke joined this hub. Falls back
// to nothing (Moodle keeps its 'default' default) on older CP versions
// that don't return slug in the register response.
if (!empty($result['slug'])) {
    set_config('spokename', $result['slug'], 'local_nucleusspoke');
}
// hubconnecturl stays empty for external spokes — they reach the hub
// at its public wwwroot, not via in-cluster DNS. The spoke admin can
// override later from settings if their network blocks public reach.
set_config('hubconnecturl', '', 'local_nucleusspoke');

// 4. Success summary.
cli_writeln('');
cli_writeln('────────────────────────────────────────────────────────────');
cli_writeln(' Joined Nucleus federation.');
cli_writeln('────────────────────────────────────────────────────────────');
cli_writeln('');
cli_writeln('  Federation id : ' . $result['federationId']);
cli_writeln('  Spoke id      : ' . $result['spokeId']);
cli_writeln('  Hub wwwroot   : ' . $result['hubWwwroot']);
cli_writeln('  CP base url   : ' . $result['cpBaseUrl']);
cli_writeln('');
cli_writeln('  Keep the spoke id for support tickets. Your federation owner');
cli_writeln('  will see this site appear as a running spoke in their portal');
cli_writeln('  within a few seconds.');
cli_writeln('');
cli_writeln('  Next step: push course versions from the hub and they will');
cli_writeln('  appear in this Moodle\'s catalogue (Site admin → Plugins →');
cli_writeln('  Local plugins → Nucleus federation spoke).');
cli_writeln('');
exit(0);
