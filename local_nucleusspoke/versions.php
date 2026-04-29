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
 * Course versions dashboard for a spoke (ADR-014 Phase 1).
 *
 * Three sections: pending notifications (inline Pull), active
 * instances (what we've already pulled), history (resolved /
 * dismissed). Pull action runs the same `puller::pull` path the
 * CLI and the control-plane WS both use.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

require(__DIR__ . '/../../../config.php');

use local_nucleusspoke\version\puller;
use local_nucleusspoke\version\notifications;
use local_nucleusspoke\version\promoter;
use local_nucleusspoke\version\lifecycle;

$pageurl = new moodle_url('/local/nucleusspoke/versions.php');
$PAGE->set_url($pageurl);
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('versions_title', 'local_nucleusspoke'));
$PAGE->set_heading(get_string('versions_title', 'local_nucleusspoke'));
$PAGE->set_pagelayout('admin');

require_login();
require_capability('local/nucleusspoke:pull', context_system::instance());

// ---------- Pull action ----------

$pullid = optional_param('pull', 0, PARAM_INT);
$pullstaging = optional_param('staging', 0, PARAM_BOOL);
if ($pullid) {
    require_sesskey();

    $notif = $DB->get_record('local_nucleusspoke_notification',
        ['id' => $pullid], '*', MUST_EXIST);
    $family = $DB->get_record('local_nucleuscommon_family',
        ['id' => $notif->familyid], '*', MUST_EXIST);
    $version = $DB->get_record('local_nucleuscommon_version',
        ['id' => $notif->versionid], '*', MUST_EXIST);

    try {
        $result = puller::pull(
            [
                'guid' => $family->guid,
                'slug' => $family->slug,
                'hubfederationid' => $family->hubfederationid,
            ],
            [
                'guid' => $version->guid,
                'versionnumber' => $version->versionnumber,
                'severity' => $version->severity,
                'snapshotref' => $version->snapshotref,
                'snapshothash' => $version->snapshothash,
                'hubcourseid' => (int) $version->hubcourseid,
                'timepublished' => (int) $version->timepublished,
                'releasenotes' => $version->releasenotes,
            ],
            1,
            (int) $USER->id,
            (bool) $pullstaging
        );
        $msgkey = $pullstaging ? 'pullstagingsuccess' : 'pullsuccess';
        redirect(
            $pageurl,
            get_string($msgkey, 'local_nucleusspoke', (object) [
                'slug' => $family->slug,
                'version' => $version->versionnumber,
                'courseid' => $result['localcourseid'],
            ]),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\Throwable $e) {
        redirect(
            $pageurl,
            get_string('pullfailure', 'local_nucleusspoke', $e->getMessage()),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

// ---------- Promote (staging → active) ----------

$promoteid = optional_param('promote', 0, PARAM_INT);
if ($promoteid) {
    require_sesskey();
    try {
        $row = promoter::promote_instance($promoteid, (int) $USER->id);
        redirect(
            $pageurl,
            get_string('promotesuccess', 'local_nucleusspoke', $row->localcourseid),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\Throwable $e) {
        redirect(
            $pageurl,
            $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

// ---------- Close / Reopen ----------

$lifecycleid = optional_param('lifecycle', 0, PARAM_INT);
$lifecycleaction = optional_param('lact', '', PARAM_ALPHA);
if ($lifecycleid && $lifecycleaction) {
    require_sesskey();
    try {
        if ($lifecycleaction === 'close') {
            $row = lifecycle::close_to_enrolment($lifecycleid);
            $msg = get_string('closesuccess', 'local_nucleusspoke');
        } else if ($lifecycleaction === 'reopen') {
            $row = lifecycle::reopen($lifecycleid);
            $msg = get_string('reopensuccess', 'local_nucleusspoke');
        } else {
            throw new \moodle_exception('invalidaction');
        }
        redirect($pageurl, $msg, null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (\Throwable $e) {
        redirect($pageurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

// ---------- Rollback (instance) ----------

$rollbackinstanceid = optional_param('rollback', 0, PARAM_INT);
if ($rollbackinstanceid) {
    require_sesskey();
    try {
        $instance = $DB->get_record('local_nucleusspoke_instance',
            ['id' => $rollbackinstanceid], '*', MUST_EXIST);
        $currentversion = $DB->get_record('local_nucleuscommon_version',
            ['id' => $instance->versionid], '*', MUST_EXIST);
        $family = $DB->get_record('local_nucleuscommon_family',
            ['id' => $instance->familyid], '*', MUST_EXIST);

        // Pick the most recent non-deprecated version for this family
        // that was published strictly before the current one.
        $target = $DB->get_record_sql(
            "SELECT * FROM {local_nucleuscommon_version}
              WHERE familyid = :fid
                AND deprecated = 0
                AND timepublished < :cur
              ORDER BY timepublished DESC
              LIMIT 1",
            ['fid' => $family->id, 'cur' => (int) $currentversion->timepublished]
        );
        if (!$target) {
            throw new \moodle_exception('rollback_notarget', 'local_nucleusspoke');
        }

        $result = puller::pull(
            [
                'guid' => $family->guid,
                'slug' => $family->slug,
                'hubfederationid' => $family->hubfederationid,
            ],
            [
                'guid' => $target->guid,
                'versionnumber' => $target->versionnumber,
                'severity' => $target->severity,
                'snapshotref' => $target->snapshotref,
                'snapshothash' => $target->snapshothash,
                'hubcourseid' => (int) $target->hubcourseid,
                'timepublished' => (int) $target->timepublished,
                'releasenotes' => $target->releasenotes,
            ],
            1,
            (int) $USER->id
        );
        redirect(
            $pageurl,
            get_string('rollback_success', 'local_nucleusspoke', (object) [
                'slug' => $family->slug,
                'from' => $currentversion->versionnumber,
                'to' => $target->versionnumber,
                'courseid' => $result['localcourseid'],
            ]),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\Throwable $e) {
        redirect(
            $pageurl,
            get_string('rollback_failure', 'local_nucleusspoke', $e->getMessage()),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

// ---------- Snooze / Dismiss / Reactivate ----------

$actionid = optional_param('nid', 0, PARAM_INT);
$actionname = optional_param('act', '', PARAM_ALPHA);
if ($actionid && $actionname) {
    require_sesskey();
    try {
        $row = notifications::apply(
            $actionid,
            $actionname,
            null, // Default snooze window — future: take from param.
            (int) $USER->id
        );
        redirect(
            $pageurl,
            get_string('notification_' . $actionname . '_success', 'local_nucleusspoke'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\Throwable $e) {
        redirect(
            $pageurl,
            $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

// ---------- Render ----------

echo $OUTPUT->header();
?>
<style>
.nfv-toolbar {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 18px;
}
.nfv-toolbar .btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.nfv-section {
  margin-top: 22px;
  margin-bottom: 8px;
}
.nfv-section-title {
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
.nfv-section-count {
  font-size: 11px;
  font-weight: 500;
  background: #f0f2f4;
  color: #6a6e72;
  border-radius: 10px;
  padding: 1px 9px;
  letter-spacing: 0;
  text-transform: none;
}
.nfv-section-count.warn {
  background: #fffbeb;
  color: #92400e;
  border: 1px solid #fde68a;
}
.nfv-empty {
  color: #6a6e72;
  font-style: italic;
  padding: 14px 0;
}

/* Cards (pending + instances). */
.nfv-card {
  border: 1px solid #e1e4e8;
  border-radius: 5px;
  padding: 12px 14px;
  background: #fff;
  margin-bottom: 10px;
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: flex-start;
}
.nfv-card-body { flex: 1; min-width: 240px; }
.nfv-card-head {
  display: flex;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 6px;
}
.nfv-slug {
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
  font-size: 14px;
  font-weight: 600;
  color: #d97706;
}
.nfv-version {
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
  font-size: 13px;
  color: #1d2326;
  font-weight: 600;
}
.nfv-sev {
  font-size: 10.5px;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: #6a6e72;
}
.nfv-meta {
  font-size: 12px;
  color: #6a6e72;
}
.nfv-meta a { color: #1d4ed8; text-decoration: none; }
.nfv-meta a:hover { text-decoration: underline; }
.nfv-notes {
  margin: 6px 0 0 0;
  padding: 7px 10px;
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
  font-size: 11.5px;
  color: #4a5258;
  background: #f8f9fa;
  border: 1px solid #e1e4e8;
  border-radius: 3px;
  white-space: pre-wrap;
  max-height: 60px;
  overflow: auto;
}
.nfv-card-actions {
  display: flex;
  flex-direction: column;
  gap: 6px;
  align-items: stretch;
  min-width: 160px;
}
.nfv-card-actions .btn { width: 100%; }
.nfv-card-actions .btn-link { padding: 4px 8px; font-size: 12px; }

/* State badges (instance). */
.nfv-state {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 9px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: 500;
}
.nfv-state-active { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
.nfv-state-staging { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.nfv-state-closed { background: #f3f4f6; color: #4a5258; border: 1px solid #d1d5db; }
.nfv-state-archived { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
.nfv-deprecated {
  display: inline-flex; align-items: center; gap: 4px;
  background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca;
  border-radius: 10px; padding: 2px 9px;
  font-size: 11px; font-weight: 500;
}

/* Family card tally + expand toggle for older versions. */
.nfv-card-tally {
  margin-left: auto;
  font-size: 11px;
  color: #6a6e72;
  background: #f0f2f4;
  padding: 2px 9px;
  border-radius: 10px;
}
.nfv-older {
  margin-top: 10px;
  border-top: 1px dashed #e1e4e8;
  padding-top: 8px;
}
.nfv-older-toggle {
  cursor: pointer;
  font-size: 12px;
  color: #1d4ed8;
  list-style: none;
  user-select: none;
  padding: 2px 0;
}
.nfv-older-toggle::-webkit-details-marker { display: none; }
.nfv-older-toggle::before {
  content: '▸';
  display: inline-block;
  margin-right: 6px;
  transition: transform 0.15s ease;
  color: #6a6e72;
}
details[open] > .nfv-older-toggle::before { transform: rotate(90deg); }
.nfv-older-toggle:hover { color: #1e40af; }
.nfv-older-list {
  margin-top: 8px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.nfv-older-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 10px;
  background: #f8f9fa;
  border: 1px solid #e1e4e8;
  border-radius: 4px;
}
.nfv-older-main { flex: 1; min-width: 0; font-size: 12.5px; }
.nfv-older-action .btn { white-space: nowrap; }

/* Compact tables for snoozed + history. */
.nfv-table {
  width: 100%;
  font-size: 13px;
  border-collapse: collapse;
}
.nfv-table th, .nfv-table td {
  padding: 8px 12px;
  border-bottom: 1px solid #f0f2f4;
  text-align: left;
}
.nfv-table th {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #6a6e72;
  font-weight: 600;
  background: #f8f9fa;
}
.nfv-table td.nfv-mono {
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
}
.nfv-table .nfv-slug { font-size: 13px; }
</style>
<?php

// Toolbar — Catalog button + (future: filters).
echo '<div class="nfv-toolbar">';
echo html_writer::link(
    new moodle_url('/local/nucleusspoke/catalog.php'),
    '<i class="fa fa-folder-open" aria-hidden="true"></i> '
        . get_string('catalog_open', 'local_nucleusspoke'),
    ['class' => 'btn btn-secondary']
);
echo '</div>';

// ---------- Pending notifications ----------

$pending = $DB->get_records_sql(
    "SELECT n.id, n.timereceived,
            f.slug AS familyslug,
            v.versionnumber, v.severity, v.releasenotes
       FROM {local_nucleusspoke_notification} n
       JOIN {local_nucleuscommon_family} f ON f.id = n.familyid
       JOIN {local_nucleuscommon_version} v ON v.id = n.versionid
      WHERE n.state = 'pending'
      ORDER BY n.timereceived ASC"
);

echo '<div class="nfv-section">';
$pendingcountclass = $pending ? 'nfv-section-count warn' : 'nfv-section-count';
echo '<h3 class="nfv-section-title">'
    . s(get_string('pendingupdates', 'local_nucleusspoke'))
    . '<span class="' . $pendingcountclass . '">' . count($pending) . '</span>'
    . '</h3>';
echo '</div>';

if (!$pending) {
    echo '<div class="nfv-empty">'
        . s(get_string('nopending', 'local_nucleusspoke'))
        . '</div>';
} else {
    foreach ($pending as $p) {
        $pullurl = new moodle_url($pageurl, ['pull' => $p->id, 'sesskey' => sesskey()]);
        $stagingurl = new moodle_url($pageurl, ['pull' => $p->id, 'staging' => 1, 'sesskey' => sesskey()]);
        $snoozeurl = new moodle_url($pageurl, ['nid' => $p->id, 'act' => 'snooze', 'sesskey' => sesskey()]);
        $dismissurl = new moodle_url($pageurl, ['nid' => $p->id, 'act' => 'dismiss', 'sesskey' => sesskey()]);

        echo '<div class="nfv-card">';
        echo '<div class="nfv-card-body">';
        echo '<div class="nfv-card-head">';
        echo '<span class="nfv-slug">' . s($p->familyslug) . '</span>';
        echo '<span class="nfv-version">v' . s($p->versionnumber) . '</span>';
        echo '<span class="nfv-sev">' . s($p->severity) . '</span>';
        echo '</div>';
        echo '<div class="nfv-meta">'
            . s(get_string('received', 'local_nucleusspoke')) . ': '
            . userdate($p->timereceived, get_string('strftimedatetimeshort', 'langconfig'))
            . '</div>';
        if (!empty($p->releasenotes)) {
            echo '<pre class="nfv-notes">' . s(shorten_text($p->releasenotes, 240)) . '</pre>';
        }
        echo '</div>';
        echo '<div class="nfv-card-actions">';
        echo html_writer::link($pullurl,
            '<i class="fa fa-download" aria-hidden="true"></i> '
                . get_string('pull', 'local_nucleusspoke'),
            ['class' => 'btn btn-primary btn-sm']);
        echo html_writer::link($stagingurl,
            get_string('pull_staging', 'local_nucleusspoke'),
            ['class' => 'btn btn-outline-primary btn-sm',
             'title' => get_string('pull_staging_hint', 'local_nucleusspoke')]);
        echo html_writer::link($snoozeurl,
            get_string('snooze', 'local_nucleusspoke'),
            ['class' => 'btn btn-outline-secondary btn-sm']);
        echo html_writer::link($dismissurl,
            get_string('dismiss', 'local_nucleusspoke'),
            ['class' => 'btn btn-link btn-sm']);
        echo '</div>';
        echo '</div>';
    }
}

// ---------- Snoozed ----------

$snoozed = $DB->get_records_sql(
    "SELECT n.id, n.timereceived, n.snoozeuntil,
            f.slug AS familyslug,
            v.versionnumber, v.severity
       FROM {local_nucleusspoke_notification} n
       JOIN {local_nucleuscommon_family} f ON f.id = n.familyid
       JOIN {local_nucleuscommon_version} v ON v.id = n.versionid
      WHERE n.state = 'snoozed'
      ORDER BY n.snoozeuntil ASC"
);
if ($snoozed) {
    echo '<div class="nfv-section">';
    echo '<h3 class="nfv-section-title">'
        . s(get_string('snoozedupdates', 'local_nucleusspoke'))
        . '<span class="nfv-section-count">' . count($snoozed) . '</span>'
        . '</h3>';
    echo '</div>';
    echo '<table class="nfv-table">';
    echo '<thead><tr>'
        . '<th>' . s(get_string('family', 'local_nucleusspoke')) . '</th>'
        . '<th>' . s(get_string('version', 'local_nucleusspoke')) . '</th>'
        . '<th>' . s(get_string('snoozed_until', 'local_nucleusspoke')) . '</th>'
        . '<th></th>'
        . '</tr></thead><tbody>';
    foreach ($snoozed as $sn) {
        $reactivateurl = new moodle_url($pageurl, ['nid' => $sn->id, 'act' => 'reactivate', 'sesskey' => sesskey()]);
        $dismissurl = new moodle_url($pageurl, ['nid' => $sn->id, 'act' => 'dismiss', 'sesskey' => sesskey()]);
        echo '<tr>'
            . '<td class="nfv-mono"><span class="nfv-slug">' . s($sn->familyslug) . '</span></td>'
            . '<td class="nfv-mono"><span class="nfv-version">v' . s($sn->versionnumber) . '</span></td>'
            . '<td>' . ($sn->snoozeuntil
                ? userdate($sn->snoozeuntil, get_string('strftimedatetimeshort', 'langconfig'))
                : '—') . '</td>'
            . '<td style="text-align:right">'
                . html_writer::link($reactivateurl, get_string('reactivate', 'local_nucleusspoke'),
                    ['class' => 'btn btn-outline-secondary btn-sm'])
                . ' '
                . html_writer::link($dismissurl, get_string('dismiss', 'local_nucleusspoke'),
                    ['class' => 'btn btn-link btn-sm'])
            . '</td>'
            . '</tr>';
    }
    echo '</tbody></table>';
}

// ---------- Pulled instances ----------

$instances = $DB->get_records_sql(
    "SELECT i.id, i.state, i.timepulled, i.localcourseid,
            c.fullname,
            f.id AS familyid, f.slug AS familyslug, f.guid AS familyguid,
            v.versionnumber, v.severity, v.deprecated, v.deprecatedreason, v.timepublished
       FROM {local_nucleusspoke_instance} i
       JOIN {course} c ON c.id = i.localcourseid
       JOIN {local_nucleuscommon_family} f ON f.id = i.familyid
       JOIN {local_nucleuscommon_version} v ON v.id = i.versionid
      ORDER BY i.timepulled DESC"
);

echo '<div class="nfv-section">';
echo '<h3 class="nfv-section-title">'
    . s(get_string('pulledinstances', 'local_nucleusspoke'))
    . '<span class="nfv-section-count">' . count($instances) . '</span>'
    . '</h3>';
echo '</div>';

if (!$instances) {
    echo '<div class="nfv-empty">'
        . s(get_string('noinstances', 'local_nucleusspoke'))
        . '</div>';
} else {
    // Group instances by family. Within each group, the most-
    // recently pulled lands first (the SQL already orders that way),
    // so $famgroup[0] is the headline shown on the card; the rest go
    // into the expandable details below.
    $grouped = [];
    foreach ($instances as $i) {
        $grouped[$i->familyid] ??= [];
        $grouped[$i->familyid][] = $i;
    }

    /**
     * Build the state-specific action button for an instance row.
     * Returns HTML or '' when the state has no action.
     */
    $actionFor = function (\stdClass $i) use ($pageurl, $DB) {
        if ((int) $i->deprecated === 1) {
            $target = $DB->get_record_sql(
                "SELECT versionnumber FROM {local_nucleuscommon_version}
                  WHERE familyid = :fid AND deprecated = 0
                    AND timepublished < :cur
                  ORDER BY timepublished DESC LIMIT 1",
                ['fid' => $i->familyid, 'cur' => (int) $i->timepublished]
            );
            if ($target) {
                return html_writer::link(
                    new moodle_url($pageurl, [
                        'rollback' => $i->id, 'sesskey' => sesskey(),
                    ]),
                    '<i class="fa fa-rotate-left" aria-hidden="true"></i> '
                        . get_string('rollback_to', 'local_nucleusspoke', 'v' . $target->versionnumber),
                    ['class' => 'btn btn-warning btn-sm']
                );
            }
            return '';
        }
        if ($i->state === 'staging') {
            return html_writer::link(
                new moodle_url($pageurl, [
                    'promote' => $i->id, 'sesskey' => sesskey(),
                ]),
                '<i class="fa fa-rocket" aria-hidden="true"></i> '
                    . get_string('promote', 'local_nucleusspoke'),
                ['class' => 'btn btn-primary btn-sm']
            );
        }
        if (str_starts_with((string) $i->state, 'closed')) {
            return html_writer::link(
                new moodle_url($pageurl, [
                    'lifecycle' => $i->id, 'lact' => 'reopen', 'sesskey' => sesskey(),
                ]),
                get_string('reopen', 'local_nucleusspoke'),
                ['class' => 'btn btn-outline-secondary btn-sm']
            );
        }
        if ($i->state === 'active') {
            return html_writer::link(
                new moodle_url($pageurl, [
                    'lifecycle' => $i->id, 'lact' => 'close', 'sesskey' => sesskey(),
                ]),
                get_string('close_to_enrolment', 'local_nucleusspoke'),
                ['class' => 'btn btn-outline-secondary btn-sm',
                 'title' => get_string('close_hint', 'local_nucleusspoke')]
            );
        }
        return '';
    };

    $stateClass = function (string $state, int $deprecated): string {
        if ($deprecated === 1) return 'nfv-state-archived';
        return match (true) {
            $state === 'active' => 'nfv-state-active',
            $state === 'staging' => 'nfv-state-staging',
            str_starts_with($state, 'closed') => 'nfv-state-closed',
            $state === 'archived' => 'nfv-state-archived',
            default => 'nfv-state-closed',
        };
    };

    foreach ($grouped as $famid => $famgroup) {
        $latest = $famgroup[0];
        $others = array_slice($famgroup, 1);
        $action = $actionFor($latest);
        $statelabel = ucfirst(str_replace('-', ' ', $latest->state));

        echo '<div class="nfv-card">';
        echo '<div class="nfv-card-body">';
        echo '<div class="nfv-card-head">';
        echo '<span class="nfv-slug">' . s($latest->familyslug) . '</span>';
        echo '<span class="nfv-version">v' . s($latest->versionnumber) . '</span>';
        echo '<span class="nfv-sev">' . s($latest->severity) . '</span>';
        echo '<span class="nfv-state ' . $stateClass($latest->state, (int) $latest->deprecated) . '">'
            . s($statelabel) . '</span>';
        if ((int) $latest->deprecated === 1) {
            echo '<span class="nfv-deprecated"><i class="fa fa-triangle-exclamation" aria-hidden="true"></i> '
                . s(get_string('deprecated', 'local_nucleusspoke')) . '</span>';
        }
        if (count($famgroup) > 1) {
            echo '<span class="nfv-card-tally">' . count($famgroup) . ' '
                . s(get_string('versions_pulled_count', 'local_nucleusspoke')) . '</span>';
        }
        echo '</div>';
        echo '<div class="nfv-meta">'
            . s(get_string('course', 'core')) . ': '
            . html_writer::link(
                new moodle_url('/course/view.php', ['id' => $latest->localcourseid]),
                format_string($latest->fullname)
            )
            . ' · '
            . s(get_string('pulled', 'local_nucleusspoke')) . ' '
            . userdate($latest->timepulled, get_string('strftimedatetimeshort', 'langconfig'))
            . '</div>';
        if ((int) $latest->deprecated === 1 && !empty($latest->deprecatedreason)) {
            echo '<div class="nfv-meta" style="color:#b91c1c; margin-top:4px">'
                . s(get_string('deprecated', 'local_nucleusspoke')) . ': ' . s($latest->deprecatedreason)
                . '</div>';
        }

        // Expandable: older instances in this family.
        if ($others) {
            echo '<details class="nfv-older">';
            echo '<summary class="nfv-older-toggle">'
                . get_string('older_versions', 'local_nucleusspoke', count($others))
                . '</summary>';
            echo '<div class="nfv-older-list">';
            foreach ($others as $o) {
                $oaction = $actionFor($o);
                $ostatelabel = ucfirst(str_replace('-', ' ', $o->state));
                echo '<div class="nfv-older-row">';
                echo '<div class="nfv-older-main">';
                echo '<span class="nfv-version">v' . s($o->versionnumber) . '</span>';
                echo ' <span class="nfv-sev">' . s($o->severity) . '</span>';
                echo ' <span class="nfv-state ' . $stateClass($o->state, (int) $o->deprecated) . '">'
                    . s($ostatelabel) . '</span>';
                if ((int) $o->deprecated === 1) {
                    echo ' <span class="nfv-deprecated"><i class="fa fa-triangle-exclamation" aria-hidden="true"></i> '
                        . s(get_string('deprecated', 'local_nucleusspoke')) . '</span>';
                }
                echo '<div class="nfv-meta" style="margin-top:3px">'
                    . html_writer::link(
                        new moodle_url('/course/view.php', ['id' => $o->localcourseid]),
                        format_string($o->fullname)
                    )
                    . ' · '
                    . userdate($o->timepulled, get_string('strftimedatetimeshort', 'langconfig'))
                    . '</div>';
                echo '</div>';
                if ($oaction) {
                    echo '<div class="nfv-older-action">' . $oaction . '</div>';
                }
                echo '</div>';
            }
            echo '</div>';
            echo '</details>';
        }

        echo '</div>'; // .nfv-card-body
        if ($action) {
            echo '<div class="nfv-card-actions">' . $action . '</div>';
        }
        echo '</div>'; // .nfv-card
    }
}

// ---------- History ----------

$history = $DB->get_records_sql(
    "SELECT n.id, n.state, n.timereceived, n.timeresolved,
            f.slug AS familyslug,
            v.versionnumber, v.severity
       FROM {local_nucleusspoke_notification} n
       JOIN {local_nucleuscommon_family} f ON f.id = n.familyid
       JOIN {local_nucleuscommon_version} v ON v.id = n.versionid
      WHERE n.state <> 'pending'
      ORDER BY n.timereceived DESC",
    null, 0, 20
);

if ($history) {
    echo '<div class="nfv-section">';
    echo '<h3 class="nfv-section-title">'
        . s(get_string('history', 'local_nucleusspoke'))
        . '<span class="nfv-section-count">' . count($history) . '</span>'
        . '</h3>';
    echo '</div>';
    echo '<table class="nfv-table">';
    echo '<thead><tr>'
        . '<th>' . s(get_string('family', 'local_nucleusspoke')) . '</th>'
        . '<th>' . s(get_string('version', 'local_nucleusspoke')) . '</th>'
        . '<th>' . s(get_string('received', 'local_nucleusspoke')) . '</th>'
        . '<th>' . s(get_string('resolved', 'local_nucleusspoke')) . '</th>'
        . '<th>' . s(get_string('state', 'local_nucleusspoke')) . '</th>'
        . '</tr></thead><tbody>';
    foreach ($history as $h) {
        echo '<tr>'
            . '<td class="nfv-mono"><span class="nfv-slug">' . s($h->familyslug) . '</span></td>'
            . '<td class="nfv-mono"><span class="nfv-version">v' . s($h->versionnumber) . '</span></td>'
            . '<td>' . userdate($h->timereceived, get_string('strftimedatetimeshort', 'langconfig')) . '</td>'
            . '<td>' . ($h->timeresolved
                ? userdate($h->timeresolved, get_string('strftimedatetimeshort', 'langconfig'))
                : '—') . '</td>'
            . '<td>' . s($h->state) . '</td>'
            . '</tr>';
    }
    echo '</tbody></table>';
}

echo $OUTPUT->footer();
