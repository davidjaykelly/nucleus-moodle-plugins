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
 * Hub-side spokes roster (ADR-014 Phase 1, B1).
 *
 * Read-only list of spokes registered with this hub. Sister page
 * to families.php — shows the receiving end of every publish.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

require(__DIR__ . '/../../../config.php');

$pageurl = new moodle_url('/local/nucleushub/spokes.php');
$PAGE->set_url($pageurl);
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('spokes_title', 'local_nucleushub'));
$PAGE->set_heading(get_string('spokes_title', 'local_nucleushub'));
$PAGE->set_pagelayout('admin');

require_login();
require_capability('local/nucleushub:publish', context_system::instance());

$spokes = $DB->get_records('local_nucleushub_spokes', null, 'timecreated DESC');

echo $OUTPUT->header();
?>
<style>
.nfs-toolbar {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 18px;
  flex-wrap: wrap;
}
.nfs-toolbar-meta { color: #6a6e72; font-size: 13px; }
.nfs-section {
  margin-top: 18px;
  margin-bottom: 8px;
}
.nfs-section-title {
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
.nfs-section-count {
  font-size: 11px;
  font-weight: 500;
  background: #f0f2f4;
  color: #6a6e72;
  border-radius: 10px;
  padding: 1px 9px;
  letter-spacing: 0;
  text-transform: none;
}
.nfs-empty {
  border: 1px dashed #e1e4e8;
  border-radius: 6px;
  padding: 24px 18px;
  text-align: center;
  color: #4a5258;
  background: #f8f9fa;
}
.nfs-empty-title {
  font-size: 14px;
  font-weight: 600;
  color: #1d2326;
  margin-bottom: 6px;
}
.nfs-empty-hint { font-size: 13px; color: #6a6e72; }

.nfs-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
  gap: 12px;
}
.nfs-card {
  border: 1px solid #e1e4e8;
  border-radius: 5px;
  padding: 12px 14px;
  background: #fff;
  display: flex;
  flex-direction: column;
  gap: 8px;
  transition: border-color 0.12s ease;
}
.nfs-card:hover { border-color: #c8ccd0; }
.nfs-card-head {
  display: flex;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
}
.nfs-name {
  font-size: 14px;
  font-weight: 600;
  color: #1d2326;
}
.nfs-status {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 2px 9px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: 500;
}
.nfs-status-active { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
.nfs-status-suspended { background: #f3f4f6; color: #4a5258; border: 1px solid #d1d5db; }
.nfs-wwwroot {
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
  font-size: 12px;
  color: #1d4ed8;
  word-break: break-all;
}
.nfs-wwwroot a { color: inherit; text-decoration: none; }
.nfs-wwwroot a:hover { text-decoration: underline; }
.nfs-meta { font-size: 12px; color: #6a6e72; }
.nfs-meta-row { padding: 2px 0; }
.nfs-meta-key {
  color: #8a8d8a;
  display: inline-block;
  min-width: 110px;
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
  font-size: 11px;
}
.nfs-cpid {
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
  color: #6a6e72;
  font-size: 11px;
}
.nfs-heartbeat-stale { color: #b45309; }
.nfs-heartbeat-fresh { color: #047857; }
</style>
<?php

$totalfamilies = (int) $DB->count_records('local_nucleuscommon_family');

echo '<div class="nfs-toolbar">';
echo html_writer::tag('div',
    s(get_string('spokes_toolbar_hint', 'local_nucleushub')),
    ['class' => 'nfs-toolbar-meta']);
echo '</div>';

if (!$spokes) {
    echo '<div class="nfs-empty">';
    echo '<div class="nfs-empty-title">'
        . s(get_string('spokes_empty_title', 'local_nucleushub')) . '</div>';
    echo '<div class="nfs-empty-hint">'
        . s(get_string('spokes_empty_hint', 'local_nucleushub')) . '</div>';
    echo '</div>';
} else {
    echo '<div class="nfs-section">';
    echo '<h3 class="nfs-section-title">'
        . s(get_string('spokes_heading', 'local_nucleushub'))
        . '<span class="nfs-section-count">' . count($spokes) . '</span>'
        . '</h3>';
    echo '</div>';

    $now = time();
    echo '<div class="nfs-grid">';
    foreach ($spokes as $s) {
        $statusclass = $s->status === 'active' ? 'nfs-status-active' : 'nfs-status-suspended';

        // Heartbeat freshness — anything within 24h is "fresh", older
        // is "stale". `null` means we've never seen the spoke check
        // in (registered but no calls yet).
        $heartbeatcell = '';
        if (empty($s->timelastheartbeat)) {
            $heartbeatcell = html_writer::tag('span',
                s(get_string('spokes_heartbeat_never', 'local_nucleushub')),
                ['class' => 'nfs-heartbeat-stale']);
        } else {
            $age = $now - (int) $s->timelastheartbeat;
            $cls = $age < 86400 ? 'nfs-heartbeat-fresh' : 'nfs-heartbeat-stale';
            $heartbeatcell = html_writer::tag('span',
                format_time($age) . ' ' . s(get_string('spokes_ago', 'local_nucleushub')),
                ['class' => $cls]);
        }

        echo '<div class="nfs-card">';
        echo '<div class="nfs-card-head">';
        echo '<span class="nfs-name">' . s($s->name) . '</span>';
        echo '<span class="nfs-status ' . $statusclass . '">' . s($s->status) . '</span>';
        echo '</div>';
        echo '<div class="nfs-wwwroot">'
            . html_writer::link($s->wwwroot, s($s->wwwroot), ['target' => '_blank', 'rel' => 'noopener'])
            . '</div>';
        echo '<div class="nfs-meta">';
        echo '<div class="nfs-meta-row">'
            . '<span class="nfs-meta-key">'
            . s(get_string('spokes_registered', 'local_nucleushub')) . ':</span> '
            . userdate((int) $s->timecreated, get_string('strftimedatetimeshort', 'langconfig'))
            . '</div>';
        echo '<div class="nfs-meta-row">'
            . '<span class="nfs-meta-key">'
            . s(get_string('spokes_lastseen', 'local_nucleushub')) . ':</span> '
            . $heartbeatcell
            . '</div>';
        if (!empty($s->cpspokeid)) {
            echo '<div class="nfs-meta-row">'
                . '<span class="nfs-meta-key">'
                . s(get_string('spokes_cpid', 'local_nucleushub')) . ':</span> '
                . '<span class="nfs-cpid">' . s($s->cpspokeid) . '</span>'
                . '</div>';
        }
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';

    // Footer summary — coarse "publish reach" gauge.
    if ($totalfamilies > 0) {
        echo html_writer::tag('p',
            get_string('spokes_reach_summary', 'local_nucleushub', (object) [
                'spokes' => count($spokes),
                'families' => $totalfamilies,
            ]),
            ['class' => 'nfs-toolbar-meta', 'style' => 'margin-top: 18px']);
    }
}

echo $OUTPUT->footer();
