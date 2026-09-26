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
 * External function: local_nucleusspoke_configure_hub.
 *
 * Stores the plugin settings the spoke needs to call its federation
 * hub. Called by the control plane right after a spoke is provisioned,
 * with values it captured from the hub side, and again whenever the
 * hub's address changes.
 *
 * `hubissuer` is the hub's pinned sign-in issuer. It lets the hub move to
 * a new address (a custom domain) while its issuer, and so every account
 * link on this spoke, stays the same. Like `hubconnecturl`, every call
 * sets it: leaving it out (or sending '') stores '', which means the
 * issuer is {hubwwwroot}/local/nucleushub/oidc, today's behaviour. It
 * doesn't touch auth_nucleus's settings or links.
 *
 * Idempotent: just `set_config` calls.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleusspoke\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_nucleuscommon\transport\hub_http;

defined('MOODLE_INTERNAL') || die();

class configure_hub extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'hubwwwroot'    => new external_value(PARAM_URL, 'Hub browser-facing URL (e.g. https://hub.example.com).'),
            'hubtoken'      => new external_value(PARAM_RAW, 'WS token issued by the hub for this spoke.'),
            'hubconnecturl' => new external_value(
                PARAM_URL,
                'Optional: cluster-internal URL the spoke pod uses to reach the hub (e.g. http://hub.nucleus-hub.svc.cluster.local). Defaults to empty.',
                VALUE_DEFAULT,
                ''
            ),
            'hubissuer'     => new external_value(
                PARAM_URL,
                'Optional: the hub\'s pinned sign-in issuer (e.g. https://hub.example.com/local/nucleushub/oidc), '
                    . 'which stays the same when the hub moves. Empty means {hubwwwroot}/local/nucleushub/oidc.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    public static function execute(string $hubwwwroot, string $hubtoken, string $hubconnecturl = '',
            string $hubissuer = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'hubwwwroot'    => $hubwwwroot,
            'hubtoken'      => $hubtoken,
            'hubconnecturl' => $hubconnecturl,
            'hubissuer'     => $hubissuer,
        ]);

        // Checked before anything is stored, so a bad issuer changes nothing.
        $issuer = rtrim($params['hubissuer'], '/');
        if ($issuer !== '' && !self::is_issuer_url($issuer)) {
            throw new \invalid_parameter_exception(
                'hubissuer must be an absolute http(s) URL ending in ' . hub_http::OIDC_PATH);
        }

        set_config('hubwwwroot', rtrim($params['hubwwwroot'], '/'), 'local_nucleusspoke');
        set_config('hubtoken', $params['hubtoken'], 'local_nucleusspoke');
        // Empty string clears any previous value, falling back to
        // hubwwwroot for outbound connections.
        set_config('hubconnecturl', rtrim($params['hubconnecturl'], '/'), 'local_nucleusspoke');
        // Empty string clears any previous value, falling back to
        // {hubwwwroot}/local/nucleushub/oidc as the issuer.
        set_config('hubissuer', $issuer, 'local_nucleusspoke');

        return ['ok' => true];
    }

    /**
     * Does this look like a hub's issuer: an absolute http(s) URL with a
     * host, no query or fragment, whose path ends in /local/nucleushub/oidc?
     *
     * @param string $url Without a trailing slash.
     * @return bool
     */
    private static function is_issuer_url(string $url): bool {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return false;
        }
        if (!in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        return str_ends_with($parts['path'] ?? '', hub_http::OIDC_PATH);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'true on success'),
        ]);
    }
}
