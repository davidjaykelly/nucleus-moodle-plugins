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
 * External function: local_nucleusspoke_notification_action
 * (ADR-014 Phase 2).
 *
 * Apply a state transition to a pending / snoozed notification:
 * snooze (until ts, optional), dismiss, or reactivate a snoozed row.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleusspoke\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_nucleusspoke\version\notifications;

defined('MOODLE_INTERNAL') || die();

class notification_action extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'notificationid' => new external_value(PARAM_INT, 'local_nucleusspoke_notification.id'),
            'action' => new external_value(PARAM_ALPHA, 'snooze | dismiss | reactivate'),
            'snoozeuntil' => new external_value(
                PARAM_INT,
                'Unix time to auto-unsnooze. Only valid for action=snooze. 0 = default (+7 days).',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    public static function execute(
        int $notificationid,
        string $action,
        int $snoozeuntil
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'notificationid' => $notificationid,
            'action' => $action,
            'snoozeuntil' => $snoozeuntil,
        ]);

        // Auth by service declaration — see the other spoke external
        // functions for the rationale.

        $row = notifications::apply(
            (int) $params['notificationid'],
            (string) $params['action'],
            (int) $params['snoozeuntil'] > 0 ? (int) $params['snoozeuntil'] : null,
            (int) $USER->id
        );

        return [
            'id' => (int) $row->id,
            'state' => (string) $row->state,
            'snoozeuntil' => (int) ($row->snoozeuntil ?? 0),
            'timeresolved' => (int) ($row->timeresolved ?? 0),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Notification id.'),
            'state' => new external_value(PARAM_ALPHAEXT, 'New state.'),
            'snoozeuntil' => new external_value(PARAM_INT, 'Unix time of auto-unsnooze (0 when not snoozed).'),
            'timeresolved' => new external_value(PARAM_INT, 'Unix time of terminal transition (0 when still active).'),
        ]);
    }
}
