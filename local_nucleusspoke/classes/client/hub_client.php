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
 * Typed facade over the hub's web service endpoint for spoke-side callers.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleusspoke\client;

use local_nucleuscommon\transport\hub_client as transport;

defined('MOODLE_INTERNAL') || die();

/**
 * One method per hub external function, named after the function it wraps.
 * Callers (enrolment observer, admin page, event consumer) use this rather
 * than touching the low-level transport — the typed methods both document
 * the protocol in PHP and keep call sites free of WS function-name strings.
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
     * List courses the hub is offering. Both modes.
     *
     * @return array[] Each row: ['id','shortname','fullname','summary','category']
     */
    public function list_courses(): array {
        return $this->transport->call('local_nucleushub_list_courses');
    }

    /**
     * Mode A: ask the hub to back up a course. Response `backup_url`
     * field is the filename the hub has stashed under its
     * `nucleushub_backups` directory; call {@see self::fetch_backup()}
     * to actually transfer the bytes.
     *
     * @param int $courseid Hub course id.
     * @return array ['status','backup_url','size_bytes']
     */
    public function request_course_copy(int $courseid): array {
        return $this->transport->call('local_nucleushub_request_course_copy', [
            'courseid' => $courseid,
        ]);
    }

    /**
     * Mode A: fetch a previously-generated MBZ from the hub's download
     * endpoint into a local file. Token is the same one used for WS
     * calls — auth is verified on the hub side against the
     * nucleus_federation service.
     *
     * @param string $filename As returned in request_course_copy()['backup_url'].
     * @param string $localpath Destination file on this spoke.
     * @return bool True if the download succeeded and wrote the file.
     */
    public function fetch_backup(string $filename, string $localpath): bool {
        return $this->transport->download_to_file(
            '/local/nucleushub/download.php',
            ['file' => $filename],
            $localpath
        );
    }

    /**
     * Mode B: idempotent upsert of a shadow user on the hub.
     *
     * @param int $spokeuserid Local user id (on this spoke).
     * @param string $username Local username — denormalised on the hub for debugging.
     * @param string $email Local email.
     * @param string $firstname
     * @param string $lastname
     * @return array ['hubuserid','created']
     */
    public function project_user(int $spokeuserid, string $username, string $email,
            string $firstname, string $lastname): array {
        return $this->transport->call('local_nucleushub_project_user', [
            'spokeuserid' => $spokeuserid,
            'username'    => $username,
            'email'       => $email,
            'firstname'   => $firstname,
            'lastname'    => $lastname,
        ]);
    }

    /**
     * Mode B: enrol a previously-projected user in a hub-hosted course.
     *
     * @param int $hubuserid Shadow user id (from project_user).
     * @param int $courseid Hub course id.
     * @return array ['enrolmentid','status']
     */
    public function request_enrolment(int $hubuserid, int $courseid): array {
        return $this->transport->call('local_nucleushub_request_enrolment', [
            'hubuserid' => $hubuserid,
            'courseid'  => $courseid,
        ]);
    }

    /**
     * Mode B: remove a projected user's enrolment from a hub course.
     *
     * @param int $hubuserid
     * @param int $courseid
     * @return array ['status']
     */
    public function revoke_enrolment(int $hubuserid, int $courseid): array {
        return $this->transport->call('local_nucleushub_revoke_enrolment', [
            'hubuserid' => $hubuserid,
            'courseid'  => $courseid,
        ]);
    }

    /**
     * Mode B (Phase B1 Step 5): tell the hub a spoke user has been
     * deleted so it can clean up the projusers row + shadow user.
     * Idempotent — `removed=false` if there was nothing to clean up.
     *
     * @param int $spokeuserid Local (spoke) user id of the deleted user.
     * @return array ['removed' => bool, 'hubuserid' => int]
     */
    public function revoke_user(int $spokeuserid): array {
        return $this->transport->call('local_nucleushub_revoke_user', [
            'spokeuserid' => $spokeuserid,
        ]);
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
