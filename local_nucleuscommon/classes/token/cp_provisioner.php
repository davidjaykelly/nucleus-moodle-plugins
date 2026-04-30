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
 * Idempotent provisioner for the Moodle web-service token the Nucleus
 * control plane uses to call into this tenant's pod.
 *
 * Same logic that's been in setup_cp_token.php (managed-spoke
 * provisioning) lifted into a class so external-spoke joins can call
 * it without shelling out to a sub-process.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

namespace local_nucleuscommon\token;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/externallib.php');

/**
 * Provisions and returns the CP-side WS token for a given external
 * service shortname (`nucleus_cp_hub` or `nucleus_cp_spoke`).
 */
class cp_provisioner {

    /**
     * Ensure web services + REST are enabled, the named external
     * service exists, the admin user is authorised against it, and a
     * permanent token is minted (or reused). Returns the token.
     *
     * Idempotent — re-running is safe and returns the same token.
     *
     * @param string $shortname External service shortname, e.g.
     *                          'nucleus_cp_hub' or 'nucleus_cp_spoke'.
     * @return string Permanent token value (32 hex chars).
     * @throws \moodle_exception When the named service doesn't exist
     *                           (plugin not installed/upgraded).
     */
    public static function ensure_token(string $shortname): string {
        global $DB;

        // Elevate to admin so config edits + token mint succeed.
        \core\session\manager::set_user(get_admin());
        $admin = get_admin();

        // Web services + REST protocol on.
        if (!get_config('core', 'enablewebservices')) {
            set_config('enablewebservices', 1);
        }
        $existing = get_config('core', 'webserviceprotocols');
        $protocols = ($existing && is_string($existing)) ? explode(',', $existing) : [];
        if (!in_array('rest', $protocols, true)) {
            $protocols[] = 'rest';
            set_config('webserviceprotocols', implode(',', array_filter($protocols)));
        }

        // Locate the service. Missing means the plugin defining it
        // hasn't been installed / upgraded yet.
        $service = $DB->get_record('external_services', ['shortname' => $shortname]);
        if (!$service) {
            throw new \moodle_exception(
                'cpserviceunknown',
                'local_nucleuscommon',
                '',
                $shortname,
                'External service not found: ' . $shortname
            );
        }

        // Authorise admin against the (restrictedusers=1) service.
        if (!$DB->record_exists(
            'external_services_users',
            ['externalserviceid' => $service->id, 'userid' => $admin->id]
        )) {
            $DB->insert_record('external_services_users', (object) [
                'externalserviceid' => $service->id,
                'userid' => $admin->id,
                'timecreated' => time(),
            ]);
        }

        // Reuse first existing permanent token; otherwise mint.
        $tokens = $DB->get_records('external_tokens', [
            'userid' => $admin->id,
            'externalserviceid' => $service->id,
            'tokentype' => EXTERNAL_TOKEN_PERMANENT,
        ], 'timecreated ASC');
        if ($tokens) {
            return reset($tokens)->token;
        }
        return \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $service,
            $admin->id,
            \context_system::instance(),
            0,
            '',
            'Nucleus control plane'
        );
    }
}
