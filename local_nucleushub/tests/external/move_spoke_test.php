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
use local_nucleushub\local\oidc\tokens;
use local_nucleushub\local\spoke_address;

/**
 * Tests for move_spoke: a spoke moves address in place, with its sign-in
 * client, and keeps its hub account and token.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(move_spoke::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(spoke_address::class)]
final class move_spoke_test extends \advanced_testcase {
    /** @var string The spoke's Nucleus address. */
    private const OLD = 'https://acme.n.example.com';

    /** @var string The spoke's own domain. */
    private const NEW = 'https://training.acme.example';

    /**
     * The generator.
     *
     * @return \local_nucleushub_generator
     */
    private function generator(): \local_nucleushub_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_nucleushub');
    }

    /**
     * Register spoke `cp-a` as the control plane does: its own hub account
     * and token, then (optionally) a sign-in client with a pending code.
     *
     * @param bool $withclient Register a sign-in client too.
     * @return array{token: string, spokeId: int, clientid: ?string, secret: ?string, userid: int}
     */
    private function register_a(bool $withclient = true): array {
        global $DB;
        $this->setAdminUser();
        $a = register_spoke::execute(self::OLD, 'Acme', 'cp-a');
        // Old enough that the move visibly changes timemodified.
        $DB->set_field('local_nucleushub_spokes', 'timemodified', time() - 1000, ['id' => $a['spokeId']]);
        $a['userid'] = (int) $DB->get_field('local_nucleushub_spokes', 'serviceuserid', ['id' => $a['spokeId']]);
        $a['clientid'] = null;
        $a['secret'] = null;
        if ($withclient) {
            $client = client_registry::register('cp-a');
            $a['clientid'] = $client['clientid'];
            $a['secret'] = $client['clientsecret'];
            $user = $this->getDataGenerator()->create_user();
            $active = client_registry::find_active($client['clientid']);
            $this->generator()->create_code($active, (int) $user->id, str_repeat('v', 43));
        }
        return $a;
    }

    /**
     * Everything about spoke A as it stands, to compare before and after.
     *
     * @param array $a From register_a().
     * @return array
     */
    private function snapshot(array $a): array {
        global $DB;
        return [
            'spokes' => $DB->get_records('local_nucleushub_spokes', null, 'id'),
            'clients' => $DB->get_records(client_registry::TABLE, null, 'id'),
            'codes' => $DB->count_records(tokens::CODE_TABLE),
            'tokens' => $DB->get_records('external_tokens', ['userid' => $a['userid']], 'id'),
        ];
    }

    /**
     * Call the function as the web service layer would, cleaning the result.
     *
     * @param string $cpspokeid
     * @param string $wwwroot
     * @return array
     */
    private function move(string $cpspokeid, string $wwwroot): array {
        return external_api::clean_returnvalue(
            move_spoke::execute_returns(),
            move_spoke::execute($cpspokeid, $wwwroot)
        );
    }

    /**
     * Expect a refusal with this error code, and nothing changed.
     *
     * @param string $errorcode
     * @param array $a From register_a().
     * @param string $cpspokeid
     * @param string $wwwroot
     */
    private function assert_refused(string $errorcode, array $a, string $cpspokeid, string $wwwroot): void {
        $before = $this->snapshot($a);
        try {
            move_spoke::execute($cpspokeid, $wwwroot);
            $this->fail("Expected {$errorcode}.");
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
        $this->assertEquals($before, $this->snapshot($a));
    }

    /**
     * The function is on the control-plane service and never on the
     * federation service spokes call.
     */
    public function test_on_the_control_plane_service_only(): void {
        global $DB;
        $this->resetAfterTest();
        $sql = "SELECT sf.functionname
                  FROM {external_services_functions} sf
                  JOIN {external_services} s ON s.id = sf.externalserviceid
                 WHERE s.shortname = :shortname";
        $this->assertContains('local_nucleushub_move_spoke', $DB->get_fieldset_sql($sql, ['shortname' => 'nucleus_cp_hub']));
        $this->assertNotContains(
            'local_nucleushub_move_spoke',
            $DB->get_fieldset_sql($sql, ['shortname' => 'nucleus_federation'])
        );
    }

    /**
     * The row moves in place, and the spoke's hub account and token don't change.
     */
    public function test_moves_the_row_in_place_and_keeps_the_token(): void {
        global $DB;
        $this->resetAfterTest();
        $a = $this->register_a(false);
        $before = $DB->get_record('local_nucleushub_spokes', ['id' => $a['spokeId']], '*', MUST_EXIST);
        $spokes = $DB->count_records('local_nucleushub_spokes');
        $tokens = $DB->get_records('external_tokens', ['userid' => $a['userid']]);
        $access = $DB->get_records('external_services_users', ['userid' => $a['userid']]);

        $result = $this->move('cp-a', self::NEW);
        $this->assertSame(['moved' => true, 'spokeid' => $a['spokeId']], $result);

        $after = $DB->get_record('local_nucleushub_spokes', ['id' => $a['spokeId']], '*', MUST_EXIST);
        $this->assertSame(self::NEW, $after->wwwroot);
        $this->assertGreaterThan((int) $before->timemodified, (int) $after->timemodified);
        // Nothing else on the row changes.
        unset($before->wwwroot, $before->timemodified, $after->wwwroot, $after->timemodified);
        $this->assertEquals($before, $after);
        $this->assertSame($spokes, $DB->count_records('local_nucleushub_spokes'));

        // The same account, with the same token and access.
        $this->assertEquals($tokens, $DB->get_records('external_tokens', ['userid' => $a['userid']]));
        $this->assertEquals($access, $DB->get_records('external_services_users', ['userid' => $a['userid']]));
        $this->assertTrue($DB->record_exists('user', ['id' => $a['userid'], 'deleted' => 0, 'suspended' => 0]));
        $this->assertSame($a['token'], $after->token);

        // register_spoke at the new address finds the same row and token.
        $again = register_spoke::execute(self::NEW, 'Acme', 'cp-a');
        $this->assertSame($a['spokeId'], $again['spokeId']);
        $this->assertSame($a['token'], $again['token']);
    }

    /**
     * The sign-in client follows the spoke: new URIs, the same id and
     * secret, and pending codes dropped. Other spokes' codes stay.
     */
    public function test_moves_the_sign_in_client(): void {
        global $DB;
        $this->resetAfterTest();
        $a = $this->register_a();
        $other = $this->generator()->create_oidc_client();
        $user = $this->getDataGenerator()->create_user();
        $this->generator()->create_code($other['client'], (int) $user->id, str_repeat('w', 43));
        $before = $DB->get_record(client_registry::TABLE, ['clientid' => $a['clientid']], '*', MUST_EXIST);
        $this->assertSame(1, $DB->count_records(tokens::CODE_TABLE, ['clientid' => $a['clientid']]));

        $this->assertTrue($this->move('cp-a', self::NEW)['moved']);

        $after = $DB->get_record(client_registry::TABLE, ['clientid' => $a['clientid']], '*', MUST_EXIST);
        $this->assertSame($before->id, $after->id);
        $this->assertEquals($a['spokeId'], $after->spokeid);
        $this->assertSame(self::NEW . '/auth/nucleus/callback.php', $after->redirecturi);
        $this->assertSame(self::NEW . '/', $after->postlogouturi);
        $this->assertSame($before->secrethash, $after->secrethash);
        $this->assertNotNull(client_registry::authenticate($a['clientid'], $a['secret']));
        $this->assertSame(0, $DB->count_records(tokens::CODE_TABLE, ['clientid' => $a['clientid']]));
        $this->assertSame(1, $DB->count_records(tokens::CODE_TABLE, ['clientid' => $other['clientid']]));
        $this->assertTrue(client_registry::is_post_logout_uri(self::NEW . '/'));
        $this->assertFalse(client_registry::is_post_logout_uri(self::OLD . '/'));
        $this->assertSame(2, $DB->count_records(client_registry::TABLE));
    }

    /**
     * The same address again changes nothing and says so, however it's written.
     */
    public function test_same_address_is_a_no_op(): void {
        $this->resetAfterTest();
        $a = $this->register_a();

        foreach ([self::OLD, self::OLD . '/', ' ' . self::OLD . ' '] as $wwwroot) {
            $before = $this->snapshot($a);
            $this->assertSame(['moved' => false, 'spokeid' => $a['spokeId']], $this->move('cp-a', $wwwroot));
            $this->assertEquals($before, $this->snapshot($a));
        }
    }

    /**
     * Moving there and back leaves the spoke where it started, with its
     * client and token.
     */
    public function test_there_and_back(): void {
        global $DB;
        $this->resetAfterTest();
        $a = $this->register_a();

        $this->move('cp-a', self::NEW);
        $this->assertSame(['moved' => true, 'spokeid' => $a['spokeId']], $this->move('cp-a', self::OLD));
        $client = client_registry::find_active($a['clientid']);
        $this->assertSame(self::OLD . '/auth/nucleus/callback.php', $client->redirecturi);
        $this->assertSame(self::OLD, $DB->get_field('local_nucleushub_spokes', 'wwwroot', ['id' => $a['spokeId']]));
        $this->assertTrue($DB->record_exists('external_tokens', ['token' => $a['token'], 'userid' => $a['userid']]));
    }

    /**
     * The spoke's own address under another case is a move, not a clash.
     */
    public function test_own_address_in_another_case_is_a_move(): void {
        global $DB;
        $this->resetAfterTest();
        $a = $this->register_a();

        $this->assertTrue($this->move('cp-a', 'https://ACME.n.example.com')['moved']);
        $this->assertSame(
            'https://ACME.n.example.com',
            $DB->get_field('local_nucleushub_spokes', 'wwwroot', ['id' => $a['spokeId']])
        );
        $this->assertSame(
            'https://ACME.n.example.com/auth/nucleus/callback.php',
            client_registry::find_active($a['clientid'])->redirecturi
        );
    }

    /**
     * Spokes that can't be moved: none with that ID, or none active.
     *
     * @return array
     */
    public static function no_spoke_provider(): array {
        return [
            'unknown' => ['cp-unknown', 'active'],
            'suspended' => ['cp-a', 'suspended'],
            'removed' => ['cp-a', 'removed'],
        ];
    }

    /**
     * No active row for the ID: spokenotfound.
     *
     * @param string $cpspokeid
     * @param string $status Spoke A's status.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('no_spoke_provider')]
    public function test_spoke_not_found(string $cpspokeid, string $status): void {
        global $DB;
        $this->resetAfterTest();
        $a = $this->register_a();
        $DB->set_field('local_nucleushub_spokes', 'status', $status, ['id' => $a['spokeId']]);

        $this->assert_refused('spokenotfound', $a, $cpspokeid, self::NEW);
    }

    /**
     * An empty ID is a bad call, not a missing spoke.
     */
    public function test_empty_id_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectException(\invalid_parameter_exception::class);
        move_spoke::execute('  ', self::NEW);
    }

    /**
     * Ways another spoke's row can hold the address.
     *
     * @return array
     */
    public static function held_address_provider(): array {
        return [
            'same address' => [self::NEW, 'cp-b', 'active'],
            'trailing slash' => [self::NEW . '/', 'cp-b', 'active'],
            'upper-case host' => ['https://TRAINING.acme.example', 'cp-b', 'active'],
            'default port' => [self::NEW . ':443', 'cp-b', 'active'],
            'suspended spoke' => [self::NEW, 'cp-b', 'suspended'],
            'row with no Nucleus spoke ID' => [self::NEW, null, 'active'],
        ];
    }

    /**
     * An address another row holds is refused: spokeurlinuse, and nothing
     * of either spoke's changes.
     *
     * @param string $wwwroot What move_spoke is asked for.
     * @param string|null $holderid The holder's Nucleus spoke ID.
     * @param string $holderstatus
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('held_address_provider')]
    public function test_address_in_use_is_refused(string $wwwroot, ?string $holderid, string $holderstatus): void {
        $this->resetAfterTest();
        $a = $this->register_a();
        $this->generator()->create_spoke([
            'wwwroot' => self::NEW,
            'cpspokeid' => $holderid,
            'status' => $holderstatus,
        ]);

        $this->assert_refused('spokeurlinuse', $a, 'cp-a', $wwwroot);
    }

    /**
     * A removed spoke's address can be used: its leftover row goes, and
     * the moving spoke keeps its own row.
     */
    public function test_removed_spokes_address_can_be_used(): void {
        global $DB;
        $this->resetAfterTest();
        $a = $this->register_a();
        $b = register_spoke::execute(self::NEW, 'Beta', 'cp-b');
        unregister_spoke::execute('cp-b');
        $this->assertSame('removed', $DB->get_field('local_nucleushub_spokes', 'status', ['id' => $b['spokeId']]));

        $this->assertSame(['moved' => true, 'spokeid' => $a['spokeId']], $this->move('cp-a', self::NEW));
        $this->assertFalse($DB->record_exists('local_nucleushub_spokes', ['id' => $b['spokeId']]));
        $this->assertSame(self::NEW, $DB->get_field('local_nucleushub_spokes', 'wwwroot', ['id' => $a['spokeId']]));
        $this->assertTrue($DB->record_exists('external_tokens', ['token' => $a['token']]));
        $this->assertNotNull(client_registry::authenticate($a['clientid'], $a['secret']));
    }

    /**
     * Addresses a spoke can't be given.
     *
     * @return array
     */
    public static function bad_address_provider(): array {
        return [
            'http while the hub is https' => ['http://training.acme.example'],
            'empty' => [''],
            'no host' => ['https://'],
            'not a URL' => ['training acme'],
            'relative' => ['/training'],
            'query' => ['https://training.acme.example?x=1'],
            'fragment' => ['https://training.acme.example#x'],
            'credentials' => ['https://user:pass@training.acme.example'],
            'other scheme' => ['ftp://training.acme.example'],
            'quote' => ['https://training.acme.example/"x'],
            'too long for the table' => ['https://training.acme.example/' . str_repeat('a', 250)],
        ];
    }

    /**
     * A bad address is refused: invalidwwwroot, and nothing changes.
     *
     * @param string $wwwroot
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_address_provider')]
    public function test_bad_address_is_refused(string $wwwroot): void {
        global $CFG;
        $this->resetAfterTest();
        // The test site's own address is https.
        $this->assertStringStartsWith('https://', $CFG->wwwroot);
        $a = $this->register_a();

        $this->assert_refused('invalidwwwroot', $a, 'cp-a', $wwwroot);
    }

    /**
     * A spoke under a path can move, and its client URIs keep the path.
     */
    public function test_address_with_a_path(): void {
        $this->resetAfterTest();
        $a = $this->register_a();

        $this->assertTrue($this->move('cp-a', 'https://acme.example/moodle/')['moved']);
        $client = client_registry::find_active($a['clientid']);
        $this->assertSame('https://acme.example/moodle/auth/nucleus/callback.php', $client->redirecturi);
        $this->assertSame('https://acme.example/moodle/', $client->postlogouturi);
    }

    /**
     * Only a site administrator (the control plane's account) may move a spoke.
     */
    public function test_capability_is_required(): void {
        $this->resetAfterTest();
        $a = $this->register_a();
        $this->setUser($this->getDataGenerator()->create_user());

        $before = $this->snapshot($a);
        try {
            move_spoke::execute('cp-a', self::NEW);
            $this->fail('A user without moodle/site:config must be refused.');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $this->assertEquals($before, $this->snapshot($a));
    }
}
