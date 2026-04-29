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
 * Web service declarations for local_nucleuscommon.
 *
 * Common only declares functions — services are owned by the
 * hub / spoke plugins which add these function names to their
 * own `nucleus_cp` service definition.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_nucleuscommon_get_tenant_stats' => [
        'classname'   => 'local_nucleuscommon\external\get_tenant_stats',
        'description' => 'Cheap aggregate counts: users / courses / enrolments / active-24h.',
        'type'        => 'read',
        'ajax'        => false,
    ],
    'local_nucleuscommon_set_federation_mode' => [
        'classname'   => 'local_nucleuscommon\external\set_federation_mode',
        'description' => 'Phase B1 Step 1: CP pushes Federation.mode onto this Moodle.',
        'type'        => 'write',
        'ajax'        => false,
    ],
    'local_nucleuscommon_provision_admin_account' => [
        'classname'   => 'local_nucleuscommon\external\provision_admin_account',
        'description' => 'ADR-016 Option A: provision a customer-facing site-admin Moodle account; returns a single-use password-reset URL.',
        'type'        => 'write',
        'ajax'        => false,
    ],
];

$services = [];
