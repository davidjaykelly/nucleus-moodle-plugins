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
 * Idempotent: ensure the Nucleus control plane has a Moodle web-service
 * token it can use to call into this tenant's pod (hub or spoke).
 *
 *   php local/nucleuscommon/cli/setup_cp_token.php
 *
 * Run by the control-plane provisioning worker after `helm install` /
 * `helm upgrade` completes. The token is printed to stdout (last line)
 * so the worker can capture and persist it on the Hub / Spoke row.
 *
 * Operates against the `nucleus_cp` service which both
 * local_nucleushub and local_nucleusspoke register in their
 * `db/services.php`. Re-running prints the existing token rather than
 * minting a new one.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/externallib.php');

list($options, $unrecognised) = cli_get_params(
    [
        // `--role=hub|spoke` picks the matching service shortname:
        // `nucleus_cp_hub` or `nucleus_cp_spoke`. Both services
        // exist in every Moodle image (the Dockerfile bakes hub +
        // spoke plugins in regardless of the pod's role); only one
        // is authoritative for the running pod's role.
        // `--service=<shortname>` overrides for ad-hoc use.
        // `--federation-id=<id>` writes local_nucleuscommon/federationid
        // — the CP federation row this Moodle belongs to. Set once at
        // provision time; downstream code (status bar, family rows,
        // spoke→hub identification) reads it from config.
        // `--cp-base-url=<url>` writes local_nucleuscommon/cpbaseurl
        // — the URL Moodle uses to call back to the CP for blob upload
        // and other federation-node operations. Set once at provision
        // time; rotate-and-restart if the CP host changes.
        // `--cp-secret=<secret>` writes local_nucleuscommon/cpsecret
        // — shared FEDERATION_NODE_SECRET matching the CP's. Used to
        // sign federation-node calls (cp_client::from_config).
        'role'          => 'hub',
        'service'       => '',
        'federation-id' => '',
        'cp-base-url'   => '',
        'cp-secret'     => '',
        'help'          => false,
    ],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error('Unrecognised options: ' . implode(', ', $unrecognised));
}

if (!empty($options['help'])) {
    echo <<<HELP
Mint or return the Nucleus control-plane web-service token.

Usage:
    php local/nucleuscommon/cli/setup_cp_token.php --role=hub|spoke
    php local/nucleuscommon/cli/setup_cp_token.php --service=<shortname>

Output:
    Diagnostic lines on stderr.
    Token on the final line of stdout.

HELP;
    exit(0);
}

global $DB;

$shortname = (string)$options['service'];
if (!$shortname) {
    if (!in_array($options['role'], ['hub', 'spoke'], true)) {
        cli_error("--role must be 'hub' or 'spoke' (or pass --service=<shortname>)", 2);
    }
    $shortname = "nucleus_cp_{$options['role']}";
}

// Elevate to admin so config edits and token minting succeed.
\core\session\manager::set_user(get_admin());
$admin = get_admin();

// 0. Record federation identity + CP wiring. Idempotent — each
//    write only happens when the value drifts. These three configs
//    are set together at provision time and feed every CP-bound
//    call (snapshot upload, federation-node auth, etc.).
$cpconfigs = [
    'federation-id' => 'federationid',
    'cp-base-url'   => 'cpbaseurl',
    'cp-secret'     => 'cpsecret',
];
foreach ($cpconfigs as $opt => $configkey) {
    $value = trim((string)$options[$opt]);
    if ($value === '') {
        continue;
    }
    $existing = (string)(get_config('local_nucleuscommon', $configkey) ?: '');
    if ($existing !== $value) {
        set_config($configkey, $value, 'local_nucleuscommon');
        // Don't echo secrets verbatim — log a fingerprint instead.
        $display = $configkey === 'cpsecret' ? '[redacted]' : $value;
        fwrite(STDERR, "{$configkey}: set to {$display}\n");
    }
}

// 1. Web services + REST protocol must be on. Most prod tenants have
//    this already from the helm chart's site config; the calls are
//    cheap no-ops when set.
if (!get_config('core', 'enablewebservices')) {
    set_config('enablewebservices', 1);
    fwrite(STDERR, "ws: enabled\n");
}
$existing = get_config('core', 'webserviceprotocols');
$protocols = ($existing && is_string($existing)) ? explode(',', $existing) : [];
if (!in_array('rest', $protocols, true)) {
    $protocols[] = 'rest';
    set_config('webserviceprotocols', implode(',', array_filter($protocols)));
    fwrite(STDERR, "ws: rest protocol enabled\n");
}

// 2. Find the service. The plugin defining the `nucleus_cp` shortname
//    (local_nucleushub or local_nucleusspoke) must be installed; if
//    it's not, the upgrade hasn't run yet and the worker should retry.
$service = $DB->get_record('external_services', ['shortname' => $shortname]);
if (!$service) {
    cli_error("service '{$shortname}' not found — has the plugin defining it been installed and upgraded?", 2);
}

// 3. `restrictedusers=1` means specific users must be authorised
//    against the service. Authorise the admin (idempotent).
if (!$DB->record_exists('external_services_users',
        ['externalserviceid' => $service->id, 'userid' => $admin->id])) {
    $DB->insert_record('external_services_users', (object) [
        'externalserviceid' => $service->id,
        'userid'            => $admin->id,
        'timecreated'       => time(),
    ]);
    fwrite(STDERR, "service: admin authorised on service id={$service->id}\n");
}

// 4. Reuse the first existing permanent token, otherwise mint one.
//    Tokens are scoped to (user, service); we tag with a recognisable
//    name so operators can distinguish them in the Moodle admin UI.
$tokens = $DB->get_records('external_tokens', [
    'userid'            => $admin->id,
    'externalserviceid' => $service->id,
    'tokentype'         => EXTERNAL_TOKEN_PERMANENT,
], 'timecreated ASC');

if ($tokens) {
    $tok = reset($tokens);
    $tokenvalue = $tok->token;
    fwrite(STDERR, "token: reused id={$tok->id}\n");
} else {
    $tokenvalue = \core_external\util::generate_token(
        EXTERNAL_TOKEN_PERMANENT,
        $service,
        $admin->id,
        \context_system::instance(),
        0,
        '',
        'Nucleus control plane'
    );
    fwrite(STDERR, "token: generated\n");
}

// 5. Print the token on its own (final) stdout line so the
//    provisioning worker can capture it with `tail -n 1`. Using
//    fwrite(STDOUT, …) without a trailing newline keeps it parseable.
fwrite(STDOUT, $tokenvalue);
exit(0);
