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
 * Web service declarations for local_nucleushub.
 *
 * Declares the hub's external functions and two services: "Nucleus
 * federation", which spokes call with their own per-spoke token
 * (ADR-023), and "Nucleus control plane (hub)", which Nucleus calls.
 * Spokes only share courses, so the federation service is read-only.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_nucleushub_list_courses' => [
        'classname'   => 'local_nucleushub\external\list_courses',
        'description' => 'List the courses this hub offers to its federation.',
        'type'        => 'read',
        'ajax'        => false,
    ],
    'local_nucleushub_register_spoke' => [
        'classname'   => 'local_nucleushub\external\register_spoke',
        'description' => 'Register a spoke with this hub: give it its own hub account and return that account\'s token.',
        'type'        => 'write',
        'ajax'        => false,
    ],
    'local_nucleushub_unregister_spoke' => [
        'classname'   => 'local_nucleushub\external\unregister_spoke',
        'description' => 'Remove a spoke from this hub: delete its hub account and token.',
        'type'        => 'write',
        'ajax'        => false,
    ],
    'local_nucleushub_publish_version' => [
        'classname'   => 'local_nucleushub\external\publish_version',
        'description' => 'Publish a new version of a course family: back up the course, upload it to Nucleus and record the version.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/nucleushub:publish',
    ],
    'local_nucleushub_list_families' => [
        'classname'   => 'local_nucleushub\external\list_families',
        'description' => 'List every course family on this hub with its versions.',
        'type'        => 'read',
        'ajax'        => false,
        'capabilities' => 'local/nucleushub:publish',
    ],
    'local_nucleushub_mark_deprecated' => [
        'classname'   => 'local_nucleushub\external\mark_deprecated',
        'description' => 'Mark a published version as deprecated, or not, and tell the spokes.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/nucleushub:publish',
    ],
    'local_nucleushub_describe_version' => [
        'classname'   => 'local_nucleushub\external\describe_version',
        'description' => 'Return the plugins and Moodle version a published version needs, so a spoke can check before pulling.',
        'type'        => 'read',
        'ajax'        => false,
    ],
    // Sign in with the hub (ADR-023 section 3). Control plane only:
    // these must never be on the federation service spokes call.
    'local_nucleushub_oidc_register_client' => [
        'classname'   => 'local_nucleushub\external\oidc_register_client',
        'description' => 'Register a spoke\'s sign-in client on this hub, or give it a new secret.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'moodle/site:config',
    ],
    'local_nucleushub_oidc_delete_client' => [
        'classname'   => 'local_nucleushub\external\oidc_delete_client',
        'description' => 'Delete a spoke\'s sign-in client, with its codes and tokens.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'moodle/site:config',
    ],
    'local_nucleushub_list_users_for_linking' => [
        'classname'   => 'local_nucleushub\external\list_users_for_linking',
        'description' => 'List hub accounts so Nucleus can offer to link spoke accounts to them.',
        'type'        => 'read',
        'ajax'        => false,
        'capabilities' => 'moodle/site:config',
    ],
    // Sign in with your organisation (ADR-023 section 5). Control plane
    // only, like the functions above.
    'local_nucleushub_configure_org_signin' => [
        'classname'   => 'local_nucleushub\external\configure_org_signin',
        'description' => 'Set up the organisation\'s own sign-in (Microsoft Entra ID, Google or OpenID Connect) on the hub.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'moodle/site:config',
    ],
    'local_nucleushub_disable_org_signin' => [
        'classname'   => 'local_nucleushub\external\disable_org_signin',
        'description' => 'Turn the organisation\'s own sign-in off on the hub, keeping its settings.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'moodle/site:config',
    ],
    'local_nucleushub_signin_status' => [
        'classname'   => 'local_nucleushub\external\signin_status',
        'description' => 'Report the hub\'s sign-in set-up: the organisation\'s provider and self-registration.',
        'type'        => 'read',
        'ajax'        => false,
        'capabilities' => 'moodle/site:config',
    ],
];

$services = [
    'Nucleus federation' => [
        'functions'       => [
            'local_nucleushub_list_courses',
            'local_nucleushub_list_families',
            'local_nucleushub_describe_version',
        ],
        'restrictedusers' => 1,
        'enabled'         => 1,
        'shortname'       => 'nucleus_federation',
        'downloadfiles'   => 1,
        'uploadfiles'     => 0,
    ],
    // Service the Nucleus control plane authenticates against to call
    // any of this plugin's external functions on the hub. Distinct
    // from `nucleus_federation` (which is what *spokes* use to call
    // the hub) so we can scope tokens differently per audience and
    // revoke control-plane access without breaking spoke pulls.
    'Nucleus control plane (hub)' => [
        'functions'       => [
            'local_nucleushub_list_courses',
            'local_nucleushub_register_spoke',
            'local_nucleushub_unregister_spoke',
            'local_nucleushub_publish_version',
            'local_nucleushub_list_families',
            'local_nucleushub_mark_deprecated',
            'local_nucleushub_describe_version',
            'local_nucleuscommon_get_tenant_stats',
            'local_nucleuscommon_provision_admin_account',
            'local_nucleushub_oidc_register_client',
            'local_nucleushub_oidc_delete_client',
            'local_nucleushub_list_users_for_linking',
            'local_nucleushub_configure_org_signin',
            'local_nucleushub_disable_org_signin',
            'local_nucleushub_signin_status',
        ],
        'restrictedusers' => 1,
        'enabled'         => 1,
        // Distinct from `nucleus_cp_spoke` because both plugins are
        // installed in every Moodle image (the Dockerfile bakes all
        // three local_nucleus* in). A shared shortname would collide
        // and only one plugin's function list would survive — so
        // we split. setup_cp_token.php takes `--role` and picks the
        // right one. (Retracts Slice 1a Decision 85.)
        'shortname'       => 'nucleus_cp_hub',
        'downloadfiles'   => 1,
        'uploadfiles'     => 0,
    ],
];
