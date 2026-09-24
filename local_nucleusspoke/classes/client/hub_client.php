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
 * Typed facade over the hub's web service endpoint for spoke-side callers.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleusspoke\client;

use local_nucleuscommon\transport\hub_client as transport;

defined('MOODLE_INTERNAL') || die();

/**
 * One method per hub external function, named after the function it wraps.
 * Callers (the catalogue page, the puller) use this rather than touching
 * the low-level transport — the typed methods both document the protocol
 * in PHP and keep call sites free of WS function-name strings.
 *
 * The token is this spoke's own hub token (ADR-023), set by Nucleus
 * through configure_hub.
 *
 * Construct with an existing transport for unit tests, or via
 * {@see self::default()} in production paths.
 */
class hub_client {

    /** @var transport */
    private transport $transport;

    public function __construct(transport $transport) {
        $this->transport = $transport;
    }

    /**
     * Build a client from the plugin's stored settings.
     *
     * @return self
     * @throws \moodle_exception If hubwwwroot or hubtoken are unset.
     */
    public static function default(): self {
        $wwwroot = (string)(get_config('local_nucleusspoke', 'hubwwwroot') ?: '');
        $token = (string)(get_config('local_nucleusspoke', 'hubtoken') ?: '');
        if ($wwwroot === '' || $token === '') {
            throw new \moodle_exception('spokenotconfigured', 'local_nucleusspoke');
        }
        // Optional: cluster-internal TCP address (e.g. k8s service DNS)
        // when the browser-facing wwwroot isn't reachable from this pod.
        // The Host header still derives from $wwwroot to satisfy Moodle's
        // wwwroot-match check on the receiving end.
        $connecturl = (string)(get_config('local_nucleusspoke', 'hubconnecturl') ?: '');
        return new self(new transport(
            $wwwroot,
            $token,
            30,
            2,
            $connecturl !== '' ? $connecturl : null
        ));
    }

    /**
     * List courses the hub is offering.
     *
     * @return array[] Each row: ['id','shortname','fullname','summary','category']
     */
    public function list_courses(): array {
        return $this->transport->call('local_nucleushub_list_courses');
    }

    /**
     * Browse the hub's federation catalog — every family the hub
     * has, with its full published version history. Used by the
     * spoke's catalog page so admins can pull families they've
     * never been notified about (e.g. families published before
     * this spoke was registered).
     *
     * @return array[] Each row matches {@see list_families::execute_returns}.
     */
    public function list_families(): array {
        return $this->transport->call('local_nucleushub_list_families');
    }

    /**
     * ADR-021 Tier A — fetch a published version's dependency manifest
     * before downloading the MBZ. Spoke uses this to refuse a pull
     * cleanly when the local plugin set or Moodle major-version isn't
     * compatible with what the backup needs.
     *
     * @param string $versionguid Version guid as returned by list_families().
     * @return array Wire payload from local_nucleushub_describe_version.
     */
    public function describe_version(string $versionguid): array {
        return $this->transport->call(
            'local_nucleushub_describe_version',
            ['versionguid' => $versionguid]
        );
    }

    /**
     * Cheap probe used by the admin UI to show connection health. Returns
     * a simple shape for easy rendering rather than throwing on failure.
     *
     * @return array ['ok' => bool, 'detail' => string, 'courses_available' => ?int]
     */
    public function health_check(): array {
        try {
            $courses = $this->list_courses();
            return [
                'ok'                => true,
                'detail'            => 'ok',
                'courses_available' => count($courses),
            ];
        } catch (\Throwable $e) {
            return [
                'ok'                => false,
                'detail'            => $e->getMessage(),
                'courses_available' => null,
            ];
        }
    }
}
