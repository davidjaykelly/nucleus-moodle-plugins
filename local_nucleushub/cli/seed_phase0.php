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
 * Phase 0 federation seed — idempotent.
 *
 * Brings the hub into a state where a single spoke can call the
 * nucleus_federation web service. Not meant to survive past Phase 0 —
 * Phase 1 replaces this with a proper `grant_spoke` CLI driven by the
 * control plane.
 *
 * Invoked from phase-0/scripts/seed-federation.sh; runnable standalone
 * inside the hub container:
 *
 *     php public/local/nucleushub/cli/seed_phase0.php [--print-token-only]
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognised) = cli_get_params(
    [
        'print-token-only' => false,
        'help' => false,
    ],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error('Unrecognised options: ' . implode(', ', $unrecognised));
}

if (!empty($options['help'])) {
    echo "Usage: php seed_phase0.php [--print-token-only]\n";
    exit(0);
}

global $DB;

// Elevate to admin so create_course, set_config, and token minting succeed.
\core\session\manager::set_user(get_admin());
$admin = get_admin();

if (!empty($options['print-token-only'])) {
    $service = $DB->get_record('external_services', ['shortname' => 'nucleus_federation']);
    if (!$service) {
        cli_error('nucleus_federation service not registered; run the full seed first.', 2);
    }
    $tok = $DB->get_record_select(
        'external_tokens',
        'userid = :uid AND externalserviceid = :sid AND tokentype = :t',
        ['uid' => $admin->id, 'sid' => $service->id, 't' => EXTERNAL_TOKEN_PERMANENT],
        '*',
        IGNORE_MULTIPLE
    );
    if (!$tok) {
        cli_error('No token minted yet; run the full seed first.', 2);
    }
    echo $tok->token . "\n";
    exit(0);
}

// 1. Web services infra.
set_config('enablewebservices', 1);
$existing = get_config('core', 'webserviceprotocols');
$protocols = ($existing && is_string($existing)) ? explode(',', $existing) : [];
if (!in_array('rest', $protocols, true)) {
    $protocols[] = 'rest';
    set_config('webserviceprotocols', implode(',', array_filter($protocols)));
}
cli_writeln("ws: enabled, protocols=" . get_config('core', 'webserviceprotocols'));

// 2. Test course.
$shortname = 'SAF101';
$existing = $DB->get_record('course', ['shortname' => $shortname]);
if ($existing) {
    cli_writeln("course: exists id={$existing->id}");
} else {
    $data = (object) [
        'fullname' => 'Safeguarding 101',
        'shortname' => $shortname,
        'category' => 1,
        'summary' => 'Phase 0 federation test course — seeded by seed_phase0.php.',
        'summaryformat' => FORMAT_HTML,
    ];
    $course = create_course($data);
    cli_writeln("course: created id={$course->id}");
}

// 3. Spokes row (hub's view of the default Phase 0 spoke).
$wwwroot = 'http://spoke-web';
$existing = $DB->get_record('local_nucleushub_spokes', ['wwwroot' => $wwwroot]);
if ($existing) {
    cli_writeln("spoke: exists id={$existing->id}");
    $spokeid = (int)$existing->id;
} else {
    $now = time();
    $spokeid = $DB->insert_record('local_nucleushub_spokes', (object) [
        'name' => 'default',
        'wwwroot' => $wwwroot,
        'token' => '',
        'status' => 'active',
        'timecreated' => $now,
        'timemodified' => $now,
    ]);
    cli_writeln("spoke: created id={$spokeid}");
}

// 4. Authorise admin on the service (restrictedusers=1 gates use).
$service = $DB->get_record('external_services', ['shortname' => 'nucleus_federation'], '*', MUST_EXIST);
if (!$DB->record_exists('external_services_users',
        ['externalserviceid' => $service->id, 'userid' => $admin->id])) {
    $DB->insert_record('external_services_users', (object) [
        'externalserviceid' => $service->id,
        'userid' => $admin->id,
        'timecreated' => time(),
    ]);
    cli_writeln("authorised: admin against service id={$service->id}");
} else {
    cli_writeln("authorised: admin already on service");
}

// 5. Token — reuse first existing permanent, else mint.
$existingtokens = $DB->get_records('external_tokens', [
    'userid' => $admin->id,
    'externalserviceid' => $service->id,
    'tokentype' => EXTERNAL_TOKEN_PERMANENT,
], 'timecreated ASC');
if ($existingtokens) {
    $tok = reset($existingtokens);
    $tokenvalue = $tok->token;
    cli_writeln("token: reused id={$tok->id}");
} else {
    $tokenvalue = \core_external\util::generate_token(
        EXTERNAL_TOKEN_PERMANENT,
        $service,
        $admin->id,
        \context_system::instance(),
        0,
        '',
        'Nucleus federation (Phase 0 seed)'
    );
    cli_writeln("token: generated");
}

// 6. Mirror the token into the spoke row so the hub side also has a copy.
$DB->set_field('local_nucleushub_spokes', 'token', $tokenvalue, ['id' => $spokeid]);

cli_writeln("=== TOKEN ===");
cli_writeln($tokenvalue);
cli_writeln("=== END ===");
