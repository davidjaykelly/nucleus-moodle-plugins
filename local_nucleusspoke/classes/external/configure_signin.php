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
 * External function: local_nucleusspoke_configure_signin.
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
use local_nucleusspoke\local\signin;

defined('MOODLE_INTERNAL') || die();

/**
 * Set up sign-in with the hub on this spoke, and turn it on (ADR-023).
 *
 * Writes auth_nucleus's settings (the client secret encrypted with
 * \core\encryption) and adds 'nucleus' to $CFG->auth the way Site
 * administration > Manage authentication does. Manual accounts stay on.
 * Calling it again replaces the settings, so it also rotates the secret.
 *
 * The issuer must be exactly {hubwwwroot}/local/nucleushub/oidc for the
 * hub this spoke is connected to: sign-in only ever talks to that hub.
 * Configuring a different issuer from the current one first gives hub
 * accounts their own login back and clears every link (links belong to
 * an issuer).
 */
class configure_signin extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'issuer' => new external_value(PARAM_URL,
                'The hub\'s issuer: exactly {hubwwwroot}/local/nucleushub/oidc.'),
            'clientid' => new external_value(PARAM_RAW_TRIMMED, 'This spoke\'s client id on the hub.'),
            'clientsecret' => new external_value(PARAM_RAW, 'This spoke\'s client secret on the hub. Stored encrypted.'),
            'hubname' => new external_value(PARAM_TEXT, 'Name for the "Sign in with {hub name}" button.'),
            'autoredirect' => new external_value(PARAM_BOOL, 'Send the login page straight to the hub (1/0).',
                VALUE_DEFAULT, true),
            'singlesignout' => new external_value(PARAM_BOOL, 'Sign out of the hub when signing out here (1/0).',
                VALUE_DEFAULT, true),
        ]);
    }

    /**
     * Store the settings and turn hub sign-in on.
     *
     * @param string $issuer
     * @param string $clientid
     * @param string $clientsecret
     * @param string $hubname
     * @param bool $autoredirect
     * @param bool $singlesignout
     * @return array ['ok' => true]
     */
    public static function execute(string $issuer, string $clientid, string $clientsecret, string $hubname,
            bool $autoredirect = true, bool $singlesignout = true): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'issuer' => $issuer,
            'clientid' => $clientid,
            'clientsecret' => $clientsecret,
            'hubname' => $hubname,
            'autoredirect' => $autoredirect,
            'singlesignout' => $singlesignout,
        ]);
        signin::require_site_config();
        signin::require_plugin();

        $issuer = rtrim($params['issuer'], '/');
        $clientid = $params['clientid'];
        $clientsecret = $params['clientsecret'];
        $hubname = trim($params['hubname']);

        if ($issuer === '' || !preg_match('#^https?://#i', $issuer)) {
            throw new \invalid_parameter_exception('issuer must be an absolute http(s) URL');
        }
        if (!preg_match('/^[\x21-\x7E]{1,255}$/', $clientid)) {
            throw new \invalid_parameter_exception('clientid must be 1-255 printable characters without spaces');
        }
        if ($clientsecret === '' || strlen($clientsecret) > 1024) {
            throw new \invalid_parameter_exception('clientsecret must be set');
        }
        if ($hubname === '' || \core_text::strlen($hubname) > 255) {
            throw new \invalid_parameter_exception('hubname must be 1-255 characters');
        }

        $hubwwwroot = rtrim((string) get_config('local_nucleusspoke', 'hubwwwroot'), '/');
        if ($hubwwwroot === '') {
            throw new \moodle_exception('spokenotconfigured', 'local_nucleusspoke');
        }
        // Exactly this hub's issuer: sign-in only ever talks to it.
        $expected = (new \local_nucleuscommon\transport\hub_http($hubwwwroot))->issuer();
        if ($issuer !== $expected) {
            throw new \moodle_exception('signin_issuernotonhub', 'local_nucleusspoke', '', $expected);
        }

        // Links belong to an issuer. Switching to another one first gives
        // hub accounts their own login back and forgets every link, as
        // turning sign-in off does.
        \auth_nucleus\local\accounts::reset_for_issuer($issuer);

        \auth_nucleus\local\config::save($issuer, $clientid, $clientsecret, $hubname,
            (bool) $params['autoredirect'], (bool) $params['singlesignout']);
        \auth_nucleus\local\config::set_enabled(true);

        return ['ok' => true];
    }

    /**
     * Return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'True when sign-in with the hub is set up and on.'),
        ]);
    }
}
