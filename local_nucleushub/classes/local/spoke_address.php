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

namespace local_nucleushub\local;

use local_nucleushub\local\oidc\client_registry;

/**
 * Spoke addresses: how they're compared, and which ones a spoke can move to.
 *
 * A spoke's address (its wwwroot) is the natural key of its row in
 * `local_nucleushub_spokes`, which has a unique index on it. Shared by
 * register_spoke and move_spoke so both treat addresses the same way.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class spoke_address {
    /** @var string Spoke table. */
    public const TABLE = 'local_nucleushub_spokes';

    /** @var int Longest address the spoke table can hold. */
    public const MAX_LENGTH = 255;

    /**
     * An address in a form for comparison: scheme and host lower case,
     * default port dropped, no trailing slash.
     *
     * @param string $wwwroot
     * @return string
     */
    public static function normalise(string $wwwroot): string {
        $wwwroot = rtrim(trim($wwwroot), '/');
        $parts = parse_url($wwwroot);
        if (!$parts || empty($parts['host'])) {
            return strtolower($wwwroot);
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }
        return $scheme . '://' . strtolower((string) $parts['host']) . ($port !== null ? ':' . $port : '')
            . rtrim((string) ($parts['path'] ?? ''), '/');
    }

    /**
     * Every spoke row at an address, whatever its status, comparing
     * addresses with {@see self::normalise()}.
     *
     * @param string $wwwroot
     * @return \stdClass[] Rows with id, wwwroot, status, cpspokeid and serviceuserid, keyed by id.
     */
    public static function rows_at(string $wwwroot): array {
        global $DB;

        $wanted = self::normalise($wwwroot);
        $rows = $DB->get_records(self::TABLE, null, 'id ASC', 'id, wwwroot, status, cpspokeid, serviceuserid');
        return array_filter(
            $rows,
            fn(\stdClass $row): bool => self::normalise((string) $row->wwwroot) === $wanted
        );
    }

    /**
     * An address a spoke can be moved to, in the form it's stored in.
     *
     * The address must pass the same checks as register_spoke's (a URL
     * that Moodle's PARAM_URL accepts, stored without a trailing slash)
     * and the sign-in client's rules in {@see client_registry::uris_for()}:
     * an absolute http or https URL with a host, and no query, fragment or
     * credentials. When the hub runs on https, so must the spoke. It must
     * also fit in the spoke table.
     *
     * @param string $wwwroot The address asked for.
     * @return string The address to store.
     * @throws \moodle_exception invalidwwwroot
     */
    public static function clean_for_move(string $wwwroot): string {
        $wwwroot = trim($wwwroot);
        if ($wwwroot === '' || clean_param($wwwroot, PARAM_URL) !== $wwwroot) {
            throw new \moodle_exception('invalidwwwroot', 'local_nucleushub');
        }
        $wwwroot = trim($wwwroot, '/');
        if ($wwwroot === '' || \core_text::strlen($wwwroot) > self::MAX_LENGTH) {
            throw new \moodle_exception('invalidwwwroot', 'local_nucleushub');
        }
        try {
            client_registry::uris_for($wwwroot);
        } catch (\moodle_exception $e) {
            throw new \moodle_exception('invalidwwwroot', 'local_nucleushub');
        }
        return $wwwroot;
    }
}
