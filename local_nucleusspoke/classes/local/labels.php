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

namespace local_nucleusspoke\local;

/**
 * Words for the values the spoke stores (states, types of change).
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class labels {
    /**
     * A pulled course's state: Live, Hidden, Closed to enrolment, Archived.
     *
     * @param string $state Stored value (active, staging, closed-to-enrolment, archived).
     * @return string
     */
    public static function instance_state(string $state): string {
        $keys = [
            'active' => 'state_active',
            'staging' => 'state_staging',
            'closed-to-enrolment' => 'state_closed',
            'archived' => 'state_archived',
        ];
        return isset($keys[$state]) ? get_string($keys[$state], 'local_nucleusspoke') : $state;
    }

    /**
     * An update's state: Waiting, Snoozed, Dismissed, Pulled.
     *
     * @param string $state Stored value (pending, snoozed, dismissed, resolved).
     * @return string
     */
    public static function notification_state(string $state): string {
        $keys = [
            'pending' => 'notifstate_pending',
            'snoozed' => 'notifstate_snoozed',
            'dismissed' => 'notifstate_dismissed',
            'resolved' => 'notifstate_resolved',
        ];
        return isset($keys[$state]) ? get_string($keys[$state], 'local_nucleusspoke') : $state;
    }

    /**
     * A type of change: Patch, Minor, Major.
     *
     * @param string $severity Stored value (patch, minor, major).
     * @return string
     */
    public static function severity(string $severity): string {
        if (in_array($severity, ['patch', 'minor', 'major'], true)) {
            return get_string('severity_' . $severity, 'local_nucleuscommon');
        }
        return $severity;
    }
}
