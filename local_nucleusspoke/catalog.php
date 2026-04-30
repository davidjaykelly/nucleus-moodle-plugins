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
 * Federation catalog browser (ADR-014 Phase 1, A3).
 *
 * Lists every family the hub publishes — including ones this spoke
 * hasn't been notified about yet — and offers one-click pull of the
 * latest version. Sister page to versions.php (which manages
 * notifications + already-pulled instances). Lives behind the same
 * pull capability.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

require(__DIR__ . '/../../../config.php');

use local_nucleusspoke\client\hub_client;
use local_nucleusspoke\version\puller;

$pageurl = new moodle_url('/local/nucleusspoke/catalog.php');
$PAGE->set_url($pageurl);
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('catalog_title', 'local_nucleusspoke'));
$PAGE->set_heading(get_string('catalog_title', 'local_nucleusspoke'));
$PAGE->set_pagelayout('admin');

require_login();
require_capability('local/nucleusspoke:pull', context_system::instance());

// --- Pull-from-catalog action.
//     Inputs are family-guid + version-guid (both from list_families
//     response). Resolves them through the catalog payload to avoid
//     trusting any client-side data; the rest of the puller flow is
//     identical to the notification-driven path.
$pullfamily = optional_param('familyguid', '', PARAM_ALPHANUMEXT);
$pullversion = optional_param('versionguid', '', PARAM_ALPHANUMEXT);

$catalog = null;
$catalogerror = null;
try {
    $catalog = hub_client::default()->list_families();
} catch (\Throwable $e) {
    $catalogerror = $e->getMessage();
}

if ($pullfamily !== '' && $pullversion !== '' && $catalog !== null) {
    require_sesskey();

    $family = null;
    $version = null;
    foreach ($catalog as $f) {
        if ($f['guid'] === $pullfamily) {
            $family = $f;
            foreach ($f['versions'] as $v) {
                if ($v['guid'] === $pullversion) {
                    $version = $v;
                    break;
                }
            }
            break;
        }
    }
    if (!$family || !$version) {
        redirect($pageurl, get_string('catalog_pullnotfound', 'local_nucleusspoke'),
            null, \core\output\notification::NOTIFY_ERROR);
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
            ],
            1,
            (int) $USER->id,
        );
        redirect(
            $pageurl,
            get_string('catalog_pullsuccess', 'local_nucleusspoke', (object) [
                'slug' => $family['slug'],
                'version' => $version['versionnumber'],
                'courseid' => $result['localcourseid'] ?? 0,
            ]),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\Throwable $e) {
        // ADR-021 v1.1 — dependency-blocked pulls get a dedicated
        // compatibility-report page rather than a one-line toast. The
        // structured payload sits on the exception's $debuginfo (set
        // by puller::preflight_dependencies). Other errors still use
        // the toast-redirect path — they're unstructured and the toast
        // is fine for those.
        $blocked = ($e instanceof \moodle_exception)
            && $e->errorcode === 'dependencyblocked';
        if ($blocked) {
            $structured = json_decode((string) ($e->debuginfo ?? ''), true);
            if (is_array($structured)) {
                $blockedfamily = $family;
                $blockedversion = $version;
                $blockedpayload = $structured;
                // Render page below — fall through to header() + body.
            } else {
                redirect($pageurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
            }
        } else {
            redirect($pageurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
    }
}

// --- Pre-compute "already pulled" lookup so we can mark families
//     the spoke has an instance for. Keyed by family guid for cheap
//     O(1) checks during render.
$instances = $DB->get_records('local_nucleusspoke_instance');
$pulledguids = [];
foreach ($instances as $i) {
    $famguid = $DB->get_field('local_nucleuscommon_family', 'guid', ['id' => $i->familyid]);
    $verguid = $DB->get_field('local_nucleuscommon_version', 'guid', ['id' => $i->versionid]);
    if ($famguid) {
        // ADR-021 v1.1 — pullnotes is JSON array of `{kind, detail}`,
        // null when the pull was clean. Decode here so the render
        // loop doesn't need to repeat it.
        $notes = [];
        if (!empty($i->pullnotes)) {
            $decoded = json_decode((string) $i->pullnotes, true);
            if (is_array($decoded)) {
                $notes = $decoded;
            }
        }
        $pulledguids[$famguid] = [
            'state' => $i->state,
            'versionguid' => $verguid,
            'localcourseid' => (int) $i->localcourseid,
            'notes' => $notes,
        ];
    }
}

echo $OUTPUT->header();

// ADR-021 v1.1 — pull-blocked dedicated page. Rendered at the top of
// the catalog so the operator sees actionable detail before the
// grid. Falls through to the normal grid below so they can also
// pick a different version / family in the same flow.
if (isset($blockedpayload, $blockedfamily, $blockedversion)) {
    $blockers = is_array($blockedpayload['blockers'] ?? null) ? $blockedpayload['blockers'] : [];
    $modstatus = is_array($blockedpayload['mod_status'] ?? null) ? $blockedpayload['mod_status'] : [];
    $bnotes = is_array($blockedpayload['notes'] ?? null) ? $blockedpayload['notes'] : [];

    echo '<div class="nfcat-blocked-card">';
    echo '<div class="nfcat-blocked-head">';
    echo '<div class="nfcat-blocked-eyebrow">'
        . s(get_string('catalog_blocked_eyebrow', 'local_nucleusspoke')) . '</div>';
    echo '<div class="nfcat-blocked-title mono">'
        . s($blockedfamily['slug']) . ' '
        . '<span class="nfcat-blocked-vchip">v' . s($blockedversion['versionnumber']) . '</span>'
        . '</div>';
    echo '<div class="nfcat-blocked-sub">'
        . s(get_string('catalog_blocked_sub', 'local_nucleusspoke')) . '</div>';
    echo '</div>';

    if ($modstatus) {
        echo '<table class="nfcat-mod-table"><thead><tr>'
            . '<th class="nfcat-mod-icon-col"></th>'
            . '<th>' . s(get_string('catalog_mod_plugin_col', 'local_nucleusspoke')) . '</th>'
            . '<th class="nfcat-mod-version-col">'
                . s(get_string('catalog_mod_manifest_col', 'local_nucleusspoke')) . '</th>'
            . '<th class="nfcat-mod-version-col">'
                . s(get_string('catalog_mod_local_col', 'local_nucleusspoke')) . '</th>'
            . '</tr></thead><tbody>';
        foreach ($modstatus as $m) {
            $state = (string) ($m['state'] ?? 'present');
            $name = (string) ($m['name'] ?? '');
            $expected = (int) ($m['expected_version'] ?? 0);
            $local = $m['spoke_version'] ?? null;
            $icon = $state === 'present' ? '✓' : ($state === 'missing' ? '✗' : '!');
            echo '<tr class="nfcat-mod-row nfcat-mod-' . s($state) . '">';
            echo '<td class="nfcat-mod-icon">' . $icon . '</td>';
            echo '<td class="mono">mod_' . s($name) . '</td>';
            echo '<td class="mono">' . ($expected > 0 ? 'v' . $expected : '—') . '</td>';
            if ($state === 'missing') {
                echo '<td class="mono nfcat-missing-text">'
                    . s(get_string('catalog_mod_notinstalled', 'local_nucleusspoke')) . '</td>';
            } else {
                echo '<td class="mono">v' . (int) $local . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    if ($blockers) {
        echo '<div class="nfcat-blocked-section">';
        echo '<div class="nfcat-blocked-label">'
            . s(get_string('catalog_blocked_remediation', 'local_nucleusspoke')) . '</div>';
        echo '<ul class="nfcat-blocker-list">';
        foreach ($blockers as $b) {
            $detail = (string) ($b['detail'] ?? '');
            $remedy = (string) ($b['remediation'] ?? '');
            echo '<li><div>' . s($detail) . '</div>';
            if ($remedy !== '') {
                echo '<div class="nfcat-remedy">' . s($remedy) . '</div>';
            }
            echo '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }

    if ($bnotes) {
        echo '<div class="nfcat-blocked-section">';
        echo '<div class="nfcat-blocked-label">'
            . s(get_string('catalog_blocked_notes', 'local_nucleusspoke')) . '</div>';
        echo '<ul class="nfcat-note-list">';
        foreach ($bnotes as $n) {
            echo '<li>' . s((string) $n) . '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }

    // Retry button — same query string the operator originally clicked.
    $retryurl = new moodle_url($pageurl, [
        'familyguid' => $blockedfamily['guid'],
        'versionguid' => $blockedversion['guid'],
        'sesskey' => sesskey(),
    ]);
    echo '<div class="nfcat-blocked-actions">';
    echo html_writer::link($retryurl,
        '<i class="fa fa-redo" aria-hidden="true"></i> '
        . s(get_string('catalog_blocked_retry', 'local_nucleusspoke')),
        ['class' => 'nfcat-blocked-retry']);
    echo html_writer::link($pageurl,
        s(get_string('catalog_blocked_back', 'local_nucleusspoke')),
        ['class' => 'nfcat-blocked-back']);
    echo '</div>';

    echo '</div>';
}

if ($catalogerror !== null) {
    echo $OUTPUT->notification(
        get_string('catalog_hubunreachable', 'local_nucleusspoke', s($catalogerror)),
        \core\output\notification::NOTIFY_ERROR
    );
    echo $OUTPUT->footer();
    exit;
}

if (!$catalog) {
    echo html_writer::div(
        get_string('catalog_empty', 'local_nucleusspoke'),
        'box generalbox p-4 text-center'
    );
    echo $OUTPUT->footer();
    exit;
}

$introkey = count($catalog) === 1 ? 'catalog_intro_one' : 'catalog_intro_many';
echo html_writer::tag('p',
    get_string($introkey, 'local_nucleusspoke', count($catalog)),
    ['class' => 'text-muted']);

// Catalog grid — one card per family. Inline CSS keeps the page
// self-contained; styles are scoped via `.nfcat-` prefix.
echo <<<'CSS'
<style>
.nfcat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 380px));
  gap: 14px;
  margin-top: 16px;
}
.nfcat-card {
  border: 1px solid #e1e4e8;
  border-radius: 6px;
  padding: 14px 16px;
  background: #fff;
  color: #1d2326;
  font-size: 13px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  transition: border-color 0.12s ease;
}
.nfcat-card:hover { border-color: #c8ccd0; }
.nfcat-title {
  margin: 0;
  font-size: 15px;
  font-weight: 600;
  color: #1d2326;
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 10px;
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
}
.nfcat-title .nfcat-slug { color: #d97706; }
.nfcat-guid {
  color: #8a8d8a;
  font-weight: 400;
  font-size: 11px;
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
}
.nfcat-meta { color: #6a6e72; font-size: 12px; }
.nfcat-version-line {
  display: flex; align-items: center; gap: 8px;
  padding: 8px 0;
  border-top: 1px solid #f0f2f4;
  border-bottom: 1px solid #f0f2f4;
}
.nfcat-vnum {
  color: #d97706;
  font-weight: 600;
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
  font-size: 13px;
}
.nfcat-vsev {
  color: #6a6e72;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.nfcat-notes {
  margin: 0;
  padding: 8px 10px;
  font-size: 11.5px;
  color: #4a5258;
  background: #f8f9fa;
  border: 1px solid #e1e4e8;
  border-radius: 4px;
  white-space: pre-wrap;
  max-height: 70px;
  overflow: auto;
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
}
.nfcat-actions { margin-top: auto; display: flex; gap: 8px; align-items: center; }
.nfcat-pull {
  flex: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 7px 12px;
  background: #d97706;
  color: #fff;
  border: 1px solid #d97706;
  border-radius: 4px;
  text-decoration: none;
  font-weight: 500;
  font-size: 12.5px;
}
.nfcat-pull:hover { background: #b45309; border-color: #b45309; color: #fff; text-decoration: none; }
.nfcat-pulled-chip {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 10px;
  background: #ecfdf5;
  color: #047857;
  border: 1px solid #a7f3d0;
  border-radius: 12px;
  font-size: 11.5px;
  font-weight: 500;
}
/* ADR-021 v1.1 — Tier C notes badge. <details>/<summary> gives a
   click-to-expand affordance with no JS. Hovering the chip shows
   the count summary in a native tooltip. */
.nfcat-notes { display: inline-block; margin-right: 4px; }
.nfcat-notes-chip {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 10px;
  background: #eff6ff;
  color: #1e40af;
  border: 1px solid #bfdbfe;
  border-radius: 12px;
  font-size: 11.5px;
  font-weight: 500;
  cursor: pointer;
  list-style: none;
}
.nfcat-notes-chip::-webkit-details-marker { display: none; }
.nfcat-notes[open] .nfcat-notes-chip { background: #dbeafe; }
.nfcat-notes-list {
  margin: 6px 0 0;
  padding: 8px 10px 8px 22px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  font-size: 12px;
  color: #1d2326;
  line-height: 1.5;
}
.nfcat-notes-list li { margin-bottom: 4px; }
.nfcat-notes-list li:last-child { margin-bottom: 0; }
.nfcat-notes-kind {
  display: inline-block;
  padding: 1px 6px;
  background: #e2e8f0;
  color: #475569;
  border-radius: 3px;
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
  font-size: 10.5px;
  margin-right: 4px;
}
/* ADR-021 v1.1 — pull-blocked compatibility report card. Sits above
   the catalog grid when a pull was just refused. */
.nfcat-blocked-card {
  border: 1px solid #fecaca;
  background: #fef2f2;
  border-radius: 6px;
  padding: 16px 18px;
  margin-bottom: 18px;
}
.nfcat-blocked-head { margin-bottom: 12px; }
.nfcat-blocked-eyebrow {
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
  font-size: 11px;
  letter-spacing: 0.12em;
  color: #b91c1c;
  text-transform: uppercase;
  margin-bottom: 4px;
}
.nfcat-blocked-title {
  font-size: 16px;
  font-weight: 600;
  color: #1d2326;
}
.nfcat-blocked-vchip {
  color: #d97706;
  font-weight: 500;
  margin-left: 4px;
}
.nfcat-blocked-sub {
  font-size: 13px;
  color: #4b5563;
  margin-top: 6px;
  line-height: 1.5;
}
.nfcat-mod-table {
  width: 100%;
  border-collapse: collapse;
  margin: 12px 0;
  background: #fff;
  border: 1px solid #fecaca;
  border-radius: 4px;
  overflow: hidden;
  font-size: 13px;
}
.nfcat-mod-table th,
.nfcat-mod-table td {
  padding: 7px 10px;
  border-bottom: 1px solid #fee2e2;
  text-align: left;
  vertical-align: middle;
}
.nfcat-mod-table th {
  font-size: 11px;
  letter-spacing: 0.06em;
  color: #6b7280;
  font-weight: 500;
  text-transform: uppercase;
  background: #fff7f7;
}
.nfcat-mod-table tr:last-child td { border-bottom: 0; }
.nfcat-mod-icon-col { width: 22px; }
.nfcat-mod-version-col { width: 110px; }
.nfcat-mod-icon {
  text-align: center;
  font-weight: 700;
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
}
.nfcat-mod-row.nfcat-mod-missing { background: #fff1f1; }
.nfcat-mod-row.nfcat-mod-missing .nfcat-mod-icon { color: #b91c1c; }
.nfcat-mod-row.nfcat-mod-older { background: #fffbeb; }
.nfcat-mod-row.nfcat-mod-older .nfcat-mod-icon { color: #b45309; }
.nfcat-mod-row.nfcat-mod-present .nfcat-mod-icon { color: #047857; }
.nfcat-missing-text { color: #b91c1c; }
.nfcat-blocked-section { margin-top: 14px; }
.nfcat-blocked-label {
  font-size: 11px;
  letter-spacing: 0.08em;
  color: #6b7280;
  text-transform: uppercase;
  margin-bottom: 6px;
  font-weight: 500;
}
.nfcat-blocker-list,
.nfcat-note-list {
  margin: 0;
  padding-left: 20px;
  font-size: 13px;
  color: #1d2326;
  line-height: 1.5;
}
.nfcat-blocker-list li,
.nfcat-note-list li { margin-bottom: 6px; }
.nfcat-blocker-list li:last-child,
.nfcat-note-list li:last-child { margin-bottom: 0; }
.nfcat-remedy {
  margin-top: 4px;
  color: #4b5563;
  font-size: 12.5px;
}
.nfcat-blocked-actions {
  margin-top: 16px;
  display: flex;
  gap: 10px;
  align-items: center;
}
.nfcat-blocked-retry {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 14px;
  background: #d97706;
  color: #fff;
  border: 1px solid #d97706;
  border-radius: 4px;
  text-decoration: none;
  font-weight: 500;
  font-size: 12.5px;
}
.nfcat-blocked-retry:hover { background: #b45309; border-color: #b45309; color: #fff; text-decoration: none; }
.nfcat-blocked-back { color: #4b5563; font-size: 12.5px; text-decoration: underline; }
.nfcat-update-chip {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 10px;
  background: #fffbeb;
  color: #92400e;
  border: 1px solid #fde68a;
  border-radius: 12px;
  font-size: 11.5px;
  font-weight: 500;
}
.nfcat-empty { color: #8a8d8a; font-style: italic; padding: 6px 0; }
</style>
CSS;

echo '<div class="nfcat-grid">';

usort($catalog, fn($a, $b) => strcmp((string) $a['slug'], (string) $b['slug']));

foreach ($catalog as $family) {
    $versions = $family['versions'] ?? [];
    $latest = empty($versions) ? null : end($versions);
    reset($versions);

    echo '<div class="nfcat-card">';

    echo '<h3 class="nfcat-title">'
        . '<span class="nfcat-slug">' . s($family['slug']) . '</span>'
        . '<span class="nfcat-guid">' . substr((string) $family['guid'], 0, 8) . '</span>'
        . '</h3>';

    $hubcourse = (string) ($family['hubcoursefullname'] ?? '');
    if ($hubcourse !== '') {
        echo html_writer::tag('div',
            get_string('catalog_hubcourse', 'local_nucleusspoke', s($hubcourse)),
            ['class' => 'nfcat-meta']);
    }

    if ($latest) {
        echo '<div class="nfcat-version-line">'
            . '<span class="nfcat-vnum">v' . s($latest['versionnumber']) . '</span>'
            . '<span class="nfcat-vsev">' . s($latest['severity']) . '</span>'
            . '<span class="nfcat-meta" style="margin-left: auto">'
                . get_string('catalog_publishedwhen', 'local_nucleusspoke',
                    format_time(time() - (int) $latest['timepublished']))
            . '</span>'
            . '</div>';
        if (!empty($latest['releasenotes'])) {
            echo html_writer::tag('pre',
                s((string) $latest['releasenotes']),
                ['class' => 'nfcat-notes']);
        }
    } else {
        echo html_writer::div(
            get_string('catalog_noversions', 'local_nucleusspoke'),
            'nfcat-empty'
        );
    }

    echo '<div class="nfcat-actions">';
    if (isset($pulledguids[$family['guid']])) {
        $local = $pulledguids[$family['guid']];
        $sameversion = $latest && $local['versionguid'] === $latest['guid'];
        // ADR-021 v1.1 — render Tier C notes badge for any pulled
        // instance with non-empty pullnotes. Hover shows the full
        // list; click expands inline (details/summary). Counts cap
        // at 99 to keep the chip stable; the expanded list is
        // unbounded.
        $notecount = isset($local['notes']) ? count($local['notes']) : 0;
        if ($notecount > 0) {
            $countlabel = $notecount > 99 ? '99+' : (string) $notecount;
            echo '<details class="nfcat-notes">'
                . '<summary class="nfcat-notes-chip" '
                . 'title="' . s(get_string('catalog_notes_summary', 'local_nucleusspoke', $countlabel)) . '">'
                . '<i class="fa fa-info-circle" aria-hidden="true"></i> '
                . s(get_string('catalog_notes_chip', 'local_nucleusspoke', $countlabel))
                . '</summary>'
                . '<ul class="nfcat-notes-list">';
            foreach ($local['notes'] as $note) {
                $kind = isset($note['kind']) ? (string) $note['kind'] : 'note';
                $detail = isset($note['detail']) ? (string) $note['detail'] : '';
                echo '<li>'
                    . '<span class="nfcat-notes-kind">' . s($kind) . '</span> '
                    . s($detail)
                    . '</li>';
            }
            echo '</ul></details>';
        }
        if ($sameversion) {
            echo '<span class="nfcat-pulled-chip">'
                . '<i class="fa fa-check" aria-hidden="true"></i>'
                . get_string('catalog_uptodate', 'local_nucleusspoke')
                . '</span>';
        } else {
            echo '<span class="nfcat-update-chip">'
                . '<i class="fa fa-arrow-up" aria-hidden="true"></i>'
                . get_string('catalog_updatable', 'local_nucleusspoke')
                . '</span>';
            if ($latest) {
                $pullurl = new moodle_url($pageurl, [
                    'familyguid' => $family['guid'],
                    'versionguid' => $latest['guid'],
                    'sesskey' => sesskey(),
                ]);
                echo html_writer::link($pullurl,
                    '<i class="fa fa-download" aria-hidden="true"></i>'
                    . get_string('catalog_pulllatest', 'local_nucleusspoke',
                        s($latest['versionnumber'])),
                    ['class' => 'nfcat-pull']);
            }
        }
    } else if ($latest) {
        $pullurl = new moodle_url($pageurl, [
            'familyguid' => $family['guid'],
            'versionguid' => $latest['guid'],
            'sesskey' => sesskey(),
        ]);
        echo html_writer::link($pullurl,
            '<i class="fa fa-download" aria-hidden="true"></i>'
            . get_string('catalog_pulllatest', 'local_nucleusspoke',
                s($latest['versionnumber'])),
            ['class' => 'nfcat-pull']);
    }
    echo '</div>';

    echo '</div>'; // .nfcat-card
}

echo '</div>'; // .nfcat-grid

echo $OUTPUT->footer();
