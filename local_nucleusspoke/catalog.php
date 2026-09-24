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
 * The Catalogue: every course the hub shares, with a Pull button for
 * anything newer than what this site has.
 *
 * Pulling is a POST (single_button with sesskey). A pull refused for
 * missing plugins shows why, above the list, with a way to try again.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_nucleuscommon\local\site;
use local_nucleusspoke\client\hub_client;
use local_nucleusspoke\output\blocked_report;
use local_nucleusspoke\output\catalog_page;
use local_nucleusspoke\version\puller;

admin_externalpage_setup('local_nucleusspoke_catalog');
$pageurl = new moodle_url('/local/nucleusspoke/catalog.php');

$catalog = null;
$catalogerror = null;
try {
    $catalog = hub_client::default()->list_families();
} catch (\moodle_exception $e) {
    debugging('Nucleus catalogue: ' . $e->getMessage() . ' ' . ($e->debuginfo ?? ''), DEBUG_DEVELOPER);
    $catalogerror = $e->getMessage();
}

$blocked = null;
if (optional_param('pull', 0, PARAM_BOOL) && $catalog !== null && data_submitted() && confirm_sesskey()) {
    $familyguid = required_param('familyguid', PARAM_ALPHANUMEXT);
    $versionguid = required_param('versionguid', PARAM_ALPHANUMEXT);

    // Take the family and version from the hub's own list, not the request.
    $family = null;
    $version = null;
    foreach ($catalog as $candidate) {
        if ($candidate['guid'] === $familyguid) {
            $family = $candidate;
            foreach ($candidate['versions'] as $v) {
                if ($v['guid'] === $versionguid) {
                    $version = $v;
                }
            }
        }
    }
    if (!$family || !$version) {
        redirect(
            $pageurl,
            get_string('catalog_pullnotfound', 'local_nucleusspoke'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    try {
        $result = puller::pull(
            [
                'guid' => $family['guid'],
                'slug' => $family['slug'],
                'hubfederationid' => $family['hubfederationid'],
            ],
            [
                'guid' => $version['guid'],
                'versionnumber' => $version['versionnumber'],
                'severity' => $version['severity'],
                'snapshotref' => $version['snapshotref'],
                'snapshothash' => $version['snapshothash'],
                'hubcourseid' => (int) $version['hubcourseid'],
                'timepublished' => (int) $version['timepublished'],
                'releasenotes' => $version['releasenotes'] ?? '',
                'lockedforspokeedit' => !empty($version['lockedforspokeedit']),
            ],
            1,
            (int) $USER->id
        );
        redirect($pageurl, s(get_string('catalog_pullsuccess', 'local_nucleusspoke', (object) [
            'slug' => $family['slug'],
            'version' => $version['versionnumber'],
        ])) . ' ' . html_writer::link(
            new moodle_url('/course/view.php', ['id' => $result['localcourseid']]),
            get_string('opencourse', 'local_nucleusspoke')
        ), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (\moodle_exception $e) {
        // Refused for missing plugins or an older Moodle: the detail is
        // in debuginfo, as JSON. Anything else is a one-line error.
        $payload = $e->errorcode === 'dependencyblocked' ? json_decode((string) ($e->debuginfo ?? ''), true) : null;
        if (is_array($payload)) {
            $blocked = new blocked_report($family, $version, $payload, $pageurl);
        } else {
            debugging('Nucleus pull failed: ' . $e->getMessage() . ' ' . ($e->debuginfo ?? ''), DEBUG_DEVELOPER);
            redirect($pageurl, s(trim(get_string('pullfailure', 'local_nucleusspoke', $e->getMessage())
                . ' ' . site::support_line())), null, \core\output\notification::NOTIFY_ERROR);
        }
    }
}

$renderer = $PAGE->get_renderer('local_nucleusspoke');
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('catalog_title', 'local_nucleusspoke'));
if ($blocked) {
    echo $renderer->render($blocked);
}
if ($catalogerror !== null) {
    echo $OUTPUT->notification(s(trim(get_string('catalog_hubunreachable', 'local_nucleusspoke', $catalogerror)
        . ' ' . site::support_line())), \core\output\notification::NOTIFY_ERROR, false);
} else if (!$catalog) {
    echo $OUTPUT->notification(
        get_string('catalog_empty', 'local_nucleusspoke'),
        \core\output\notification::NOTIFY_INFO,
        false
    );
} else {
    echo $renderer->render(new catalog_page($catalog, $pageurl));
}
echo $OUTPUT->footer();
