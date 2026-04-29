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
 * CLI: pull a course-family version onto this spoke (ADR-014
 * Phase 1). Either picks up the oldest pending notification and
 * pulls that, or pulls a specific (family, version) by guid.
 *
 *     php cli/pull_version.php --pending
 *     php cli/pull_version.php --family-guid=<fguid> --version-guid=<vguid>
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_nucleusspoke\version\puller;

list($options, $unrecognised) = cli_get_params(
    [
        'pending'       => false,
        'family-guid'   => '',
        'version-guid'  => '',
        'category'      => 1,
        'list'          => false,
        'help'          => false,
    ],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error("Unrecognised options: " . implode(', ', array_keys($unrecognised)));
}

if ($options['help']) {
    cli_writeln(<<<USAGE
Pull a version of a course family onto this spoke.

Usage:
    php cli/pull_version.php --pending [--category=N]
    php cli/pull_version.php --family-guid=FGUID --version-guid=VGUID [--category=N]
    php cli/pull_version.php --list

Options:
    --pending        Pull the oldest 'pending' notification.
    --family-guid    Family guid (for explicit pull).
    --version-guid   Version guid (for explicit pull).
    --category       Target course category id. Default: 1 (top level).
    --list           Print pending notifications and exit.
    -h, --help       Show this help.

Reads local_nucleuscommon/{cpbaseurl,cpsecret} for the snapshot download.
USAGE);
    exit(0);
}

global $DB;

if ($options['list']) {
    $rows = $DB->get_records_sql(
        "SELECT n.id, n.state, n.timereceived,
                f.guid AS familyguid, f.slug AS familyslug,
                v.guid AS versionguid, v.versionnumber, v.severity
           FROM {local_nucleusspoke_notification} n
           JOIN {local_nucleuscommon_family} f ON f.id = n.familyid
           JOIN {local_nucleuscommon_version} v ON v.id = n.versionid
          ORDER BY n.timereceived ASC"
    );
    if (!$rows) {
        cli_writeln('(no notifications yet)');
        exit(0);
    }
    foreach ($rows as $r) {
        cli_writeln(sprintf(
            "%s  %-9s  %s v%s (%s)  family=%s",
            date('Y-m-d H:i', $r->timereceived),
            $r->state,
            $r->familyslug,
            $r->versionnumber,
            $r->severity,
            $r->familyguid
        ));
    }
    exit(0);
}

$adminid = (int) get_admin()->id;
$familyguid = (string) $options['family-guid'];
$versionguid = (string) $options['version-guid'];

if ($options['pending']) {
    $row = $DB->get_record_sql(
        "SELECT f.guid AS familyguid, v.guid AS versionguid
           FROM {local_nucleusspoke_notification} n
           JOIN {local_nucleuscommon_family} f ON f.id = n.familyid
           JOIN {local_nucleuscommon_version} v ON v.id = n.versionid
          WHERE n.state = 'pending'
          ORDER BY n.timereceived ASC
          LIMIT 1"
    );
    if (!$row) {
        cli_error('No pending notifications — nothing to pull.');
    }
    $familyguid = (string) $row->familyguid;
    $versionguid = (string) $row->versionguid;
    cli_writeln("Resolved --pending → family {$familyguid}  version {$versionguid}");
}

if ($familyguid === '' || $versionguid === '') {
    cli_error('Need --pending, --list, or both --family-guid and --version-guid. See --help.');
}

$familyrow = $DB->get_record(
    'local_nucleuscommon_family',
    ['guid' => $familyguid],
    '*',
    MUST_EXIST
);
$versionrow = $DB->get_record(
    'local_nucleuscommon_version',
    ['guid' => $versionguid],
    '*',
    MUST_EXIST
);

$familydto = [
    'guid' => $familyrow->guid,
    'slug' => $familyrow->slug,
    'hubfederationid' => $familyrow->hubfederationid,
];
$versiondto = [
    'guid' => $versionrow->guid,
    'versionnumber' => $versionrow->versionnumber,
    'severity' => $versionrow->severity,
    'snapshotref' => $versionrow->snapshotref,
    'snapshothash' => $versionrow->snapshothash,
    'hubcourseid' => (int) $versionrow->hubcourseid,
    'timepublished' => (int) $versionrow->timepublished,
    'releasenotes' => $versionrow->releasenotes,
];

cli_writeln("Pulling {$familyrow->slug} v{$versionrow->versionnumber} ...");

try {
    $result = puller::pull(
        $familydto,
        $versiondto,
        (int) $options['category'],
        $adminid
    );
} catch (\Throwable $e) {
    cli_error('pull failed: ' . $e->getMessage());
}

cli_writeln('');
cli_writeln('Pulled:');
cli_writeln(sprintf('  instance id     %d', $result['instanceid']));
cli_writeln(sprintf('  local course id %d', $result['localcourseid']));
cli_writeln(sprintf('  family id       %d', $result['familyid']));
cli_writeln(sprintf('  version id      %d', $result['versionid']));
cli_writeln(sprintf('  time pulled     %d', $result['timepulled']));
cli_writeln('');
cli_writeln('Notifications for this (family, version) have been marked resolved.');
exit(0);
