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
 * Phase 0 federation seed — idempotent.
 *
 * Brings the hub into a state where a single dev spoke can call the
 * nucleus_federation web service: a test course, and the spoke
 * registered through register_spoke, so it gets its own hub account and
 * token (ADR-023) like any spoke Nucleus provisions.
 *
 * Invoked from deploy/scripts/seed-federation.sh; runnable standalone
 * inside the hub container:
 *
 *     php public/local/nucleushub/cli/seed_phase0.php [--print-token-only]
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
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

// The hub's view of the default dev spoke.
$wwwroot = 'http://spoke-web';

if (!empty($options['print-token-only'])) {
    $spoke = $DB->get_record('local_nucleushub_spokes', ['wwwroot' => $wwwroot, 'status' => 'active']);
    if (!$spoke || (string) $spoke->token === '') {
        cli_error('No spoke registered yet; run the full seed first.', 2);
    }
    echo $spoke->token . "\n";
    exit(0);
}

// 1. Test course.
$shortname = 'SAF101';
$existing = $DB->get_record('course', ['shortname' => $shortname]);
if ($existing) {
    cli_writeln("course: exists id={$existing->id}");
} else {
    $data = (object) [
        'fullname' => 'Safeguarding 101',
        'shortname' => $shortname,
        'category' => 1,
        'summary' => 'Phase 0 federation test course - seeded by seed_phase0.php.',
        'summaryformat' => FORMAT_HTML,
    ];
    $course = create_course($data);
    cli_writeln("course: created id={$course->id}");
}

// 2. Register the spoke. Same path as a spoke Nucleus provisions: its own
//    hub account and token, web services and REST switched on.
$registered = \local_nucleushub\external\register_spoke::execute($wwwroot, 'default', '');
$tokenvalue = $registered['token'];
cli_writeln("spoke: registered id={$registered['spokeId']}");

cli_writeln("=== TOKEN ===");
cli_writeln($tokenvalue);
cli_writeln("=== END ===");
