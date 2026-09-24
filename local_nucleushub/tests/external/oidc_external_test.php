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

namespace local_nucleushub\external;

use core_external\external_api;
use local_nucleushub\local\oidc\client_registry;
use local_nucleushub\local\oidc\provider;
use local_nucleushub\local\oidc\subjects;

/**
 * Tests for the sign-in web services the control plane calls.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(oidc_register_client::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(oidc_delete_client::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(list_users_for_linking::class)]
final class oidc_external_test extends \advanced_testcase {
    /**
     * The generator.
     *
     * @return \local_nucleushub_generator
     */
    private function generator(): \local_nucleushub_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_nucleushub');
    }

    /**
     * The three functions are on the control-plane service and never on the
     * federation service spokes call.
     */
    public function test_functions_are_on_the_control_plane_service_only(): void {
        global $DB;
        $this->resetAfterTest();
        $sql = "SELECT sf.functionname
                  FROM {external_services_functions} sf
                  JOIN {external_services} s ON s.id = sf.externalserviceid
                 WHERE s.shortname = :shortname";
        $cp = $DB->get_fieldset_sql($sql, ['shortname' => 'nucleus_cp_hub']);
        $federation = $DB->get_fieldset_sql($sql, ['shortname' => 'nucleus_federation']);
        foreach (['local_nucleushub_oidc_register_client', 'local_nucleushub_oidc_delete_client',
                'local_nucleushub_list_users_for_linking'] as $function) {
            $this->assertContains($function, $cp);
            $this->assertNotContains($function, $federation);
        }
    }

    /**
     * Register returns the issuer, client id and a secret; again gives the same id and a new secret.
     */
    public function test_register_client(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $spoke = $this->generator()->create_spoke();

        $first = external_api::clean_returnvalue(
            oidc_register_client::execute_returns(),
            oidc_register_client::execute($spoke->cpspokeid)
        );
        $this->assertSame(provider::issuer(), $first['issuer']);
        $this->assertNotEmpty($first['clientid']);
        $this->assertNotEmpty($first['clientsecret']);

        $second = oidc_register_client::execute($spoke->cpspokeid);
        $this->assertSame($first['clientid'], $second['clientid']);
        $this->assertNotSame($first['clientsecret'], $second['clientsecret']);
    }

    /**
     * Only a site administrator (the control plane's account) may call these.
     */
    public function test_capability_is_required(): void {
        global $DB;
        $this->resetAfterTest();
        $spoke = $this->generator()->create_spoke();
        $this->setUser($this->getDataGenerator()->create_user());

        foreach ([
            fn() => oidc_register_client::execute($spoke->cpspokeid),
            fn() => oidc_delete_client::execute($spoke->cpspokeid),
            fn() => list_users_for_linking::execute(0, 10),
        ] as $call) {
            try {
                $call();
                $this->fail('A user without moodle/site:config must be refused.');
            } catch (\required_capability_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
            }
        }
        $this->assertSame(0, $DB->count_records(client_registry::TABLE));
    }

    /**
     * Register refuses an unknown spoke and an empty ID.
     */
    public function test_register_client_refuses_unknown_spoke(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        try {
            oidc_register_client::execute('cp-unknown');
            $this->fail('An unknown spoke must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('oidc_nospoke', $e->errorcode);
        }
        $this->expectException(\invalid_parameter_exception::class);
        oidc_register_client::execute('  ');
    }

    /**
     * Delete removes the client and is idempotent.
     */
    public function test_delete_client(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $registered = $this->generator()->create_oidc_client();

        $result = external_api::clean_returnvalue(
            oidc_delete_client::execute_returns(),
            oidc_delete_client::execute($registered['spoke']->cpspokeid)
        );
        $this->assertTrue($result['ok']);
        $this->assertNull(client_registry::find_active($registered['clientid']));
        $this->assertTrue(oidc_delete_client::execute($registered['spoke']->cpspokeid)['ok']);
    }

    /**
     * Linking lists real accounts only, with their flags, a page at a time.
     */
    public function test_list_users_for_linking(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $ok = $generator->create_user(['email' => 'ok@example.com']);
        $suspended = $generator->create_user(['email' => 'suspended@example.com', 'suspended' => 1]);
        $unconfirmed = $generator->create_user(['email' => 'unconfirmed@example.com']);
        $DB->set_field('user', 'confirmed', 0, ['id' => $unconfirmed->id]);
        $deleted = $generator->create_user();
        delete_user($deleted);
        $service = $generator->create_user(['auth' => 'webservice']);
        $nologin = $generator->create_user(['auth' => 'nologin']);

        $result = external_api::clean_returnvalue(
            list_users_for_linking::execute_returns(),
            list_users_for_linking::execute(0, 500)
        );
        $bysub = array_column($result['users'], null, 'sub');
        // Every sub is an opaque subject, made on first listing, never a user id.
        foreach (array_keys($bysub) as $sub) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $sub);
        }
        $sub = fn(\stdClass $user) => subjects::find_for_user((int) $user->id);
        $this->assertArrayHasKey($sub($ok), $bysub);
        $this->assertSame('ok@example.com', $bysub[$sub($ok)]['email']);
        $this->assertTrue($bysub[$sub($ok)]['confirmed']);
        $this->assertFalse($bysub[$sub($ok)]['suspended']);
        $this->assertTrue($bysub[$sub($suspended)]['suspended']);
        $this->assertFalse($bysub[$sub($unconfirmed)]['confirmed']);
        foreach ([$deleted, $service, $nologin, guest_user()] as $excluded) {
            $this->assertNull($sub($excluded));
        }
        // Listing again gives the same subjects.
        $again = list_users_for_linking::execute(0, 500);
        $this->assertSame(array_column($result['users'], 'sub'), array_column($again['users'], 'sub'));
        // The admin plus the three listed accounts.
        $this->assertSame(4, $result['total']);
        $this->assertCount(4, $result['users']);

        // Paging.
        $page0 = list_users_for_linking::execute(0, 3);
        $page1 = list_users_for_linking::execute(1, 3);
        $this->assertCount(3, $page0['users']);
        $this->assertCount(1, $page1['users']);
        $this->assertSame(4, $page1['total']);
        $subs = array_merge(array_column($page0['users'], 'sub'), array_column($page1['users'], 'sub'));
        $this->assertSame(array_column($result['users'], 'sub'), $subs);
    }

    /**
     * perpage is capped at 500 and at least 1.
     */
    public function test_list_users_for_linking_caps_perpage(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        for ($i = 0; $i < 3; $i++) {
            $this->getDataGenerator()->create_user();
        }
        $this->assertCount(1, list_users_for_linking::execute(0, 0)['users']);
        $this->assertCount(1, list_users_for_linking::execute(0, -5)['users']);
        $this->assertCount(4, list_users_for_linking::execute(0, 100000)['users']);
        $this->assertSame(500, list_users_for_linking::MAX_PER_PAGE);
    }
}
