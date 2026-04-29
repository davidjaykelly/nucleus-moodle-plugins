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
 * Language strings for local_nucleusspoke.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['banner_instance'] = '{$a->slug} · pulled {$a->when} ago · state: {$a->state}';
$string['older_versions'] = '+{$a} older version(s) pulled';
$string['versions_pulled_count'] = 'versions pulled';
$string['banner_pending'] = '{$a} new version(s) available';
$string['catalog_empty'] = 'The hub does not currently publish any course families. Once a hub admin promotes a course and publishes a version, it will appear here.';
$string['catalog_hubcourse'] = 'Hub course: {$a}';
$string['catalog_hubunreachable'] = 'Could not reach the federation hub: {$a}';
$string['catalog_intro_one'] = '1 course family available from your federation hub. Pull its latest version to create a local copy on this spoke.';
$string['catalog_intro_many'] = '{$a} course families available from your federation hub. Pull the latest version of each to create a local copy on this spoke.';
$string['catalog_noversions'] = 'No published versions yet.';
$string['catalog_open'] = 'Browse catalog';
$string['catalog_publishedwhen'] = 'published {$a} ago';
$string['catalog_pulllatest'] = 'Pull v{$a}';
$string['catalog_pullnotfound'] = 'Family or version not found in the current catalog. Reload the page and try again.';
$string['catalog_pullsuccess'] = 'Pulled {$a->slug} v{$a->version} — new course id {$a->courseid}.';
$string['catalog_title'] = 'Federation catalog';
$string['catalog_updatable'] = 'Update available';
$string['catalog_uptodate'] = 'On latest';
$string['banner_running_deprecated'] = 'Running deprecated v{$a->version} — hub flagged it: {$a->reason}';
$string['banner_update_available'] = 'New version v{$a->version} ({$a->severity}) is available — currently running v{$a->current} (notified {$a->when} ago).';
$string['banner_viewversions'] = 'View versions';
$string['dismiss'] = 'Dismiss';
$string['notificationbadaction'] = 'Unknown notification action: {$a}';
$string['notificationbadstate'] = 'Notification is in state "{$a->from}"; action requires one of {$a->allowed}.';
$string['notificationsnoozepast'] = 'Snooze-until must be in the future.';
$string['notification_snooze_success'] = 'Notification snoozed.';
$string['notification_dismiss_success'] = 'Notification dismissed.';
$string['notification_reactivate_success'] = 'Notification re-activated.';
$string['reactivate'] = 'Reactivate';
$string['snooze'] = 'Snooze';
$string['snooze_hint'] = 'Hide for 7 days. Returns as pending afterwards unless dismissed.';
$string['snoozed_until'] = 'Snoozed until';
$string['snoozedupdates'] = 'Snoozed updates';
$string['task_unsnooze_notifications'] = 'Nucleus: auto-unsnooze due notifications';
$string['deprecated'] = 'Deprecated';
$string['rollback_failure'] = 'Rollback failed: {$a}';
$string['rollback_nonone'] = 'no earlier version';
$string['rollback_notarget'] = 'No earlier non-deprecated version exists for this family.';
$string['rollback_success'] = 'Rolled back {$a->slug} from v{$a->from} to v{$a->to} — new course id {$a->courseid}.';
$string['rollback_to'] = 'Rollback → {$a}';
$string['statusbar_spoke_deprecated'] = 'deprecated';
$string['statusbar_spoke_deprecated_reason'] = 'Hub deprecated this version: {$a}';
$string['statusbar_spoke_familystate'] = 'State: {$a->state} · pulled {$a->when} ago';
$string['statusbar_spoke_localcopy'] = 'Local copy: {$a}';
$string['statusbar_spoke_morepending'] = '{$a} older notification(s) also pending — see the versions dashboard.';
$string['statusbar_spoke_pullupdate'] = 'Pull v{$a}';
$string['statusbar_spoke_runningtitle'] = 'Currently running';
$string['statusbar_spoke_runningversion'] = 'v{$a->version} ({$a->severity}) · published {$a->when} ago';
$string['statusbar_spoke_updateavailable'] = 'v{$a->version} ({$a->severity}) · notified {$a->when} ago';
$string['statusbar_spoke_updatestitle'] = 'Update available';
$string['instancenotstaging'] = 'Instance is not in staging state (current: {$a}).';
$string['promote'] = 'Promote';
$string['promotesuccess'] = 'Staging instance promoted. Course id {$a} is now visible to students.';
$string['pull_staging'] = 'Pull → staging';
$string['pull_staging_hint'] = 'Pull as hidden (students won\'t see it). Promote later to make live.';
$string['pullstagingsuccess'] = 'Pulled {$a->slug} v{$a->version} to staging — new course id {$a->courseid} is hidden until promoted.';
$string['close_to_enrolment'] = 'Close to enrolment';
$string['close_hint'] = 'Disable every enrol method on this course. Existing enrolments keep access; nobody new can enrol.';
$string['closesuccess'] = 'Course closed to new enrolments.';
$string['reopen'] = 'Reopen';
$string['reopensuccess'] = 'Course reopened — enrol methods re-enabled.';
$string['instancearchived'] = 'Instance is archived — lifecycle actions no longer apply.';
$string['instancenotclosed'] = 'Instance is not closed (current state: {$a}).';
$string['instance_action_unknown'] = 'Unknown instance action: {$a}';
$string['statusbar_spoke_pending'] = '{$a} available';
$string['statusbar_spoke_pulled'] = 'Pulled {$a->when} ago · state: {$a->state}';
$string['statusbar_spoke_pending_hint'] = '{$a} newer version(s) are waiting — open the Versions page to pull.';
$string['family'] = 'Family';
$string['history'] = 'History';
$string['hubunreachable'] = 'Federation hub is not reachable: {$a}';
$string['noinstances'] = 'No versions have been pulled onto this spoke yet.';
$string['nopending'] = 'No pending version updates.';
$string['nucleusspoke:pull'] = 'Pull a version of a course family onto this spoke';
$string['pendingupdates'] = 'Pending updates';
$string['pluginname'] = 'Nucleus federation spoke';
$string['pull'] = 'Pull';
$string['pullfailed'] = 'Course-version pull failed: {$a}';
$string['pullfailure'] = 'Pull failed: {$a}';
$string['pullsuccess'] = 'Pulled {$a->slug} v{$a->version} into new course (id {$a->courseid}).';
$string['pulled'] = 'Pulled';
$string['pulledinstances'] = 'Pulled instances';
$string['received'] = 'Received';
$string['releasenotes'] = 'Release notes';
$string['resolved'] = 'Resolved';
$string['severity'] = 'Severity';
$string['snapshothashmismatch'] = 'Downloaded snapshot hash mismatch (expected {$a->expected}, got {$a->got}). Aborted.';
$string['state'] = 'State';
$string['version'] = 'Version';
$string['versions_title'] = 'Course versions';
$string['setting_intro_html'] = '<div style="padding: 12px 14px; background: #f8f9fa; border: 1px solid #e1e4e8; border-left: 3px solid #d97706; border-radius: 4px; margin: 8px 0 18px 0;"><strong>Auto-configured during provisioning.</strong> The values below are written by the <code>wireSpokeToHub</code> stage when a spoke is provisioned. Edit only when recovering a broken state or building a tenant by hand.</div>';
$string['setting_hub_heading'] = 'Hub trust';
$string['setting_hub_desc'] = 'Where the federation hub lives, and the credentials this spoke uses to call it.';
$string['setting_hubtoken'] = 'Hub token';
$string['setting_hubtoken_desc'] = 'Web service token generated on the hub for the Nucleus federation service. Obtain via seed-federation.sh or the hub admin UI; Phase 1 control plane will provision this automatically.';
$string['setting_hubconnecturl'] = 'Hub connect URL (optional)';
$string['setting_hubconnecturl_desc'] = 'TCP destination for hub calls when the browser-facing hub wwwroot is not reachable from this spoke\'s pod. Typical Kubernetes value: the hub Service DNS (http://hub.nucleus-hub.svc.cluster.local). Leave empty when the spoke can reach the hub at its wwwroot directly. The Host header sent to the hub is always derived from "Hub wwwroot" so Moodle\'s wwwroot check still passes.';
$string['setting_hubwwwroot'] = 'Hub wwwroot';
$string['setting_hubwwwroot_desc'] = 'URL of the federation hub as the HUB believes itself to be (matches the hub\'s $CFG->wwwroot). Used to build the Host header on outgoing calls. If the spoke pod can\'t reach this URL directly (common in k8s), set "Hub connect URL" to the in-cluster Service DNS.';
$string['setting_spokename'] = 'Spoke name';
$string['setting_spokename_desc'] = 'Identifier for this spoke within the federation. Must match the name registered on the hub. Used as the Redis consumer-group name (nucleus:spoke:&lt;name&gt;).';
$string['spokenotconfigured'] = 'This spoke has no hub configured. Set Site administration → Plugins → Local plugins → Nucleus federation spoke.';
