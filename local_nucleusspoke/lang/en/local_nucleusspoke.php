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
 * Language strings for local_nucleusspoke.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['actionfailure'] = 'Couldn\'t do that: {$a}';
$string['banner_running_deprecated'] = 'This course is on v{$a->version}, which the hub has marked as deprecated: {$a->reason}';
$string['banner_update_available'] = 'v{$a->version} ({$a->severity}) is available. This course is on v{$a->current}.';
$string['banner_viewversions'] = 'View versions';
$string['blocker_moodle_detail'] = 'This course was published from Moodle {$a->hub}; this site runs Moodle {$a->spoke}.';
$string['blocker_moodle_remedy'] = 'Upgrade this site to Moodle {$a} or newer, then try again.';
$string['blocker_moodle_remedy_hosted'] = 'Email contact@dklabs.co.uk to have this site moved to Moodle {$a} or newer, then try again.';
$string['blocker_plugins_detail'] = 'This course uses plugins this site doesn\'t have: {$a}.';
$string['blocker_plugins_remedy'] = 'Install them on this site (Site administration > Plugins > Install plugins), then try again. Or ask your hub to publish a version that doesn\'t use them.';
$string['blocker_plugins_remedy_hosted'] = 'Email contact@dklabs.co.uk to have them added, then try again.';
$string['catalog_blocked_back'] = 'Back to the catalogue';
$string['catalog_blocked_notes'] = 'Other notes';
$string['catalog_blocked_remediation'] = 'What to do';
$string['catalog_blocked_retry'] = 'Try again';
$string['catalog_blocked_sub'] = 'This site is missing plugins the course needs, listed below. Install them, then try again.';
$string['catalog_blocked_sub_hosted'] = 'This site is missing plugins the course needs, listed below. Email contact@dklabs.co.uk to have them added, then try again.';
$string['catalog_blocked_title'] = 'Can\'t pull {$a->slug} v{$a->version} yet';
$string['catalog_col_course'] = 'Course';
$string['catalog_col_latest'] = 'Latest version';
$string['catalog_col_onthissite'] = 'On this site';
$string['catalog_empty'] = 'Your hub isn\'t sharing any courses yet. They\'ll appear here once the hub publishes a version.';
$string['catalog_hubunreachable'] = 'Couldn\'t reach your hub, so the catalogue can\'t be shown. Try again in a minute. ({$a})';
$string['catalog_identifier'] = 'Identifier: {$a}';
$string['catalog_intro_many'] = 'Courses available from your hub: {$a}. Pulling a version creates a new course on this site.';
$string['catalog_intro_one'] = 'One course available from your hub. Pulling a version creates a new course on this site.';
$string['catalog_latest'] = 'v{$a->version} ({$a->severity})';
$string['catalog_locked_chip'] = 'Editing locked';
$string['catalog_locked_title'] = 'Published with editing locked - teachers on this site won\'t be able to change the course after you pull it.';
$string['catalog_mod_anyversion'] = 'Any version';
$string['catalog_mod_caption'] = 'Plugins the course needs';
$string['catalog_mod_local_col'] = 'This site';
$string['catalog_mod_manifest_col'] = 'Course needs';
$string['catalog_mod_notinstalled'] = 'Not installed';
$string['catalog_mod_plugin_col'] = 'Plugin';
$string['catalog_mod_state_col'] = 'State';
$string['catalog_mod_state_missing'] = 'Missing';
$string['catalog_mod_state_older'] = 'Older version';
$string['catalog_mod_state_present'] = 'Installed';
$string['catalog_notes_chip'] = 'Notes: {$a}';
$string['catalog_notpulled'] = 'Not pulled';
$string['catalog_noversions'] = 'No published versions yet.';
$string['catalog_open'] = 'Browse the catalogue';
$string['catalog_publishedwhen'] = 'Published {$a} ago';
$string['catalog_pulllatest'] = 'Pull v{$a}';
$string['catalog_pullnotfound'] = 'That version is no longer in the catalogue. Reload the page and try again.';
$string['catalog_pullsuccess'] = 'Pulled {$a->slug} v{$a->version} as a new course.';
$string['catalog_releasenotes'] = 'Release notes';
$string['catalog_title'] = 'Catalogue';
$string['catalog_toversions'] = 'Go to Course versions';
$string['catalog_updatable'] = 'Update available';
$string['catalog_uptodate'] = 'Up to date';
$string['close_to_enrolment'] = 'Close to enrolment';
$string['closesuccess'] = 'Course closed to new enrolments.';
$string['confirm_close'] = 'Close {$a} to enrolment? Every enrolment method on it is turned off. Learners already enrolled keep access - nobody new can join.';
$string['confirm_dismiss'] = 'Dismiss the update to {$a->slug} v{$a->version}? It moves to History and won\'t come back.';
$string['confirm_pull'] = 'Pull {$a->slug} v{$a->version}? It arrives as a new course on this site that learners can see. Courses already on this site stay as they are.';
$string['confirm_pullhidden'] = 'Pull {$a->slug} v{$a->version} as a new course hidden from learners? You can check it, then make it visible.';
$string['confirm_rollback'] = 'Roll {$a->slug} back to v{$a->to}? v{$a->to} is pulled again as a new course. Learners stay in v{$a->from} until you move them.';
$string['copy_already'] = 'This course has already been copied.';
$string['copy_done'] = 'Copied to a new course on this site.';
$string['dependencyblocked'] = 'Can\'t pull this version yet:
{$a}';
$string['deprecated'] = 'Deprecated';
$string['deprecated_noreason'] = 'no reason given';
$string['dismiss'] = 'Dismiss';
$string['family'] = 'Course family';
$string['history'] = 'History';
$string['instance_action_unknown'] = 'Unknown action: {$a}';
$string['instancearchived'] = 'This course is archived, so it can\'t be closed or reopened.';
$string['instancenotclosed'] = 'This course isn\'t closed to enrolment.';
$string['instancenotstaging'] = 'This course is already visible to learners.';
$string['locked_banner_body'] = 'Your hub locked editing on this course, so teachers can\'t change its content here.';
$string['lockedbadge'] = 'Editing locked';
$string['manifest_note_empty'] = 'This version has no plugin list, so the plugin check was skipped.';
$string['manifest_note_extractor'] = 'The hub couldn\'t list this version\'s plugins, so the plugin check was skipped: {$a}';
$string['manifest_note_malformed'] = 'This version\'s plugin list couldn\'t be read, so the plugin check was skipped.';
$string['manifest_note_nomanifest'] = 'This version was published before Nucleus checked plugins, so that check was skipped. Moodle still checks the course as it restores it.';
$string['manifest_note_older'] = 'mod_{$a->name} on this site ({$a->local}) is older than the version the course was published with ({$a->expected}). Most courses still restore cleanly, but some features may look different.';
$string['noinstances'] = 'Nothing pulled to this site yet.';
$string['nopending'] = 'No updates waiting.';
$string['notekind_edit_locked'] = 'Editing';
$string['notekind_manifest_note'] = 'Compatibility';
$string['notekind_other'] = 'Note';
$string['notekind_restore_warning'] = 'Restore';
$string['notification_dismiss_success'] = 'Update dismissed.';
$string['notification_reactivate_success'] = 'Update moved back to waiting.';
$string['notification_snooze_success'] = 'Update snoozed for 7 days.';
$string['notificationbadaction'] = 'Unknown update action: {$a}';
$string['notificationbadstate'] = 'This update has changed since the page loaded. Reload and try again.';
$string['notificationsnoozepast'] = 'Snooze until a time in the future.';
$string['notifstate_dismissed'] = 'Dismissed';
$string['notifstate_pending'] = 'Waiting';
$string['notifstate_resolved'] = 'Pulled';
$string['notifstate_snoozed'] = 'Snoozed';
$string['nucleusspoke:pull'] = 'Pull course versions onto this spoke';
$string['opencourse'] = 'Open the course';
$string['pending_help'] = 'Pull brings a version in as a new course. Pull as hidden does the same but hides it from learners so you can check it first. Snooze hides an update for 7 days.';
$string['pendingupdates'] = 'Updates waiting';
$string['pluginname'] = 'Nucleus federation spoke';
$string['privacy:metadata:instance'] = 'The courses this spoke has pulled from its hub.';
$string['privacy:metadata:instance:localcourseid'] = 'The course created by the pull.';
$string['privacy:metadata:instance:pulledbyid'] = 'The ID of the person who pulled the course.';
$string['privacy:metadata:instance:timepulled'] = 'When the course was pulled.';
$string['privacy:metadata:notification'] = 'Updates from the hub that a new version is available.';
$string['privacy:metadata:notification:resolvedbyid'] = 'The ID of the person who pulled or dismissed the update.';
$string['privacy:metadata:notification:state'] = 'Whether the update is waiting, snoozed, dismissed or pulled.';
$string['privacy:metadata:notification:timeresolved'] = 'When the update was pulled or dismissed.';
$string['privacy:metadata:nucleus'] = 'Nucleus, the DK Labs service that runs the federation, receives a record of each course version pulled.';
$string['privacy:metadata:nucleus:userid'] = 'The ID of the person who pulled the course version.';
$string['promote'] = 'Make visible to learners';
$string['promotesuccess'] = 'The course is now visible to learners.';
$string['pull_staging'] = 'Pull as hidden';
$string['pull_version'] = 'Pull v{$a}';
$string['pulled'] = 'Pulled';
$string['pulledinstances'] = 'Pulled courses';
$string['pullfailed'] = 'Couldn\'t restore the course: {$a}';
$string['pullfailure'] = 'Pull failed: {$a}';
$string['pullstagingsuccess'] = 'Pulled {$a->slug} v{$a->version} as a new hidden course. Learners won\'t see it until you make it visible.';
$string['pullsuccess'] = 'Pulled {$a->slug} v{$a->version} as a new course.';
$string['reactivate'] = 'Show again';
$string['received'] = 'Received';
$string['reopen'] = 'Reopen enrolment';
$string['reopensuccess'] = 'Enrolment reopened.';
$string['resolved'] = 'Resolved';
$string['rollback'] = 'Roll back';
$string['rollback_failure'] = 'Couldn\'t roll back: {$a}';
$string['rollback_notarget'] = 'There\'s no earlier version to roll back to.';
$string['rollback_success'] = 'Rolled back {$a->slug} to v{$a->to}. It\'s a new course on this site - learners stay in v{$a->from} until you move them.';
$string['rollback_to'] = 'Roll back to v{$a}';
$string['setting_hub_desc'] = 'Where the hub is, and the key this site uses to call it.';
$string['setting_hub_heading'] = 'Your hub';
$string['setting_hubconnecturl'] = 'Internal hub address (optional)';
$string['setting_hubconnecturl_desc'] = 'Only needed when this site can\'t reach the hub at its public address, for example on a private network. Leave it empty otherwise.';
$string['setting_hubtoken'] = 'Hub token';
$string['setting_hubtoken_desc'] = 'The key this site uses to call its hub. Nucleus sets it when the spoke joins.';
$string['setting_huburl'] = 'Hub URL';
$string['setting_huburl_desc'] = 'The hub\'s public web address, exactly as set in the hub\'s config.php.';
$string['setting_intro'] = 'Only spokes use these. Nucleus sets them when a spoke joins its hub - only change them if DK Labs asks you to.';
$string['setting_intro_hosted'] = 'Nucleus set these values when this spoke joined its hub. Only DK Labs can change them.';
$string['setting_spokename'] = 'Spoke name';
$string['setting_spokename_desc'] = 'This spoke\'s name in the federation. It must match the name the hub has for it.';
$string['settings_hub'] = 'Hub connection';
$string['sharedcourses_category'] = 'Shared courses';
$string['sharedcourses_category_desc'] = 'Courses this site has pulled from its hub.';
$string['signin_issuernotonhub'] = 'The sign-in issuer must be exactly {$a}, on this spoke\'s hub.';
$string['signin_pluginmissing'] = 'Nucleus hub sign-in (auth_nucleus) isn\'t installed on this site. Install it with the other Nucleus plugins, then try again.';
$string['snapshothashmismatch'] = 'The downloaded course didn\'t match what the hub published, so nothing changed. Try the pull again.';
$string['snooze'] = 'Snooze';
$string['snoozed_until'] = 'Snoozed until';
$string['snoozedupdates'] = 'Snoozed updates';
$string['spokenotconfigured'] = 'This site isn\'t connected to a hub yet. Set the hub in Site administration > Plugins > Local plugins > Nucleus > Hub connection.';
$string['state'] = 'State';
$string['state_active'] = 'Live';
$string['state_archived'] = 'Archived';
$string['state_closed'] = 'Closed to enrolment';
$string['state_staging'] = 'Hidden';
$string['statusbar_spoke_deprecated'] = 'Deprecated';
$string['statusbar_spoke_deprecated_reason'] = 'The hub has deprecated this version: {$a}';
$string['statusbar_spoke_familystate'] = 'State: {$a->state} · pulled {$a->when} ago';
$string['statusbar_spoke_localcopy'] = 'This course: {$a}';
$string['statusbar_spoke_locked'] = 'Editing locked';
$string['statusbar_spoke_locked_title'] = 'The hub locked editing on this version, so teachers here can\'t change the content.';
$string['statusbar_spoke_morepending'] = 'Older updates waiting: {$a}. See Course versions.';
$string['statusbar_spoke_pending'] = 'Updates: {$a}';
$string['statusbar_spoke_pullupdate'] = 'Pull v{$a}';
$string['statusbar_spoke_runningtitle'] = 'This course is on';
$string['statusbar_spoke_runningversion'] = 'v{$a->version} ({$a->severity}) · published {$a->when} ago';
$string['statusbar_spoke_site_clean'] = 'No updates waiting';
$string['statusbar_spoke_site_fromhub'] = 'Courses from the hub: {$a}';
$string['statusbar_spoke_site_title'] = 'This spoke';
$string['statusbar_spoke_site_waiting'] = 'Updates waiting: {$a}. See Course versions.';
$string['statusbar_spoke_updateavailable'] = 'v{$a->version} ({$a->severity}) · received {$a->when} ago';
$string['statusbar_spoke_updatestitle'] = 'Update available';
$string['task_unsnooze_notifications'] = 'Bring back snoozed Nucleus updates';
$string['version'] = 'Version';
$string['versions_title'] = 'Course versions';
