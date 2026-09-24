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
 * Test data generator for local_nucleushub.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_nucleushub\local\oidc\client_registry;
use local_nucleushub\local\oidc\tokens;

/**
 * Makes spokes, sign-in clients and codes for the tests.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_nucleushub_generator extends component_generator_base {
    /** @var int Spokes made so far. */
    protected int $spokecount = 0;

    /**
     * Reset between tests.
     *
     * @return void
     */
    public function reset() {
        $this->spokecount = 0;
    }

    /**
     * A spoke row.
     *
     * @param array $record Overrides.
     * @return stdClass
     */
    public function create_spoke(array $record = []): stdClass {
        global $DB;

        $this->spokecount++;
        $n = $this->spokecount;
        $now = time();
        $spoke = (object) ($record + [
            'name' => 'Spoke ' . $n,
            'wwwroot' => 'https://spoke' . $n . '.example.com',
            'token' => '',
            'status' => 'active',
            'cpspokeid' => 'cp-spoke-' . $n,
            'serviceuserid' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $spoke->id = $DB->insert_record('local_nucleushub_spokes', $spoke);
        return $spoke;
    }

    /**
     * A spoke with a registered sign-in client.
     *
     * @param array $spokerecord Overrides for the spoke row.
     * @return array{spoke: stdClass, client: stdClass, clientid: string, secret: string}
     */
    public function create_oidc_client(array $spokerecord = []): array {
        $spoke = $this->create_spoke($spokerecord);
        // A client for an inactive spoke can't be registered, so register first.
        $status = $spoke->status;
        if ($status !== 'active') {
            $this->set_spoke_status($spoke, 'active');
        }
        $registered = client_registry::register($spoke->cpspokeid);
        $client = client_registry::find_active($registered['clientid']);
        if ($status !== 'active') {
            $this->set_spoke_status($spoke, $status);
        }
        return [
            'spoke' => $spoke,
            'client' => $client,
            'clientid' => $registered['clientid'],
            'secret' => $registered['clientsecret'],
        ];
    }

    /**
     * Change a spoke row's status.
     *
     * @param stdClass $spoke
     * @param string $status
     * @return void
     */
    public function set_spoke_status(stdClass $spoke, string $status): void {
        global $DB;
        $DB->set_field('local_nucleushub_spokes', 'status', $status, ['id' => $spoke->id]);
        $spoke->status = $status;
    }

    /**
     * An authorisation code, as authorize.php would issue it.
     *
     * @param stdClass $client Active client.
     * @param int $userid
     * @param string $verifier PKCE verifier the code is bound to.
     * @param string $nonce
     * @return string The code.
     */
    public function create_code(stdClass $client, int $userid, string $verifier, string $nonce = 'nonce-1'): string {
        return tokens::issue_code(
            $client,
            $userid,
            (string) $client->redirecturi,
            $nonce,
            tokens::pkce_challenge($verifier),
            time() - 10
        );
    }
}
