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
 * Web service declarations for local_nucleusspoke.
 *
 * Registers the `nucleus_cp` service the Nucleus control plane
 * authenticates against, plus the external functions it can call.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_nucleusspoke_pull_course' => [
        'classname'   => 'local_nucleusspoke\external\pull_course',
        'description' => 'Mode A: pull a hub course MBZ and restore it as a new local course.',
        'type'        => 'write',
        'ajax'        => false,
    ],
    'local_nucleusspoke_configure_hub' => [
        'classname'   => 'local_nucleusspoke\external\configure_hub',
        'description' => 'Auto-config: store the hub WS URL + token + cluster-internal connect URL.',
        'type'        => 'write',
        'ajax'        => false,
    ],
    'local_nucleusspoke_pull_version' => [
        'classname'   => 'local_nucleusspoke\external\pull_version',
        'description' => 'ADR-014: download a course-family version snapshot from the control plane and restore it as a new local course.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/nucleusspoke:pull',
    ],
    'local_nucleusspoke_receive_notification' => [
        'classname'   => 'local_nucleusspoke\external\receive_notification',
        'description' => 'ADR-014: record a version-available notification fanned out from the control plane.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/nucleusspoke:pull',
    ],
    'local_nucleusspoke_list_instances' => [
        'classname'   => 'local_nucleusspoke\external\list_instances',
        'description' => 'ADR-014: list pulled instances on this spoke (plus pending notification counts per family) for the Nucleus portal.',
        'type'        => 'read',
        'ajax'        => false,
        'capabilities' => 'local/nucleusspoke:pull',
    ],
    'local_nucleusspoke_notification_action' => [
        'classname'   => 'local_nucleusspoke\external\notification_action',
        'description' => 'ADR-014 Phase 2: snooze / dismiss / reactivate a pending version notification.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/nucleusspoke:pull',
    ],
    'local_nucleusspoke_receive_deprecation' => [
        'classname'   => 'local_nucleusspoke\external\receive_deprecation',
        'description' => 'ADR-014 Phase 2: mirror a hub-side version deprecation flag locally.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/nucleusspoke:pull',
    ],
    'local_nucleusspoke_promote_instance' => [
        'classname'   => 'local_nucleusspoke\external\promote_instance',
        'description' => 'ADR-014 Phase 2: promote a staging instance to active.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/nucleusspoke:pull',
    ],
    'local_nucleusspoke_instance_action' => [
        'classname'   => 'local_nucleusspoke\external\instance_action',
        'description' => 'ADR-014 Phase 2: close / reopen actions on a pulled instance.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/nucleusspoke:pull',
    ],
    'local_nucleusspoke_apply_completion' => [
        'classname'   => 'local_nucleusspoke\external\apply_completion',
        'description' => 'Phase B1: apply a completion.v1 envelope routed by the control plane.',
        'type'        => 'write',
        'ajax'        => false,
    ],
    'local_nucleusspoke_revoke_user' => [
        'classname'   => 'local_nucleusspoke\external\revoke_user',
        'description' => 'Phase B1 Step 5: hub-initiated GDPR cascade — unenrol a user from this spoke\'s Mode B placeholder courses.',
        'type'        => 'write',
        'ajax'        => false,
    ],
    'local_nucleusspoke_preview_pull' => [
        'classname'   => 'local_nucleusspoke\external\preview_pull',
        'description' => 'ADR-021 v1.1: run the dependency pre-flight for a hub version without pulling. Returns blockers + Tier C notes for the operator portal.',
        'type'        => 'read',
        'ajax'        => false,
        'capabilities' => 'local/nucleusspoke:pull',
    ],
];

$services = [
    'Nucleus control plane (spoke)' => [
        'functions'       => [
            'local_nucleusspoke_pull_course',
            'local_nucleusspoke_configure_hub',
            'local_nucleusspoke_pull_version',
            'local_nucleusspoke_receive_notification',
            'local_nucleusspoke_list_instances',
            'local_nucleusspoke_notification_action',
            'local_nucleusspoke_receive_deprecation',
            'local_nucleusspoke_promote_instance',
            'local_nucleusspoke_instance_action',
            'local_nucleusspoke_apply_completion',
            'local_nucleusspoke_revoke_user',
            'local_nucleusspoke_preview_pull',
            'local_nucleuscommon_get_tenant_stats',
            'local_nucleuscommon_set_federation_mode',
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
