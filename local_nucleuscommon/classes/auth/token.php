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
 * Bearer-style federation token helper.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleuscommon\auth;

defined('MOODLE_INTERNAL') || die();

/**
 * Holds and applies the shared federation token used between hub and spoke.
 *
 * Phase 0 simplification: we piggy-back on Moodle's built-in web-service token
 * mechanism. Outbound requests carry the token as the `wstoken` query param,
 * which Moodle core on the receiving side verifies before dispatching to the
 * named external function. Phase 1 will replace this with per-spoke bearer
 * tokens issued by the control plane and a custom verifier.
 */
class token {

    /**
     * Token length hint for generated secrets. Matches Moodle core WS tokens.
     */
    const TOKEN_LENGTH = 32;

    /**
     * Read the configured token for the remote side of the federation.
     *
     * The plugin that stores the token (hub or spoke) differs by role, so
     * this helper accepts the component name to look under.
     *
     * @param string $component Plugin component that owns the setting (e.g. 'local_nucleusspoke').
     * @param string $settingname Name of the setting holding the token.
     * @return string Token value, empty string if unset.
     */
    public static function get(string $component, string $settingname): string {
        $value = get_config($component, $settingname);
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Append the token to a URL as the `wstoken` query parameter.
     *
     * Preserves any existing query string on the URL.
     *
     * @param string $url Base URL (typically the hub's /webservice/rest/server.php).
     * @param string $token The federation token.
     * @return string URL with wstoken appended.
     */
    public static function with_wstoken(string $url, string $token): string {
        if ($token === '') {
            throw new \moodle_exception('tokenmissing', 'local_nucleuscommon');
        }
        $separator = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $separator . 'wstoken=' . rawurlencode($token);
    }

    /**
     * Generate a fresh token suitable for Phase 0 shared-secret use.
     *
     * Not used at runtime — intended for manual admin/CLI setup ahead of Day 3.
     *
     * @return string Cryptographically random hex string.
     */
    public static function generate(): string {
        return bin2hex(random_bytes(self::TOKEN_LENGTH / 2));
    }
}
