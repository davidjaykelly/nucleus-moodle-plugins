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
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@dklabs.co.uk>
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
Join a Nucleus federation - registers this Moodle as a spoke.

Usage:
    php join_federation.php --hub-url=<URL> --token=<NUCJ-...>

Options:
    --hub-url   The Nucleus API URL the federation owner gave you.
                Example: https://nucleus.dklabs.co.uk/api
    --token     The one-time join token from the Nucleus portal, in the
                form NUCJ-XXXX-XXXX-... (with or without the hyphens).
    --force     Join even if this Moodle already belongs to a federation.
                Use with care - switching federations leaves pulled course
                versions out of step.
    --help      Show this help.

When the join works, it prints the spoke ID and the federation ID. Keep
them: quote the spoke ID if you contact contact@dklabs.co.uk. The
federation owner sees the spoke in their portal straight away.
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
        "Missing plugins: " . implode(', ', $missing) . ".\n"
        . "Install them from https://github.com/davidjaykelly/nucleus-moodle-plugins,\n"
        . "run 'php admin/cli/upgrade.php', then run this script again."
    );
}

// Already-joined guard. local_nucleuscommon/cpbaseurl + cpsecret being
// non-empty means this Moodle is already registered with a control plane;
// silently overwriting would be a bad surprise.
$existingbase = (string) get_config('local_nucleuscommon', 'cpbaseurl');
$existingsecret = (string) get_config('local_nucleuscommon', 'cpsecret');
if (!empty($existingbase) && !empty($existingsecret) && empty($options['force'])) {
    cli_error(
        "This Moodle already belongs to a federation:\n"
        . "  Nucleus API URL : {$existingbase}\n"
        . "  Federation ID   : " . get_config('local_nucleuscommon', 'federationid') . "\n"
        . "Use --force to join a different one, or ask your federation owner\n"
        . "if you didn't expect this."
    );
}

// 2. Mint the CP→spoke web-service token *before* registering. The
// register response will store the same token on the CP's spoke
// row, which is what every CP→spoke endpoint (course-instances,
// preview, content-pull) authenticates against. Provisioning here
// avoids the manual "authorise admin → create token" dance the
// operator otherwise has to do via the Moodle admin UI.
// Idempotent: re-running join with the same Moodle reuses the
// existing token.
cli_writeln('Creating the Nucleus web service token...');
try {
    $cpwstoken = \local_nucleuscommon\token\cp_provisioner::ensure_token('nucleus_cp_spoke');
} catch (\Throwable $e) {
    cli_error(
        "Couldn't create the Nucleus web service token: " . $e->getMessage() . "\n"
        . "Check that local_nucleusspoke is installed and upgraded\n"
        . "(it registers the 'nucleus_cp_spoke' web service)."
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
                "Couldn't join (HTTP {$status}). The --hub-url looks like it's missing /api at the end.\n"
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
    cli_error("Couldn't join (HTTP {$status}): {$reason}");
}

$result = json_decode((string) $response, true);
if (
    !is_array($result) || empty($result['spokeId']) || empty($result['cpBaseUrl'])
    || empty($result['cpSecret']) || empty($result['hubWwwroot'])
) {
    cli_error("Nucleus sent an unexpected reply: " . substr((string) $response, 0, 500));
}

// 3. Stamp the config.
set_config('cpbaseurl', $result['cpBaseUrl'], 'local_nucleuscommon');
set_config('cpsecret', $result['cpSecret'], 'local_nucleuscommon');
set_config('cpportalurl', '', 'local_nucleuscommon');
set_config('federationid', $result['federationId'], 'local_nucleuscommon');
set_config('externalspokeid', $result['spokeId'], 'local_nucleuscommon');

set_config('hubwwwroot', $result['hubWwwroot'], 'local_nucleusspoke');
// The hubtoken is the per-spoke Moodle WS token the hub minted via
// local_nucleushub_register_spoke during the register handshake.
// The hub validates this against `external_tokens` on every spoke→hub
// WS call (list_families, describe_version, etc.). Falls back to
// cpSecret for older CP versions that don't return hubToken; that
// older fallback only worked when the hub was specifically configured
// to accept the federation-node secret as a wstoken (rare).
if (!empty($result['hubToken'])) {
    set_config('hubtoken', $result['hubToken'], 'local_nucleusspoke');
} else {
    set_config('hubtoken', $result['cpSecret'], 'local_nucleusspoke');
}
// The spokename is the slug the operator picked when minting the invite.
// Without it the settings page shows the default "default", which would
// be ambiguous if more than one external spoke joined this hub. Falls
// back to nothing (Moodle keeps its 'default' default) on older CP
// versions that don't return slug in the register response.
if (!empty($result['slug'])) {
    set_config('spokename', $result['slug'], 'local_nucleusspoke');
}
// The hubconnecturl stays empty for external spokes: they reach the hub
// at its public wwwroot, not via in-cluster DNS. The spoke admin can
// override later from settings if their network blocks public reach.
set_config('hubconnecturl', '', 'local_nucleusspoke');

// 4. Success summary.
cli_writeln('');
cli_writeln('Joined the Nucleus federation.');
cli_writeln('');
cli_writeln('  Federation ID   : ' . $result['federationId']);
cli_writeln('  Spoke ID        : ' . $result['spokeId']);
cli_writeln('  Hub URL         : ' . $result['hubWwwroot']);
cli_writeln('  Nucleus API URL : ' . $result['cpBaseUrl']);
cli_writeln('');
cli_writeln('  Keep the spoke ID - quote it if you contact contact@dklabs.co.uk.');
cli_writeln('  The federation owner sees this site in their portal within a few');
cli_writeln('  seconds.');
cli_writeln('');
cli_writeln('  Next: when the hub publishes course versions, they appear in the');
cli_writeln('  Nucleus catalogue on this site (Site administration > Plugins >');
cli_writeln('  Local plugins > Nucleus > Catalogue).');
cli_writeln('');
exit(0);
