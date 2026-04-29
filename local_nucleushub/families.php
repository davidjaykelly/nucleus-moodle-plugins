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
 * Hub-side families dashboard (ADR-014 Phase 1, B1).
 *
 * Top-level entry point listing every course family this hub
 * publishes. Sister page to the spoke's versions.php. Each card
 * shows the headline state — slug, last version, pending changes,
 * working hub course — and routes through to publish.php for the
 * actual version-creation flow.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

require(__DIR__ . '/../../../config.php');

$pageurl = new moodle_url('/local/nucleushub/families.php');
$PAGE->set_url($pageurl);
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('families_title', 'local_nucleushub'));
$PAGE->set_heading(get_string('families_title', 'local_nucleushub'));
$PAGE->set_pagelayout('admin');

require_login();
require_capability('local/nucleushub:publish', context_system::instance());

// Fetch families with their draft + last-version + course info in
// one trip. Outer joins: a family with no draft (orphaned) or no
// published version still surfaces — surfacing is the whole point
// of the dashboard. Version count is a separate aggregate query.
$rows = $DB->get_records_sql(
    "SELECT f.id AS familyid, f.slug, f.guid, f.timecreated AS familytimecreated,
            d.hubcourseid, d.lastpublishversionid, d.pendingchangecount, d.timelastedit,
            c.fullname AS coursefullname, c.shortname AS courseshortname,
            v.versionnumber, v.severity, v.timepublished
       FROM {local_nucleuscommon_family} f
  LEFT JOIN {local_nucleushub_draft} d ON d.familyid = f.id
  LEFT JOIN {course} c ON c.id = d.hubcourseid
  LEFT JOIN {local_nucleuscommon_version} v ON v.id = d.lastpublishversionid
   ORDER BY f.slug ASC"
);

// Per-family version count.
$versioncounts = $DB->get_records_sql(
    "SELECT familyid, COUNT(*) AS n
       FROM {local_nucleuscommon_version}
   GROUP BY familyid"
);
$countmap = [];
foreach ($versioncounts as $r) {
    $countmap[(int) $r->familyid] = (int) $r->n;
}

// Active spoke count — same number for every family at present
// (every spoke gets fan-out for every published family). Hub admin
// can use this as a coarse "how many places will see this" gauge.
$spokecount = (int) $DB->count_records('local_nucleushub_spokes', ['status' => 'active']);

echo $OUTPUT->header();
?>
<style>
.nfh-toolbar {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 18px;
  flex-wrap: wrap;
}
.nfh-toolbar-meta { color: #6a6e72; font-size: 13px; }
.nfh-section {
  margin-top: 22px;
  margin-bottom: 8px;
}
.nfh-section-title {
  font-size: 14px;
  font-weight: 600;
  color: #1d2326;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  margin: 0 0 4px 0;
  padding-bottom: 6px;
  border-bottom: 1px solid #e1e4e8;
  display: flex;
  align-items: center;
  gap: 10px;
}
.nfh-section-count {
  font-size: 11px;
  font-weight: 500;
  background: #f0f2f4;
  color: #6a6e72;
  border-radius: 10px;
  padding: 1px 9px;
  letter-spacing: 0;
  text-transform: none;
}
.nfh-empty {
  border: 1px dashed #e1e4e8;
  border-radius: 6px;
  padding: 24px 18px;
  text-align: center;
  color: #4a5258;
  background: #f8f9fa;
}
.nfh-empty .nfh-empty-title {
  font-size: 14px;
  font-weight: 600;
  color: #1d2326;
  margin-bottom: 6px;
}
.nfh-empty .nfh-empty-hint {
  font-size: 13px;
  color: #6a6e72;
}

.nfh-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
  gap: 12px;
}
.nfh-card {
  border: 1px solid #e1e4e8;
  border-radius: 5px;
  padding: 12px 14px;
  background: #fff;
  display: flex;
  flex-direction: column;
  gap: 8px;
  transition: border-color 0.12s ease;
}
.nfh-card:hover { border-color: #c8ccd0; }
.nfh-card-head {
  display: flex;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
}
.nfh-slug {
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
  font-size: 14px;
  font-weight: 600;
  color: #d97706;
}
.nfh-guid {
  color: #6a6e72;
  font-weight: 400;
  font-size: 11px;
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
}
.nfh-version {
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
  font-size: 13px;
  font-weight: 600;
  color: #1d2326;
}
.nfh-sev {
  font-size: 10.5px;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: #6a6e72;
}
.nfh-pill {
  background: #f8f9fa;
  border: 1px solid #e1e4e8;
  border-radius: 10px;
  padding: 2px 9px;
  font-size: 11px;
  color: #4a5258;
}
.nfh-pill-warn {
  background: #fffbeb;
  border-color: #fde68a;
  color: #92400e;
}
.nfh-pill-muted {
  background: #f3f4f6;
  color: #6a6e72;
}
.nfh-pill-ok {
  background: #ecfdf5;
  border-color: #a7f3d0;
  color: #047857;
}
.nfh-meta {
  font-size: 12px;
  color: #6a6e72;
}
.nfh-meta a { color: #1d4ed8; text-decoration: none; }
.nfh-meta a:hover { text-decoration: underline; }
.nfh-card-actions {
  margin-top: auto;
  display: flex;
  gap: 8px;
  align-items: center;
}
.nfh-card-actions .nfh-publish {
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
.nfh-card-actions .nfh-publish:hover { background: #b45309; border-color: #b45309; color: #fff; text-decoration: none; }
.nfh-card-actions .nfh-secondary {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 12px;
  background: #fff;
  color: #1d2326;
  border: 1px solid #e1e4e8;
  border-radius: 4px;
  text-decoration: none;
  font-size: 12.5px;
}
.nfh-card-actions .nfh-secondary:hover { background: #f3f4f6; text-decoration: none; }
</style>
<?php

// Toolbar — hint about how to add new families. The workflow is
// per-course (open the course, status bar offers "+ Add to
// federation"), so this is a pointer rather than a button.
echo '<div class="nfh-toolbar">';
echo html_writer::tag('div',
    s(get_string('families_toolbar_hint', 'local_nucleushub')),
    ['class' => 'nfh-toolbar-meta']);
echo '</div>';

if (!$rows) {
    echo '<div class="nfh-empty">';
    echo '<div class="nfh-empty-title">'
        . s(get_string('families_empty_title', 'local_nucleushub')) . '</div>';
    echo '<div class="nfh-empty-hint">'
        . s(get_string('families_empty_hint', 'local_nucleushub')) . '</div>';
    echo '</div>';
} else {
    echo '<div class="nfh-section">';
    echo '<h3 class="nfh-section-title">'
        . s(get_string('families_heading', 'local_nucleushub'))
        . '<span class="nfh-section-count">' . count($rows) . '</span>'
        . '</h3>';
    echo '</div>';

    echo '<div class="nfh-grid">';
    foreach ($rows as $r) {
        $versions = $countmap[(int) $r->familyid] ?? 0;
        $pending = (int) ($r->pendingchangecount ?? 0);
        $hasdraft = (int) ($r->hubcourseid ?? 0) > 0;

        echo '<div class="nfh-card">';

        // Head: slug · GUID · last-published version pill · pending pill.
        echo '<div class="nfh-card-head">';
        echo '<span class="nfh-slug">' . s($r->slug) . '</span>';
        echo '<span class="nfh-guid">· ' . substr((string) $r->guid, 0, 8) . '</span>';
        if ($r->versionnumber) {
            echo '<span class="nfh-pill">v' . s($r->versionnumber)
                . ' · ' . format_time(time() - (int) $r->timepublished) . ' ago</span>';
        } else {
            echo '<span class="nfh-pill nfh-pill-muted">'
                . s(get_string('familyneverpublished_short', 'local_nucleushub')) . '</span>';
        }
        if ($pending > 0) {
            echo '<span class="nfh-pill nfh-pill-warn">' . $pending . ' pending</span>';
        } else if ($r->versionnumber) {
            echo '<span class="nfh-pill nfh-pill-ok">clean</span>';
        }
        echo '</div>';

        // Meta line: working course (if any), version count, age.
        echo '<div class="nfh-meta">';
        if ($hasdraft && $r->coursefullname) {
            echo s(get_string('families_workingcourse', 'local_nucleushub')) . ': '
                . html_writer::link(
                    new moodle_url('/course/view.php', ['id' => $r->hubcourseid]),
                    format_string($r->coursefullname)
                );
        } else {
            echo '<span style="color:#b91c1c">'
                . s(get_string('families_orphan', 'local_nucleushub')) . '</span>';
        }
        echo '</div>';
        echo '<div class="nfh-meta">'
            . $versions . ' '
            . s(get_string('families_versions_published', 'local_nucleushub'))
            . ' · '
            . format_time(time() - (int) $r->familytimecreated)
            . ' ' . s(get_string('families_old', 'local_nucleushub'))
            . '</div>';

        // Actions — primary publish CTA (only if there's a draft course),
        // secondary jump-to-course.
        echo '<div class="nfh-card-actions">';
        if ($hasdraft) {
            echo html_writer::link(
                new moodle_url('/local/nucleushub/publish.php', ['id' => $r->hubcourseid]),
                '<i class="fa fa-upload" aria-hidden="true"></i> '
                    . s(get_string('publishversion', 'local_nucleushub')),
                ['class' => 'nfh-publish']
            );
            echo html_writer::link(
                new moodle_url('/course/view.php', ['id' => $r->hubcourseid]),
                '<i class="fa fa-external-link-alt" aria-hidden="true"></i> '
                    . s(get_string('families_opencourse', 'local_nucleushub')),
                ['class' => 'nfh-secondary']
            );
        }
        echo '</div>';

        echo '</div>'; // .nfh-card
    }
    echo '</div>'; // .nfh-grid
}

// Footer note: spoke reach.
if ($rows) {
    $reachstr = $spokecount > 0
        ? get_string('families_reach', 'local_nucleushub', $spokecount)
        : get_string('families_reach_none', 'local_nucleushub');
    echo html_writer::tag('p', $reachstr,
        ['class' => 'nfh-toolbar-meta', 'style' => 'margin-top: 18px']);
}

echo $OUTPUT->footer();
