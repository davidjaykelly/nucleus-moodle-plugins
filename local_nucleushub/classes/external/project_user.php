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
 * External function: local_nucleushub_project_user.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleushub\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_nucleuscommon\events\publisher;

defined('MOODLE_INTERNAL') || die();

/**
 * Mode B: idempotent upsert of a shadow user for a spoke-owned identity.
 *
 * The spoke's authoritative user never authenticates directly to the hub;
 * instead the hub creates a `nologin` stand-in keyed by
 * (spokeid, spokeuserid). Subsequent `request_enrolment` calls reference
 * the returned `hubuserid`.
 *
 * Phase 0 hardcodes the caller's spokeid to the single active row in
 * local_nucleushub_spokes. Phase 1 resolves spokeid from the bearer token.
 */
class project_user extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'spokeuserid' => new external_value(PARAM_INT, 'User id on the calling spoke'),
            'username'    => new external_value(PARAM_USERNAME, 'Spoke-side username (for reference only)'),
            'email'       => new external_value(PARAM_EMAIL, 'Spoke-side email'),
            'firstname'   => new external_value(PARAM_TEXT, 'First name'),
            'lastname'    => new external_value(PARAM_TEXT, 'Last name'),
        ]);
    }

    /**
     * @param int $spokeuserid
     * @param string $username
     * @param string $email
     * @param string $firstname
     * @param string $lastname
     * @return array
     */
    public static function execute(int $spokeuserid, string $username, string $email,
            string $firstname, string $lastname): array {
        global $CFG, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'spokeuserid' => $spokeuserid,
            'username'    => $username,
            'email'       => $email,
            'firstname'   => $firstname,
            'lastname'    => $lastname,
        ]);

        require_once($CFG->dirroot . '/user/lib.php');

        $spoke = self::resolve_caller_spoke();

        // Idempotency check: already projected?
        $existing = $DB->get_record('local_nucleushub_projusers', [
            'spokeid'     => $spoke->id,
            'spokeuserid' => $params['spokeuserid'],
        ]);
        if ($existing) {
            // Refresh denormalised fields if they've drifted.
            if ($existing->spokeusername !== $params['username']
                    || $existing->spokeemail !== $params['email']) {
                $DB->set_field('local_nucleushub_projusers', 'spokeusername',
                    $params['username'], ['id' => $existing->id]);
                $DB->set_field('local_nucleushub_projusers', 'spokeemail',
                    $params['email'], ['id' => $existing->id]);
            }
            return [
                'hubuserid' => (int)$existing->hubuserid,
                'created'   => false,
            ];
        }

        // Create a nologin shadow user. Username must be unique hub-wide, so
        // we derive it from the (spokeid, spokeuserid) tuple — deterministic
        // and collision-free across spokes.
        $shadowusername = sprintf('nucleus_s%d_u%d', $spoke->id, $params['spokeuserid']);

        $user = (object) [
            'auth'       => 'nologin',
            'username'   => $shadowusername,
            'email'      => $params['email'],
            'firstname'  => $params['firstname'],
            'lastname'   => $params['lastname'],
            'password'   => '',
            'confirmed'  => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
            'lang'       => $CFG->lang ?? 'en',
            'timezone'   => '99',
        ];
        $hubuserid = user_create_user($user, false, false);

        $now = time();
        $DB->insert_record('local_nucleushub_projusers', (object) [
            'spokeid'       => $spoke->id,
            'spokeuserid'   => $params['spokeuserid'],
            'hubuserid'     => $hubuserid,
            'spokeusername' => $params['username'],
            'spokeemail'    => $params['email'],
            'timecreated'   => $now,
        ]);

        // Phase B1 Step 3: tell CP about the new shadow user so the
        // portal Mode B feed can show "alice was projected at T".
        // Drop the publish silently on legacy spoke rows that
        // pre-date the cpspokeid backfill — same pattern as
        // completion_publisher; CP audits the drop on its side
        // when it sees the empty field.
        $cpspokeid = (string)($spoke->cpspokeid ?? '');
        if ($cpspokeid !== '') {
            try {
                publisher::publish(
                    'mode_b_user_projected.v1',
                    'hub',
                    'cp',
                    [
                        'cp_spoke_id'   => $cpspokeid,
                        'spoke_id'      => (int)$spoke->id,
                        'spoke_name'    => (string)$spoke->name,
                        'hub_user_id'   => (int)$hubuserid,
                        'spoke_user_id' => (int)$params['spokeuserid'],
                        'username'      => (string)$params['username'],
                        'email'         => (string)$params['email'],
                        'projected_at'  => $now,
                    ]
                );
            } catch (\Throwable $e) {
                // Audit-publish failure must not fail the projection.
                debugging('Nucleus: mode_b_user_projected publish failed: ' . $e->getMessage(),
                    DEBUG_DEVELOPER);
            }
        }

        return [
            'hubuserid' => (int)$hubuserid,
            'created'   => true,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'hubuserid' => new external_value(PARAM_INT, 'Hub-side mdl_user.id of the shadow user'),
            'created'   => new external_value(PARAM_BOOL, 'True if this call created the shadow user; false if it already existed'),
        ]);
    }

    /**
     * Resolve which spoke is calling. Phase 0 assumes exactly one active
     * spoke (the bootstrap creates it). Phase 1 will map the bearer token
     * to a spoke row.
     *
     * @return \stdClass Spoke row from local_nucleushub_spokes.
     * @throws \moodle_exception If no active spoke is registered.
     */
    private static function resolve_caller_spoke(): \stdClass {
        global $DB;
        $spoke = $DB->get_record('local_nucleushub_spokes', ['status' => 'active'], '*', IGNORE_MULTIPLE);
        if (!$spoke) {
            throw new \moodle_exception('nospokeregistered', 'local_nucleushub');
        }
        return $spoke;
    }
}
