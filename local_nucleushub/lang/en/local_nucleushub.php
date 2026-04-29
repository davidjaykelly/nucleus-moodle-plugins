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
 * Language strings for local_nucleushub.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['backupfailed'] = 'Federation backup failed: {$a}';
$string['banner_clean'] = 'No changes since v{$a->version} — published {$a->when} ago.';
$string['banner_clean_badge'] = 'Clean';
$string['banner_dirty'] = '{$a->count} pending change(s) since v{$a->version} (published {$a->when} ago) — ready to publish a new version.';
$string['banner_dirty_badge'] = 'Draft changes';
$string['banner_dirty_unpublished'] = '{$a} pending change(s) accumulated — ready to publish v1.0.0.';
$string['banner_unpublished'] = 'This course has no federation versions yet — first publish creates v1.0.0.';
$string['banner_unpublished_badge'] = 'Unpublished';
$string['statusbar_hub_addtofederation'] = 'Add to federation';
$string['statusbar_hub_clean'] = 'clean';
$string['statusbar_hub_coursesummary'] = '{$a->sections} sections · {$a->modules} activities';
$string['statusbar_hub_coursetitle'] = 'Course content';
$string['statusbar_hub_familycourse'] = 'Working copy: {$a}';
$string['statusbar_hub_familymeta'] = '{$a->count} version(s) published · created {$a->when} ago';
$string['statusbar_hub_lastpub'] = 'v{$a->version} ({$a->severity}) · published {$a->when} ago';
$string['statusbar_hub_lastpub_title'] = 'Last published';
$string['statusbar_hub_nopublishes'] = 'No versions published yet — first publish will be v1.0.0.';
$string['statusbar_hub_nospokes'] = 'No spokes registered yet — published versions will sit waiting until a spoke joins.';
$string['statusbar_hub_notinfederation'] = 'not in federation';
$string['statusbar_hub_notinfederation_hint'] = 'This course is not yet part of the federation. Add it to give it a stable family identity that spokes can pull.';
$string['statusbar_hub_pending'] = '{$a} pending';
$string['statusbar_hub_pendingbreakdown'] = 'Pending changes:';
$string['statusbar_hub_pendingtitle'] = '{$a} pending change(s)';
$string['statusbar_hub_spokesregistered'] = '{$a} spoke(s) registered. They will be notified on the next publish.';
$string['statusbar_hub_spokestitle'] = 'Downstream';
$string['statusbar_hub_unpublished'] = 'unpublished';
$string['statusbar_hub_unpublished_hint'] = 'First publish will create version 1.0.0 for this course family.';
$string['cpbadresponse'] = 'Control-plane returned an unexpected response: {$a}';
$string['families_empty_hint'] = 'Open any hub course and click "Add to federation" in the Nucleus status bar to promote it to a versioned family.';
$string['families_empty_title'] = 'No course families yet.';
$string['families_heading'] = 'Families';
$string['families_old'] = 'old';
$string['families_opencourse'] = 'Open course';
$string['families_orphan'] = 'No working course (orphaned family)';
$string['families_reach'] = 'Each published version is fanned out to {$a} active spoke(s).';
$string['families_reach_none'] = 'No spokes are registered yet — published versions will sit waiting until one joins.';
$string['families_title'] = 'Federation families';
$string['families_toolbar_hint'] = 'Every course family this hub publishes. Open a hub course to promote new ones via the Nucleus status bar.';
$string['families_versions_published'] = 'version(s) published';
$string['families_workingcourse'] = 'Working course';
$string['spokes_ago'] = 'ago';
$string['spokes_cpid'] = 'CP id';
$string['spokes_empty_hint'] = 'Spokes register automatically when the control plane provisions them. If you expected one to be here, check the provisioning job in the portal.';
$string['spokes_empty_title'] = 'No spokes registered yet.';
$string['spokes_heading'] = 'Spokes';
$string['spokes_heartbeat_never'] = 'never seen';
$string['spokes_lastseen'] = 'Last seen';
$string['spokes_reach_summary'] = 'Each published version reaches {$a->spokes} spoke(s); {$a->families} famil(ies) on this hub.';
$string['spokes_registered'] = 'Registered';
$string['spokes_title'] = 'Federation spokes';
$string['spokes_toolbar_hint'] = 'Spokes registered with this hub. Each one is notified when you publish a new version of any family below.';
$string['family'] = 'Family';
$string['familyneverpublished'] = 'This family has never been published — the next publish will be v1.0.0.';
$string['familyneverpublished_short'] = 'no published versions yet';
$string['firstpublish_hint'] = 'This course has no versioned family yet. Publishing will auto-create one using the course shortname as the slug.';
$string['lastpublished'] = 'Last published: v{$a->version} ({$a->severity}) on {$a->when}';
$string['pendingchanges'] = 'Pending changes since publish: {$a}';
$string['pendingchanges_short'] = '{$a} pending';
$string['publishsuccess'] = 'Published v{$a->version} ({$a->size}).';
$string['publishversion'] = 'Publish version';
$string['publishversion_title'] = 'Publish a new version of {$a}';
$string['releasenotes'] = 'Release notes';
$string['releasenotes_help'] = 'Describe what changed in this version. Required for minor and major bumps; optional for patches. Spokes see these in the notification they receive.';
$string['severity'] = 'Severity';
$string['severity_major'] = 'Major';
$string['severity_major_caption'] = 'Breaking changes — content is removed or restructured. Spokes need to plan their upgrade.';
$string['severity_minor'] = 'Minor';
$string['severity_minor_caption'] = 'New content added or activities updated. Forward-compatible — spokes can upgrade in place.';
$string['severity_patch'] = 'Patch';
$string['severity_patch_caption'] = 'Small tweaks: course settings, section names, typo fixes. No structural changes.';
$string['severity_suggested'] = 'Suggested severity: <strong>{$a->severity}</strong> — {$a->rationale}';
$string['severity_suggested_badge'] = 'Suggested';
$string['severity_rationale_major'] = '{$a} deletion(s) since last publish — existing learners lose content if they upgrade in place.';
$string['severity_rationale_minor'] = '{$a->added} addition(s) + {$a->updated} module update(s) — forward-compatible changes.';
$string['severity_rationale_patch'] = 'Course metadata / section edits only — no structural changes.';
$string['enrolnotavailable'] = 'Manual enrolment plugin is not available on the hub.';
$string['familynotfound'] = 'No course family found with guid {$a}';
$string['federationidunset'] = 'local_nucleuscommon/federationid is unset on this hub; cannot publish.';
$string['invalidseverity'] = 'Severity must be one of major, minor, patch — got {$a}';
$string['nucleushub:publish'] = 'Publish a new version of a course family to the federation';
$string['nospokeregistered'] = 'No active spoke is registered on the hub.';
$string['notaprojectedhubuser'] = 'The hub user is not a projected federation user and cannot be enrolled via the federation protocol.';
$string['pluginname'] = 'Nucleus federation hub';
$string['promote_already'] = 'This course is already in the federation. Continue to publish a version.';
$string['promote_course'] = 'Course';
$string['promote_course_content'] = 'Content';
$string['promote_course_shortname'] = 'Shortname';
$string['promote_heading'] = 'Add course to federation';
$string['promote_intro'] = 'Promoting this course gives it a stable identity across all versions and all spokes. After promoting, you can publish v1.0.0 — spokes will be notified and able to pull a copy.';
$string['promote_slug'] = 'Family slug';
$string['promote_slug_clash'] = 'Slug "{$a}" is already taken in this federation. Pick a different one.';
$string['promote_slug_help'] = 'Stable identifier for this course family across all versions and all spokes. Use lowercase letters, numbers, and hyphens. Cannot be changed once set.';
$string['promote_slug_invalid'] = 'Slug must contain at least one letter or number.';
$string['promote_slug_preview_label'] = 'Spokes will see this family as:';
$string['promote_submit'] = 'Add to federation';
$string['promote_success'] = 'Course added to the federation as family "{$a}". Publish a version when ready.';
$string['promote_title'] = 'Add {$a} to the federation';
$string['releasenotesrequired'] = 'Release notes are required for {$a} version bumps.';
$string['setting_intro_html'] = '<div style="padding: 12px 14px; background: #f8f9fa; border: 1px solid #e1e4e8; border-left: 3px solid #d97706; border-radius: 4px; margin: 8px 0 18px 0;"><strong>Hub-side federation settings.</strong> Spoke→hub trust is set up automatically by the provisioning worker (see <code>wireSpokeToHub</code>). The token below is a Phase 0 fallback; new spokes get their own per-spoke tokens minted via <code>register_spoke</code>.</div>';
$string['setting_spoketoken'] = 'Shared spoke token';
$string['setting_spoketoken_desc'] = 'Phase 0 shared secret used by all spokes to authenticate against this hub. Paste the web service token generated on the hub for the Nucleus federation service. Phase 1 will replace this with per-spoke tokens held in the control plane.';
