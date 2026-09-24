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
 * Language strings for local_nucleuscommon.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['admincategory'] = 'Nucleus';
$string['cperror'] = 'A request to Nucleus failed: {$a}';
$string['cpnotconfigured'] = 'This site isn\'t connected to Nucleus yet - the Nucleus API URL or site token is missing.';
$string['cpserviceunknown'] = 'The Nucleus web service "{$a}" isn\'t installed. Run the Moodle upgrade, then try again.';
$string['huberror'] = 'Couldn\'t reach the hub: {$a}';
$string['pluginname'] = 'Nucleus federation (common)';
$string['privacy:metadata:family'] = 'Course families: the courses a hub shares with its spokes.';
$string['privacy:metadata:family:createdbyid'] = 'The ID of the person who added the course to the federation.';
$string['privacy:metadata:family:slug'] = 'The course family\'s identifier.';
$string['privacy:metadata:family:timecreated'] = 'When the course was added to the federation.';
$string['privacy:metadata:nucleus'] = 'Nucleus, the DK Labs service that runs the federation, receives course backups and a record of each version published or pulled.';
$string['privacy:metadata:nucleus:coursebackup'] = 'Backups of shared courses, without users or their data.';
$string['privacy:metadata:nucleus:userid'] = 'The ID of the person who published or pulled a course version.';
$string['privacy:metadata:version'] = 'Published versions of shared courses.';
$string['privacy:metadata:version:publishedbyid'] = 'The ID of the person who published the version.';
$string['privacy:metadata:version:releasenotes'] = 'The release notes written for the version.';
$string['privacy:metadata:version:timepublished'] = 'When the version was published.';
$string['privacy:metadata:version:versionnumber'] = 'The version number.';
$string['redisconnect'] = 'Couldn\'t connect to the Nucleus event service.';
$string['redismissing'] = 'Nucleus events need the PHP redis extension, which isn\'t loaded on this server.';
$string['redispublish'] = 'Couldn\'t send an update to Nucleus.';
$string['role_hub'] = 'Hub';
$string['role_spoke'] = 'Spoke';
$string['setting_cp_desc'] = 'How this site and Nucleus reach each other. Set automatically when the site was created.';
$string['setting_cp_heading'] = 'Nucleus connection';
$string['setting_cpbaseurl'] = 'Nucleus API URL';
$string['setting_cpbaseurl_desc'] = 'The address of the Nucleus API, ending in /api with no trailing slash.';
$string['setting_cpportalurl'] = 'Nucleus portal URL';
$string['setting_cpportalurl_desc'] = 'Where the "Nucleus portal" link in the Nucleus bar goes. Hosted sites use https://nucleus.dklabs.co.uk. Leave it empty to hide the link.';
$string['setting_cpsecret'] = 'Site token';
$string['setting_cpsecret_desc'] = 'This site\'s own key for talking to Nucleus, issued when the site was created or joined its federation. Keep it private - it identifies this site alone.';
$string['setting_eventstream'] = 'Event stream key';
$string['setting_eventstream_desc'] = 'The Redis stream name. It must be the same on the hub and every spoke.';
$string['setting_federationid'] = 'Federation ID';
$string['setting_federationid_desc'] = 'The Nucleus federation this site belongs to. Set automatically - only change it on a self-hosted site you built by hand.';
$string['setting_identity_desc'] = 'The Nucleus federation this site belongs to.';
$string['setting_identity_heading'] = 'Federation';
$string['setting_intro'] = 'Nucleus set these values when this site joined its federation. Only change them if DK Labs asks you to - a wrong value disconnects this site from its federation.';
$string['setting_intro_hosted'] = 'Nucleus set these values when it created this site. Only DK Labs can change them.';
$string['setting_notset'] = 'Not set';
$string['setting_redis_desc'] = 'Where the hub and spokes exchange events. Leave these as they are unless you run your own Redis.';
$string['setting_redis_heading'] = 'Event transport (Redis)';
$string['setting_redishost'] = 'Redis host';
$string['setting_redishost_desc'] = 'The Redis server this site can reach.';
$string['setting_redisport'] = 'Redis port';
$string['setting_redisport_desc'] = 'Usually 6379.';
$string['setting_secretset'] = 'Set (hidden)';
$string['setting_supportcontact'] = 'Support contact';
$string['setting_supportcontact_desc'] = 'An email address added to Nucleus error messages, as in "If this keeps happening, email ...". Leave it empty to show no contact.';
$string['settings_federation'] = 'Federation settings';
$string['severity_major'] = 'Major';
$string['severity_minor'] = 'Minor';
$string['severity_patch'] = 'Patch';
$string['statusbar_brand'] = 'Nucleus';
$string['statusbar_federationid'] = 'Federation ID: {$a}';
$string['statusbar_label'] = 'Nucleus status';
$string['statusbar_nofederation'] = 'Not set';
$string['statusbar_panel_empty'] = 'Open a course to see its Nucleus status.';
$string['statusbar_portal'] = 'Nucleus portal';
$string['statusbar_role'] = 'Role: {$a}';
$string['statusbar_thissite'] = 'This site';
$string['statusbar_toggle'] = 'Details';
$string['supportline'] = 'If this keeps happening, email {$a}.';
$string['tokenmissing'] = 'This site has no Nucleus token yet, so it can\'t talk to its federation.';
