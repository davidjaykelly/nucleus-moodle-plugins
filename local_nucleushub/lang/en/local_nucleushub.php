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
 * Language strings for local_nucleushub.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['backupfailed'] = 'Couldn\'t back up the course to publish it.';
$string['banner_dirty_many'] = '{$a->count} changes since v{$a->version}, published {$a->when} ago. Publish a new version when you\'re ready.';
$string['banner_dirty_one'] = 'One change since v{$a->version}, published {$a->when} ago. Publish a new version when you\'re ready.';
$string['banner_dirty_unpublished'] = 'Changes so far: {$a}. Publish v1.0.0 when you\'re ready.';
$string['changekind_course_updated'] = 'Course settings changed: {$a}';
$string['changekind_file_replaced'] = 'Files replaced: {$a}';
$string['changekind_module_added'] = 'Activities added: {$a}';
$string['changekind_module_deleted'] = 'Activities removed: {$a}';
$string['changekind_module_updated'] = 'Activities updated: {$a}';
$string['changekind_other'] = 'Other changes: {$a}';
$string['changekind_section_added'] = 'Sections added: {$a}';
$string['changekind_section_deleted'] = 'Sections removed: {$a}';
$string['changekind_section_updated'] = 'Sections updated: {$a}';
$string['cpbadresponse'] = 'Nucleus sent an unexpected reply.';
$string['event_user_signed_in_to_spoke'] = 'Signed in to a spoke through the hub';
$string['families_col_changes'] = 'Changes';
$string['families_col_family'] = 'Course';
$string['families_col_latest'] = 'Latest version';
$string['families_col_versions'] = 'Versions published';
$string['families_created'] = 'Created {$a} ago';
$string['families_empty_hint'] = 'To share a course, open it and choose "Add to federation" from its More menu.';
$string['families_empty_title'] = 'No course families yet';
$string['families_identifier'] = 'Identifier: {$a}';
$string['families_latest'] = 'v{$a->version} ({$a->severity})';
$string['families_orphan'] = 'The hub course for this family has been deleted';
$string['families_publishedago'] = 'Published {$a} ago';
$string['families_reach'] = 'New versions go to every active spoke. Active spokes: {$a}.';
$string['families_reach_none'] = 'No spokes yet. Published versions will wait until one joins.';
$string['families_title'] = 'Course families';
$string['families_toolbar_hint'] = 'Every course this hub shares with its spokes. To add another, open the course and choose "Add to federation" from its More menu.';
$string['familyneverpublished_short'] = 'Not published yet';
$string['familynotfound'] = 'Course family not found ({$a}).';
$string['federationidunset'] = 'This hub isn\'t linked to a Nucleus federation yet, so it can\'t publish.';
$string['invalidseverity'] = 'Choose patch, minor or major.';
$string['notahub'] = 'This site is a spoke, so it can\'t share courses. Courses are shared from the hub.';
$string['nucleushub:publish'] = 'Publish course versions to the federation';
$string['oidc_badclient'] = 'Nucleus couldn\'t sign you in to that site: its sign-in link isn\'t one this hub knows. Go back to the site and try again. If it keeps happening, ask the site\'s administrator to check its sign-in settings in Nucleus.';
$string['oidc_badspokeurl'] = 'Nucleus couldn\'t set up sign-in for this spoke: its address isn\'t a valid URL, or isn\'t https while this hub is.';
$string['oidc_keyfailed'] = 'Nucleus couldn\'t create or update the hub\'s sign-in signing key.';
$string['oidc_keysdamaged'] = 'The hub\'s sign-in signing keys can\'t be read. Replace them with local/nucleushub/cli/oidc_rotate_key.php --reset.';
$string['oidc_nospoke'] = 'This hub has no active spoke with that ID.';
$string['oidc_nosubject'] = 'Nucleus couldn\'t create this account\'s sign-in identifier. Try again.';
$string['oidc_signedout'] = 'You\'re signed out of {$a}.';
$string['oidc_signedout_title'] = 'Signed out';
$string['oidc_signout_confirm'] = 'Sign out of {$a}?';
$string['oidc_signout_title'] = 'Sign out';
$string['orgsignin_baddomain'] = 'Nucleus couldn\'t use those email domains. Enter your organisation\'s own domains, separated by commas, for example contoso.org. Wildcards and public email services such as gmail.com aren\'t allowed.';
$string['orgsignin_badprovider'] = 'Choose Microsoft Entra ID, Google Workspace or another OpenID Connect provider.';
$string['orgsignin_badtenant'] = 'Nucleus couldn\'t use that tenant ID. Enter the Directory (tenant) ID from Microsoft Entra, or a verified domain such as contoso.onmicrosoft.com. Multi-tenant values such as common aren\'t allowed.';
$string['orgsignin_badurl'] = 'Nucleus couldn\'t use that discovery URL. Enter an https address ending in /.well-known/openid-configuration, or the address before it, with no query string.';
$string['orgsignin_button'] = 'Sign in with {$a}';
$string['orgsignin_discoveryfailed'] = 'Nucleus couldn\'t reach your provider\'s sign-in settings. Check the tenant ID or discovery URL, and try again.';
$string['orgsignin_missingfield'] = 'Nucleus needs a valid {$a}: the client ID and secret without spaces, and a name of up to 100 characters for the button.';
$string['orgsignin_savefailed'] = 'Nucleus couldn\'t save your organisation\'s sign-in on the hub. Try again.';
$string['orgsignin_secretlooksid'] = 'That looks like the secret\'s ID. In Microsoft Entra, copy the secret\'s Value instead.';
$string['pendingchanges_short'] = 'Unpublished changes: {$a}';
$string['pluginname'] = 'Nucleus federation hub';
$string['privacy:metadata:changelog'] = 'Changes made to shared courses since their last published version.';
$string['privacy:metadata:changelog:actoruserid'] = 'The ID of the person who made the change.';
$string['privacy:metadata:changelog:eventkind'] = 'The kind of change.';
$string['privacy:metadata:changelog:timecreated'] = 'When the change was made.';
$string['privacy:metadata:oidc_code'] = 'Single-use codes made when someone signs in to a spoke through the hub. Each lasts 60 seconds and is stored only as a hash.';
$string['privacy:metadata:oidc_code:authtime'] = 'When the person signed in to the hub.';
$string['privacy:metadata:oidc_code:clientid'] = 'The spoke they are signing in to.';
$string['privacy:metadata:oidc_code:expires'] = 'When the code expires.';
$string['privacy:metadata:oidc_code:nonce'] = 'A random value from the spoke, sent back to it with the sign-in.';
$string['privacy:metadata:oidc_code:userid'] = 'The ID of the person signing in.';
$string['privacy:metadata:oidc_subject'] = 'A random identifier for each person who signs in to spokes through the hub. Spokes know the person by it instead of their user ID. It is deleted with the account.';
$string['privacy:metadata:oidc_subject:sub'] = 'The random identifier sent to spokes.';
$string['privacy:metadata:oidc_subject:timecreated'] = 'When the identifier was made.';
$string['privacy:metadata:oidc_subject:userid'] = 'The ID of the person it belongs to.';
$string['privacy:metadata:oidc_token'] = 'Access tokens made when someone signs in to a spoke through the hub. Each lasts 5 minutes and is stored only as a hash.';
$string['privacy:metadata:oidc_token:clientid'] = 'The spoke they signed in to.';
$string['privacy:metadata:oidc_token:expires'] = 'When the token expires.';
$string['privacy:metadata:oidc_token:userid'] = 'The ID of the person who signed in.';
$string['privacy:metadata:spokes'] = 'When someone signs in to a spoke Moodle in the federation through the hub, the hub sends that spoke who they are. The spoke keeps its own account for them.';
$string['privacy:metadata:spokes:email'] = 'Their email address.';
$string['privacy:metadata:spokes:family_name'] = 'Their last name.';
$string['privacy:metadata:spokes:given_name'] = 'Their first name.';
$string['privacy:metadata:spokes:locale'] = 'Their preferred language.';
$string['privacy:metadata:spokes:name'] = 'Their full name.';
$string['privacy:metadata:spokes:sub'] = 'Their random identifier from the hub (not their user ID).';
$string['privacy:signins'] = 'Sign-ins to spokes';
$string['promote_already'] = 'This course is already in the federation. Publish a version when you\'re ready.';
$string['promote_content'] = 'Content';
$string['promote_heading'] = 'Add course to the federation';
$string['promote_intro'] = 'Adding this course gives it a permanent identifier that stays the same across every version and every spoke. Nothing is shared yet - publish v1.0.0 next, and your spokes can pull a copy.';
$string['promote_slug'] = 'Identifier';
$string['promote_slug_clash'] = '"{$a}" is already used in this federation. Choose another identifier.';
$string['promote_slug_help'] = 'A short name spokes will see for this course family. Use lowercase letters, numbers and hyphens. It can\'t be changed later.';
$string['promote_slug_invalid'] = 'The identifier needs at least one letter or number.';
$string['promote_slug_preview_label'] = 'Spokes will see this course as:';
$string['promote_submit'] = 'Add to federation';
$string['promote_success'] = 'Course added to the federation as "{$a}". Publish a version when you\'re ready.';
$string['promote_title'] = 'Add {$a} to the federation';
$string['publish_changes'] = 'Unpublished changes';
$string['publish_lockedit_caption'] = 'Spokes that pull this version get it read-only - their editing teachers can\'t change activities, sections or course settings. Use it for content that must stay as published.';
$string['publish_lockedit_label'] = 'Lock editing on spokes';
$string['publish_nextversion'] = 'creates v{$a}';
$string['publishfailed'] = 'Couldn\'t publish this version: {$a}';
$string['publishsuccess'] = 'Published v{$a->version} ({$a->size}). Your spokes will see it shortly.';
$string['publishversion'] = 'Publish version';
$string['publishversion_heading'] = 'Publish a new version';
$string['publishversion_title'] = 'Publish a new version of {$a}';
$string['releasenotes'] = 'Release notes';
$string['releasenotes_help'] = 'Say what changed in this version. Required for minor and major versions, optional for patches. Spoke admins see these notes before they pull.';
$string['releasenotesrequired'] = 'Add release notes - they\'re required for {$a} versions.';
$string['serviceuser_firstname'] = 'Nucleus spoke';
$string['serviceuser_tokenname'] = 'Nucleus spoke: {$a}';
$string['serviceuserclash'] = 'Nucleus couldn\'t set up this spoke\'s hub account: the username {$a} is already taken by another account.';
$string['severity'] = 'Type of change';
$string['severity_major_caption'] = 'Content removed or restructured. Spokes should plan when to move learners across.';
$string['severity_minor_caption'] = 'New or updated activities, nothing removed.';
$string['severity_note'] = 'Whatever the type, a spoke that pulls this version gets it as a new course. Learners stay where they are until the spoke moves them.';
$string['severity_patch_caption'] = 'Small fixes such as typos, section names or course settings. Nothing added or removed.';
$string['severity_rationale_major'] = 'Items removed since the last version: {$a}.';
$string['severity_rationale_minor'] = 'Added: {$a->added}. Updated: {$a->updated}. Nothing removed.';
$string['severity_rationale_patch'] = 'Only course settings or section names changed.';
$string['severity_suggested'] = 'Suggested: {$a->severity} - {$a->rationale}';
$string['severity_suggested_badge'] = 'Suggested';
$string['spokerole_description'] = 'Lets a Nucleus spoke call this hub\'s federation web service. Nucleus gives it to each spoke\'s hub account. Don\'t give it to people.';
$string['spokerole_name'] = 'Nucleus spoke';
$string['spokes_col_address'] = 'Address';
$string['spokes_col_name'] = 'Name';
$string['spokes_col_status'] = 'Status';
$string['spokes_cpid'] = 'Spoke ID';
$string['spokes_empty_hint'] = 'Spokes appear here once Nucleus has set them up. If one is missing, check it in the Nucleus portal.';
$string['spokes_empty_title'] = 'No spokes yet';
$string['spokes_heartbeat_never'] = 'Not yet';
$string['spokes_lastseen'] = 'Last seen';
$string['spokes_lastseen_ago'] = '{$a} ago';
$string['spokes_reach_summary'] = 'Spokes: {$a->spokes}. Course families on this hub: {$a->families}.';
$string['spokes_registered'] = 'Joined';
$string['spokes_status_active'] = 'Active';
$string['spokes_status_suspended'] = 'Suspended';
$string['spokes_title'] = 'Spokes';
$string['spokes_toolbar_hint'] = 'The spokes connected to this hub. Each one is told when you publish a new version of any course family.';
$string['spokeurlinuse'] = 'Nucleus couldn\'t register this spoke: another spoke is already registered at {$a}.';
$string['statusbar_hub_addtofederation'] = 'Add to federation';
$string['statusbar_hub_clean'] = 'Up to date';
$string['statusbar_hub_coursesummary'] = 'Sections: {$a->sections} · Activities: {$a->modules}';
$string['statusbar_hub_coursetitle'] = 'Course content';
$string['statusbar_hub_familycourse'] = 'Hub course: {$a}';
$string['statusbar_hub_familymeta'] = 'Versions published: {$a->count} · Created {$a->when} ago';
$string['statusbar_hub_lastpub'] = 'v{$a->version} ({$a->severity}) · published {$a->when} ago';
$string['statusbar_hub_lastpub_title'] = 'Last published';
$string['statusbar_hub_nopublishes'] = 'Nothing published yet. The first version will be v1.0.0.';
$string['statusbar_hub_nospokes'] = 'No spokes yet. Published versions will wait until one joins.';
$string['statusbar_hub_notinfederation'] = 'Not in the federation';
$string['statusbar_hub_notinfederation_hint'] = 'This course isn\'t shared with your spokes yet. Add it to the federation, then publish a version for spokes to pull.';
$string['statusbar_hub_pending'] = 'Unpublished changes: {$a}';
$string['statusbar_hub_pendingtitle'] = 'Unpublished changes ({$a})';
$string['statusbar_hub_spokesregistered'] = 'Spokes: {$a}. Each one is told when you publish.';
$string['statusbar_hub_spokestitle'] = 'Spokes';
$string['task_cleanup_oidc'] = 'Delete expired hub sign-in codes and tokens';
$string['versionnotfound'] = 'Version not found ({$a}).';
