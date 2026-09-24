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
 * Web service declarations for local_nucleuscommon.
 *
 * Common only declares functions. The services are owned by the
 * hub and spoke plugins, which list most of these functions in their
 * control-plane services (`nucleus_cp_hub` and `nucleus_cp_spoke`).
 * get_usage_history adds itself to both through its `services` key
 * instead, which Moodle applies at the end of every upgrade.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_nucleuscommon_get_tenant_stats' => [
        'classname'   => 'local_nucleuscommon\external\get_tenant_stats',
        'description' => 'Counts of users, courses, enrolments and users active in the last 24 hours.',
        'type'        => 'read',
        'ajax'        => false,
    ],
    'local_nucleuscommon_get_usage_history' => [
        'classname'   => 'local_nucleuscommon\external\get_usage_history',
        'description' => 'The people figures this site recorded every 10 minutes, as the highest value in each time bucket.',
        'type'        => 'read',
        'ajax'        => false,
        // The control-plane services only, as get_tenant_stats is.
        'services'    => ['nucleus_cp_hub', 'nucleus_cp_spoke'],
    ],
    'local_nucleuscommon_provision_admin_account' => [
        'classname'   => 'local_nucleuscommon\external\provision_admin_account',
        'description' => 'Create or update the customer\'s site administrator account and return a one-time link to set its password.',
        'type'        => 'write',
        'ajax'        => false,
    ],
];

$services = [];
