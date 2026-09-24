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
 * Web service declarations for local_nucleusspoke.
 *
 * Registers the `nucleus_cp` service the Nucleus control plane
 * authenticates against, plus the external functions it can call.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_nucleusspoke_configure_hub' => [
        'classname'   => 'local_nucleusspoke\external\configure_hub',
        'description' => 'Store the hub\'s address and token on this spoke.',
        'type'        => 'write',
        'ajax'        => false,
    ],
    'local_nucleusspoke_pull_version' => [
        'classname'   => 'local_nucleusspoke\external\pull_version',
        'description' => 'Pull a published version of a course family as a new course on this spoke.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/nucleusspoke:pull',
    ],
    'local_nucleusspoke_receive_notification' => [
        'classname'   => 'local_nucleusspoke\external\receive_notification',
        'description' => 'Record that a new version is available to pull.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/nucleusspoke:pull',
    ],
    'local_nucleusspoke_list_instances' => [
        'classname'   => 'local_nucleusspoke\external\list_instances',
        'description' => 'List the courses this spoke has pulled, with the updates waiting for each course family.',
        'type'        => 'read',
        'ajax'        => false,
        'capabilities' => 'local/nucleusspoke:pull',
    ],
    'local_nucleusspoke_notification_action' => [
        'classname'   => 'local_nucleusspoke\external\notification_action',
        'description' => 'Snooze, dismiss or bring back an update.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/nucleusspoke:pull',
    ],
    'local_nucleusspoke_receive_deprecation' => [
        'classname'   => 'local_nucleusspoke\external\receive_deprecation',
        'description' => 'Record that the hub has deprecated a version, or restored it.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/nucleusspoke:pull',
    ],
    'local_nucleusspoke_promote_instance' => [
        'classname'   => 'local_nucleusspoke\external\promote_instance',
        'description' => 'Make a course that was pulled hidden visible to learners.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/nucleusspoke:pull',
    ],
    'local_nucleusspoke_instance_action' => [
        'classname'   => 'local_nucleusspoke\external\instance_action',
        'description' => 'Close a pulled course to enrolment, or reopen it.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/nucleusspoke:pull',
    ],
    'local_nucleusspoke_preview_pull' => [
        'classname'   => 'local_nucleusspoke\external\preview_pull',
        'description' => 'Check whether this spoke can pull a version, without pulling it.',
        'type'        => 'read',
        'ajax'        => false,
        'capabilities' => 'local/nucleusspoke:pull',
    ],
    // Sign in with the hub (ADR-023). The sign-in itself is auth_nucleus;
    // these fail cleanly when it isn't installed.
    'local_nucleusspoke_configure_signin' => [
        'classname'   => 'local_nucleusspoke\external\configure_signin',
        'description' => 'Set up sign-in with the hub on this spoke and turn it on.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'moodle/site:config',
    ],
    'local_nucleusspoke_disable_signin' => [
        'classname'   => 'local_nucleusspoke\external\disable_signin',
        'description' => 'Turn sign-in with the hub off on this spoke and give hub accounts their own login back.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'moodle/site:config',
    ],
    'local_nucleusspoke_list_accounts_for_linking' => [
        'classname'   => 'local_nucleusspoke\external\list_accounts_for_linking',
        'description' => 'List this spoke\'s accounts for linking to hub accounts.',
        'type'        => 'read',
        'ajax'        => false,
        'capabilities' => 'moodle/site:config',
    ],
    'local_nucleusspoke_apply_links' => [
        'classname'   => 'local_nucleusspoke\external\apply_links',
        'description' => 'Link existing accounts on this spoke to hub accounts.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'moodle/site:config',
    ],
    'local_nucleusspoke_signin_status' => [
        'classname'   => 'local_nucleusspoke\external\signin_status',
        'description' => 'Report whether sign-in with the hub is on for this spoke.',
        'type'        => 'read',
        'ajax'        => false,
        'capabilities' => 'moodle/site:config',
    ],
];

$services = [
    'Nucleus control plane (spoke)' => [
        'functions'       => [
            'local_nucleusspoke_configure_hub',
            'local_nucleusspoke_pull_version',
            'local_nucleusspoke_receive_notification',
            'local_nucleusspoke_list_instances',
            'local_nucleusspoke_notification_action',
            'local_nucleusspoke_receive_deprecation',
            'local_nucleusspoke_promote_instance',
            'local_nucleusspoke_instance_action',
            'local_nucleusspoke_preview_pull',
            'local_nucleusspoke_configure_signin',
            'local_nucleusspoke_disable_signin',
            'local_nucleusspoke_list_accounts_for_linking',
            'local_nucleusspoke_apply_links',
            'local_nucleusspoke_signin_status',
            'local_nucleuscommon_get_tenant_stats',
            'local_nucleuscommon_provision_admin_account',
        ],
        'restrictedusers' => 1,
        'enabled'         => 1,
        // See the matching comment in local_nucleushub/db/services.php
        // — both plugins ship in every image so the per-role split
        // avoids shortname collision.
        'shortname'       => 'nucleus_cp_spoke',
        'downloadfiles'   => 0,
        'uploadfiles'     => 0,
    ],
];
