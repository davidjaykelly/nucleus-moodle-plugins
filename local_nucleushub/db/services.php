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
 * Web service declarations for local_nucleushub.
 *
 * Declares the five external functions that make up the hub side of the
 * Nucleus federation protocol, and the dedicated "Nucleus federation"
 * service that spokes authenticate against.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_nucleushub_list_courses' => [
        'classname'   => 'local_nucleushub\external\list_courses',
        'description' => 'Return courses flagged on the hub as available for federation.',
        'type'        => 'read',
        'ajax'        => false,
    ],
    'local_nucleushub_request_course_copy' => [
        'classname'   => 'local_nucleushub\external\request_course_copy',
        'description' => 'Mode A: trigger a backup of a hub course and return a retrievable reference.',
        'type'        => 'write',
        'ajax'        => false,
    ],
    'local_nucleushub_project_user' => [
        'classname'   => 'local_nucleushub\external\project_user',
        'description' => 'Mode B: create or update a shadow user record on the hub. Returns the hub user id.',
        'type'        => 'write',
        'ajax'        => false,
    ],
    'local_nucleushub_request_enrolment' => [
        'classname'   => 'local_nucleushub\external\request_enrolment',
        'description' => 'Mode B: enrol a projected user in a hub-hosted course.',
        'type'        => 'write',
        'ajax'        => false,
    ],
    'local_nucleushub_revoke_enrolment' => [
        'classname'   => 'local_nucleushub\external\revoke_enrolment',
        'description' => 'Mode B: unenrol a projected user from a hub-hosted course.',
        'type'        => 'write',
        'ajax'        => false,
    ],
    'local_nucleushub_register_spoke' => [
        'classname'   => 'local_nucleushub\external\register_spoke',
        'description' => 'Auto-config: register a spoke with this hub and return a permanent token for it to call back with.',
        'type'        => 'write',
        'ajax'        => false,
    ],
    'local_nucleushub_publish_version' => [
        'classname'   => 'local_nucleushub\external\publish_version',
        'description' => 'Publish a new version of a course family: backup the course, upload snapshot to the control plane, record version metadata.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/nucleushub:publish',
    ],
    'local_nucleushub_list_families' => [
        'classname'   => 'local_nucleushub\external\list_families',
        'description' => 'List every course family on this hub with its version history — used by the Nucleus portal.',
        'type'        => 'read',
        'ajax'        => false,
        'capabilities' => 'local/nucleushub:publish',
    ],
    'local_nucleushub_mark_deprecated' => [
        'classname'   => 'local_nucleushub\external\mark_deprecated',
        'description' => 'ADR-014 Phase 2: flip the deprecated flag on a published version and broadcast the change to spokes.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/nucleushub:publish',
    ],
    'local_nucleushub_list_projusers' => [
        'classname'   => 'local_nucleushub\external\list_projusers',
        'description' => 'Phase B1: list Mode B shadow users for the portal Identity surface (optional cpspokeid filter).',
        'type'        => 'read',
        'ajax'        => false,
    ],
    'local_nucleushub_revoke_user' => [
        'classname'   => 'local_nucleushub\external\revoke_user',
        'description' => 'Phase B1: spoke-initiated GDPR cascade — delete the shadow user + projusers row.',
        'type'        => 'write',
        'ajax'        => false,
    ],
];

$services = [
    'Nucleus federation' => [
        'functions'       => [
            'local_nucleushub_list_courses',
            'local_nucleushub_list_families',
            'local_nucleushub_request_course_copy',
            'local_nucleushub_project_user',
            'local_nucleushub_request_enrolment',
            'local_nucleushub_revoke_enrolment',
            'local_nucleushub_revoke_user',
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
            'local_nucleushub_request_course_copy',
            'local_nucleushub_project_user',
            'local_nucleushub_request_enrolment',
            'local_nucleushub_revoke_enrolment',
            'local_nucleushub_register_spoke',
            'local_nucleushub_publish_version',
            'local_nucleushub_list_families',
            'local_nucleushub_mark_deprecated',
            'local_nucleushub_list_projusers',
            'local_nucleushub_revoke_user',
            'local_nucleuscommon_get_tenant_stats',
            'local_nucleuscommon_set_federation_mode',
            'local_nucleuscommon_provision_admin_account',
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
