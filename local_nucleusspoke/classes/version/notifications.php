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
 * Notification state machine for course-version updates
 * (ADR-014 Phase 2, snooze + dismiss).
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

namespace local_nucleusspoke\version;

defined('MOODLE_INTERNAL') || die();

/**
 * State transitions for `local_nucleusspoke_notification` rows.
 *
 *              pull → resolved   (handled by puller)
 *             ┌────────────────┐
 *             │                │
 *   pending ──┼── snooze ──────┼──▶ snoozed
 *             │                │       │
 *             │                │   unsnooze (auto)
 *             │                │       │
 *             └── dismiss ─────┴── dismissed
 *
 * resolved / dismissed are terminal. Snoozed rows auto-return to
 * pending via the scheduled unsnooze task once `snoozeuntil` passes.
 * Manual "reactivate" is available for operators who change their
 * mind without waiting for the timer.
 */
class notifications {

    public const ACTIONS = ['snooze', 'dismiss', 'reactivate'];

    public const DEFAULT_SNOOZE_SECONDS = 7 * 24 * 60 * 60; // 7 days.

    /**
     * Apply a state action to a notification row. Returns the
     * fresh row post-update.
     *
     * @param int $notificationid
     * @param string $action One of ACTIONS.
     * @param int|null $snoozeuntil Unix ts for 'snooze' (default: +7 days from now).
     * @param int $userid Actor (for audit fields on terminal states).
     * @return \stdClass Updated row.
     * @throws \moodle_exception
     */
    public static function apply(
        int $notificationid,
        string $action,
        ?int $snoozeuntil,
        int $userid
    ): \stdClass {
        global $DB;

        if (!in_array($action, self::ACTIONS, true)) {
            throw new \moodle_exception(
                'notificationbadaction',
                'local_nucleusspoke',
                '',
                $action
            );
        }
        $row = $DB->get_record(
            'local_nucleusspoke_notification',
            ['id' => $notificationid],
            '*',
            MUST_EXIST
        );

        $now = time();
        $update = (object) ['id' => $row->id];

        switch ($action) {
            case 'snooze':
                self::require_state($row, ['pending']);
                $until = $snoozeuntil ?: ($now + self::DEFAULT_SNOOZE_SECONDS);
                if ($until <= $now) {
                    throw new \moodle_exception(
                        'notificationsnoozepast',
                        'local_nucleusspoke'
                    );
                }
                $update->state = 'snoozed';
                $update->snoozeuntil = $until;
                $update->timeresolved = null;
                $update->resolvedbyid = null;
                break;

            case 'dismiss':
                self::require_state($row, ['pending', 'snoozed']);
                $update->state = 'dismissed';
                $update->snoozeuntil = null;
                $update->timeresolved = $now;
                $update->resolvedbyid = $userid;
                break;

            case 'reactivate':
                self::require_state($row, ['snoozed']);
                $update->state = 'pending';
                $update->snoozeuntil = null;
                $update->timeresolved = null;
                $update->resolvedbyid = null;
                break;
        }

        $DB->update_record('local_nucleusspoke_notification', $update);
        return $DB->get_record(
            'local_nucleusspoke_notification',
            ['id' => $notificationid],
            '*',
            MUST_EXIST
        );
    }

    /**
     * Auto-unsnooze pass: move every snoozed notification whose
     * snoozeuntil has passed back to pending. Called by the
     * scheduled task.
     *
     * @param int|null $now Unix time; defaults to time(). Injectable
     *                      for tests.
     * @return int Count of rows unsnoozed.
     */
    public static function unsnooze_due(?int $now = null): int {
        global $DB;
        $now = $now ?? time();
        $rows = $DB->get_records_select(
            'local_nucleusspoke_notification',
            "state = :state AND snoozeuntil IS NOT NULL AND snoozeuntil <= :now",
            ['state' => 'snoozed', 'now' => $now]
        );
        $count = 0;
        foreach ($rows as $row) {
            $DB->update_record('local_nucleusspoke_notification', (object) [
                'id' => $row->id,
                'state' => 'pending',
                'snoozeuntil' => null,
            ]);
            $count++;
        }
        return $count;
    }

    /**
     * @param \stdClass $row
     * @param string[] $allowed
     * @throws \moodle_exception
     */
    private static function require_state(\stdClass $row, array $allowed): void {
        if (!in_array($row->state, $allowed, true)) {
            throw new \moodle_exception(
                'notificationbadstate',
                'local_nucleusspoke',
                '',
                (object) ['from' => $row->state, 'allowed' => implode('|', $allowed)]
            );
        }
    }
}
