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
 * External function: local_nucleushub_configure_org_signin.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleushub\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_nucleushub\local\org_signin;

/**
 * Set up (or change) the organisation's sign-in on the hub (ADR-023 section 5).
 *
 * Creates or updates the one Moodle OAuth 2 issuer Nucleus manages, runs
 * endpoint discovery against the provider straight away, and turns on
 * the OAuth 2 authentication plugin. Errors come back as
 * `orgsignin_*` error codes and never contain the secret.
 *
 * Only on the "Nucleus control plane (hub)" service.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class configure_org_signin extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'provider' => new external_value(PARAM_RAW, 'microsoft, google or oidc.'),
            'clientid' => new external_value(PARAM_RAW, 'Client (application) id from the provider.'),
            'clientsecret' => new external_value(PARAM_RAW, 'Client secret. Stored on the hub, never returned.'),
            'tenantid' => new external_value(PARAM_RAW, 'Entra tenant id (GUID) or verified domain. microsoft only.',
                VALUE_DEFAULT, ''),
            'discoveryurl' => new external_value(PARAM_RAW, 'Discovery URL (or its base), https. oidc only.', VALUE_DEFAULT, ''),
            'domains' => new external_value(PARAM_RAW, 'Comma-separated email domains. Required for microsoft and google.',
                VALUE_DEFAULT, ''),
            'displayname' => new external_value(PARAM_RAW, 'Name on the login button, for example Contoso.'),
        ]);
    }

    /**
     * Set up the organisation's sign-in.
     *
     * @param string $provider
     * @param string $clientid
     * @param string $clientsecret
     * @param string $tenantid
     * @param string $discoveryurl
     * @param string $domains
     * @param string $displayname
     * @return array{ok: bool, issuerid: int, redirecturi: string}
     */
    public static function execute(
        string $provider,
        string $clientid,
        string $clientsecret,
        string $tenantid = '',
        string $discoveryurl = '',
        string $domains = '',
        string $displayname = ''
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'provider' => $provider,
            'clientid' => $clientid,
            'clientsecret' => $clientsecret,
            'tenantid' => $tenantid,
            'discoveryurl' => $discoveryurl,
            'domains' => $domains,
            'displayname' => $displayname,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        return org_signin::configure(
            $params['provider'],
            $params['clientid'],
            $params['clientsecret'],
            $params['tenantid'],
            $params['discoveryurl'],
            $params['domains'],
            $params['displayname']
        );
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'True: the issuer is saved, discovered and on the login page.'),
            'issuerid' => new external_value(PARAM_INT, 'The Moodle OAuth 2 issuer id.'),
            'redirecturi' => new external_value(PARAM_URL, 'The redirect URI to register with the provider.'),
        ]);
    }
}
