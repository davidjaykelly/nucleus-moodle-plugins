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
 * Language strings for local_nucleuscommon.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['cperror'] = 'Nucleus control-plane call failed: {$a}';
$string['cpnotconfigured'] = 'Control-plane URL or secret is unset; cannot call the Nucleus control plane.';
$string['statusbar_federation'] = 'Federation';
$string['statusbar_mode'] = 'Federation mode';
$string['statusbar_panel_empty'] = 'No federation context for this page.';
$string['statusbar_nav_catalog'] = 'Catalog';
$string['statusbar_nav_families'] = 'Families';
$string['statusbar_nav_spokes'] = 'Spokes';
$string['statusbar_nav_versions'] = 'Versions';
$string['statusbar_portal'] = 'Portal';
$string['setting_cpportalurl'] = 'Control-plane portal URL';
$string['setting_cpportalurl_desc'] = 'Public URL of the Nucleus portal (not the API). Shown as the "Portal" link in the Moodle status bar. Leave empty to hide the link.';
$string['huberror'] = 'Federation hub call failed: {$a}';
$string['mode_content'] = 'Content federation (Mode A)';
$string['mode_identity'] = 'Identity federation (Mode B)';
$string['pluginname'] = 'Nucleus federation (common)';
$string['redisconnect'] = 'Could not connect to Redis: {$a}';
$string['redismissing'] = 'The PHP redis extension is required for the Nucleus event transport but is not loaded.';
$string['redispublish'] = 'Publishing to the Nucleus event stream failed: {$a}';
$string['tokenmissing'] = 'No federation token is configured on this Moodle.';
$string['setting_eventstream'] = 'Event stream key';
$string['setting_eventstream_desc'] = 'Redis stream key used for cross-instance federation events. Must match on hub and spoke.';
$string['setting_mode'] = 'Federation mode';
$string['setting_mode_desc'] = 'Operating mode for this federation. Must match on hub and spoke. Mode A distributes content only; Mode B projects users and enrolments to the hub.';
$string['setting_intro_html'] = '<div style="padding: 12px 14px; background: #f8f9fa; border: 1px solid #e1e4e8; border-left: 3px solid #d97706; border-radius: 4px; margin: 8px 0 18px 0;"><strong>Auto-configured during provisioning.</strong> Most values on this page are written by <code>setup_cp_token.php</code> when a hub or spoke is provisioned via the Nucleus control plane. Edit only when recovering a broken state or building a tenant by hand.</div>';
$string['setting_identity_heading'] = 'Federation identity';
$string['setting_identity_desc'] = 'Which federation this Moodle belongs to and what mode it operates in. Mode must agree across the hub and all of its spokes.';
$string['setting_cp_desc'] = 'Coordinates the control plane uses to call into this Moodle, plus the URL operators reach the portal at. Set automatically at provision time.';
$string['setting_redis_desc'] = 'Where the cross-instance event stream lives. Hub and spokes publish/consume the same stream.';
$string['setting_redis_heading'] = 'Redis event transport';
$string['setting_redishost'] = 'Redis host';
$string['setting_redishost_desc'] = 'Hostname reachable from this Moodle container for the shared Redis instance.';
$string['setting_redisport'] = 'Redis port';
$string['setting_cp_heading'] = 'Nucleus control plane';
$string['setting_federationid'] = 'Federation id';
$string['setting_federationid_desc'] = 'Control-plane federation id this Moodle belongs to. Set automatically by the provisioning worker; paste manually only for a hand-built tenant.';
$string['setting_cpbaseurl'] = 'Control-plane base URL';
$string['setting_cpbaseurl_desc'] = 'Base URL of the Nucleus control-plane API, e.g. https://control-plane.example.com/api. No trailing slash.';
$string['setting_cpsecret'] = 'Control-plane node secret';
$string['setting_cpsecret_desc'] = 'Shared secret matching FEDERATION_NODE_SECRET on the control plane. Used for node-to-node calls (e.g. snapshot upload). Rotate on both ends together.';
