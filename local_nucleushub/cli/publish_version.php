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
 * CLI: publish a new version of a hub course (ADR-014 Phase 1).
 *
 * Runs the full publish flow end-to-end: backup → upload to CP →
 * record version → emit stream event. Primarily a smoke-testing
 * tool until the Moodle UI lands; operators can also use it for
 * scripted / scheduled publishes.
 *
 *     php cli/publish_version.php --course=5 --severity=minor --notes="bugfix"
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_nucleushub\version\publisher;

list($options, $unrecognised) = cli_get_params(
    [
        'course'    => null,
        'severity'  => 'patch',
        'notes'     => '',
        'family'    => '',
        'help'      => false,
    ],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error("Unrecognised options: " . implode(', ', array_keys($unrecognised)));
}

if ($options['help'] || empty($options['course'])) {
    cli_writeln(<<<USAGE
Publish a new version of a course family.

Usage:
    php cli/publish_version.php --course=ID [--severity=major|minor|patch]
                                [--notes="..."] [--family=GUID]

Options:
    --course     Required. mdl_course.id of the working course to publish.
    --severity   Defaults to 'patch'. Drives the version-number bump.
    --notes      Release notes. Required for minor/major, optional for patch.
    --family     Optional. Publish into an existing family by guid.
                 Omit to auto-resolve (reuse draft binding) or create.
    -h, --help   Show this help and exit.

Reads local_nucleuscommon/{federationid,cpbaseurl,cpsecret} from config.
USAGE);
    exit(0);
}

$courseid = (int) $options['course'];
$severity = (string) $options['severity'];
$notes = $options['notes'] !== '' ? (string) $options['notes'] : null;
$familyguid = $options['family'] !== '' ? (string) $options['family'] : null;

cli_writeln("Publishing course {$courseid} as {$severity} ...");
$adminid = (int) get_admin()->id;

try {
    $result = publisher::publish($courseid, $severity, $notes, $familyguid, $adminid);
} catch (\Throwable $e) {
    cli_error("publish failed: " . $e->getMessage());
}

cli_writeln('');
cli_writeln('Published:');
cli_writeln(sprintf('  family guid     %s', $result['familyguid']));
cli_writeln(sprintf('  version guid    %s', $result['versionguid']));
cli_writeln(sprintf('  version number  %s', $result['versionnumber']));
cli_writeln(sprintf('  snapshot ref    %s', $result['snapshotref']));
cli_writeln(sprintf('  snapshot hash   %s', $result['snapshothash']));
cli_writeln(sprintf('  size (bytes)    %d', $result['size']));
cli_writeln(sprintf('  time published  %d', $result['timepublished']));
cli_writeln('');
cli_writeln('Event course_version_published.v1 emitted — check control-plane logs for fan-out.');
exit(0);
