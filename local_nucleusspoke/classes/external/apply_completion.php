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
 * External function: local_nucleusspoke_apply_completion.
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
use local_nucleusspoke\handler\completion_applier;

defined('MOODLE_INTERNAL') || die();

/**
 * CP-driven completion application (Phase B1 Step 2).
 *
 * Replaces the Phase 0 one-shot CLI consumer (`cli/consume_events.php`)
 * for the production path: CP subscribes to `completion.v1` on the
 * shared Redis stream, looks up the target spoke, and calls this WS
 * function. We delegate to the existing `completion_applier` (kept as
 * the single source of truth so the CLI fallback still works).
 *
 * Idempotency comes from `completion_applier::apply()` — it relies on
 * `completion_completion::mark_complete()` no-opping when a row exists,
 * so the BullMQ retry path is safe.
 */
class apply_completion extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'hub_user_id'   => new external_value(PARAM_INT, 'Hub-side mdl_user.id (shadow user) the completion was recorded against.'),
            'spoke_user_id' => new external_value(PARAM_INT, 'Spoke-side mdl_user.id of the local user.'),
            'course_id'     => new external_value(PARAM_INT, 'Hub course id (we resolve to local via local_nucleusspoke_courses).'),
            'completed_at'  => new external_value(PARAM_INT, 'Unix time the hub recorded the completion.'),
            'envelope_id'   => new external_value(PARAM_RAW, 'Envelope id from the publisher (logging only).', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * @return array{status: string, envelope_id: string}
     */
    public static function execute(
        int $hub_user_id,
        int $spoke_user_id,
        int $course_id,
        int $completed_at,
        string $envelope_id = ''
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'hub_user_id'   => $hub_user_id,
            'spoke_user_id' => $spoke_user_id,
            'course_id'     => $course_id,
            'completed_at'  => $completed_at,
            'envelope_id'   => $envelope_id,
        ]);

        // Reconstitute the envelope shape the applier expects. The
        // applier reads `payload.*` exclusively, so the outer fields
        // are decorative — keep them populated for log readability.
        $envelope = [
            'type'        => 'completion.v1',
            'id'          => $params['envelope_id'],
            'source'      => 'cp',
            'destination' => 'spoke',
            'timestamp'   => time(),
            'payload'     => [
                'hub_user_id'   => $params['hub_user_id'],
                'spoke_user_id' => $params['spoke_user_id'],
                'course_id'     => $params['course_id'],
                'completed_at'  => $params['completed_at'],
            ],
        ];

        $status = completion_applier::apply($envelope);

        return [
            'status'      => $status,
            'envelope_id' => $params['envelope_id'],
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'      => new external_value(PARAM_ALPHAEXT, 'applied | unknown_course | unknown_user | bad_envelope'),
            'envelope_id' => new external_value(PARAM_RAW, 'Echoes the envelope id for log correlation.'),
        ]);
    }
}
