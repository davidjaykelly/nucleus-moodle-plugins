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
 * External function: local_nucleushub_register_spoke.
 *
 * Idempotent: ensures the hub knows about a spoke and has a permanent
 * web-service token the spoke can use to call back into the hub
 * (`nucleus_federation` service). Replaces the manual Phase-0
 * `seed_phase0.php` step for production tenants — the control plane
 * calls this once per spoke at provision time.
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

defined('MOODLE_INTERNAL') || die();

class register_spoke extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'wwwroot'    => new external_value(PARAM_URL, 'Spoke browser-facing URL (e.g. https://acme.example.com)'),
            'name'       => new external_value(PARAM_TEXT, 'Operator-facing spoke name / slug'),
            'cpspokeid'  => new external_value(
                PARAM_RAW,
                'Control-plane Spoke.id (cuid). Optional — older callers can omit.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    public static function execute(string $wwwroot, string $name, string $cpspokeid = ''): array {
        global $DB, $CFG;

        $params = self::validate_parameters(self::execute_parameters(),
            ['wwwroot' => $wwwroot, 'name' => $name, 'cpspokeid' => $cpspokeid]);
        $wwwroot = trim($params['wwwroot'], '/');
        $name = trim($params['name']);
        $cpspokeid = trim($params['cpspokeid']);

        // 1. Ensure WS + REST are enabled (idempotent — Moodle's
        //    `set_config` is a write-then-rebuild, only does work
        //    when the value actually changes).
        if (!get_config('core', 'enablewebservices')) {
            set_config('enablewebservices', 1);
        }
        $existing = get_config('core', 'webserviceprotocols');
        $protocols = ($existing && is_string($existing)) ? explode(',', $existing) : [];
        if (!in_array('rest', $protocols, true)) {
            $protocols[] = 'rest';
            set_config('webserviceprotocols', implode(',', array_filter($protocols)));
        }

        // 2. nucleus_federation service must exist (declared in
        //    db/services.php; available after plugin upgrade).
        $service = $DB->get_record('external_services',
            ['shortname' => 'nucleus_federation'], '*', MUST_EXIST);

        // 3. Authorise the admin user against the service (the
        //    hub-side identity that signs spoke→hub calls).
        //    `restrictedusers=1` → external_services_users gates use.
        $admin = get_admin();
        if (!$DB->record_exists('external_services_users',
                ['externalserviceid' => $service->id, 'userid' => $admin->id])) {
            $DB->insert_record('external_services_users', (object) [
                'externalserviceid' => $service->id,
                'userid'            => $admin->id,
                'timecreated'       => time(),
            ]);
        }

        // 4. Mint or reuse a permanent token for the admin user
        //    against this service. All spokes share the admin
        //    token in this design — Phase 0 pattern. Per-spoke
        //    tokens land in a future hardening pass.
        $tokens = $DB->get_records('external_tokens', [
            'userid'            => $admin->id,
            'externalserviceid' => $service->id,
            'tokentype'         => EXTERNAL_TOKEN_PERMANENT,
        ], 'timecreated ASC');
        if ($tokens) {
            $tokenvalue = reset($tokens)->token;
        } else {
            require_once($CFG->libdir . '/externallib.php');
            $tokenvalue = \core_external\util::generate_token(
                EXTERNAL_TOKEN_PERMANENT,
                $service,
                $admin->id,
                \context_system::instance(),
                0,
                '',
                'Nucleus federation (control-plane spoke registration)'
            );
        }

        // 5. Upsert the spoke row in `local_nucleushub_spokes`.
        //    `wwwroot` is the natural key (the browser-facing URL
        //    is unique per spoke). Mirrors the token onto the row
        //    so other parts of the hub-side code that read it for
        //    auditing have a consistent view.
        $now = time();
        $existing = $DB->get_record('local_nucleushub_spokes', ['wwwroot' => $wwwroot]);
        if ($existing) {
            $update = (object) [
                'id'           => $existing->id,
                'name'         => $name,
                'token'        => $tokenvalue,
                'status'       => 'active',
                'timemodified' => $now,
            ];
            if ($cpspokeid !== '') {
                $update->cpspokeid = $cpspokeid;
            }
            $DB->update_record('local_nucleushub_spokes', $update);
            $spokeid = (int)$existing->id;
        } else {
            $spokeid = (int)$DB->insert_record('local_nucleushub_spokes', (object) [
                'name'         => $name,
                'wwwroot'      => $wwwroot,
                'token'        => $tokenvalue,
                'status'       => 'active',
                'cpspokeid'    => $cpspokeid !== '' ? $cpspokeid : null,
                'timecreated'  => $now,
                'timemodified' => $now,
            ]);
        }

        return [
            'token'   => $tokenvalue,
            'spokeId' => $spokeid,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'token'   => new external_value(PARAM_RAW, 'Token the spoke uses on hub WS calls.'),
            'spokeId' => new external_value(PARAM_INT, 'local_nucleushub_spokes row id.'),
        ]);
    }
}
