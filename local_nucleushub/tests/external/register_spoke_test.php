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

use local_nucleushub\local\oidc\client_registry;

/**
 * Tests for register_spoke: one spoke can't take over another's address.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(register_spoke::class)]
final class register_spoke_test extends \advanced_testcase {
    /** @var string The first spoke's address. */
    private const ADDRESS = 'https://acme.example.com';

    /**
     * Register spoke A with a sign-in client.
     *
     * @return array{token: string, spokeId: int, clientid: string}
     */
    private function register_a(): array {
        $this->setAdminUser();
        $a = register_spoke::execute(self::ADDRESS, 'Acme', 'cp-a');
        $client = client_registry::register('cp-a');
        return $a + ['clientid' => $client['clientid']];
    }

    /**
     * Assert spoke A still has its row, token and sign-in client.
     *
     * @param array $a From register_a().
     */
    private function assert_a_untouched(array $a): void {
        global $DB;
        $row = $DB->get_record('local_nucleushub_spokes', ['id' => $a['spokeId']], '*', MUST_EXIST);
        $this->assertSame('cp-a', $row->cpspokeid);
        $this->assertSame(self::ADDRESS, $row->wwwroot);
        $this->assertTrue($DB->record_exists('external_tokens', ['token' => $a['token']]));
        $this->assertNotNull(client_registry::find_active($a['clientid']));
    }

    /**
     * Registering the same spoke again is idempotent.
     */
    public function test_same_spoke_again(): void {
        $this->resetAfterTest();
        $a = $this->register_a();
        $again = register_spoke::execute(self::ADDRESS, 'Acme', 'cp-a');
        $this->assertSame($a['token'], $again['token']);
        $this->assertSame($a['spokeId'], $again['spokeId']);
        $this->assert_a_untouched($a);
    }

    /**
     * Ways of writing an address another spoke holds.
     *
     * @return array
     */
    public static function held_address_provider(): array {
        return [
            'same address' => ['https://acme.example.com', 'cp-b'],
            'trailing slash' => ['https://acme.example.com/', 'cp-b'],
            'upper-case host' => ['https://ACME.Example.com', 'cp-b'],
            'upper-case scheme' => ['HTTPS://acme.example.com', 'cp-b'],
            'default port' => ['https://acme.example.com:443', 'cp-b'],
            'no Nucleus spoke ID' => ['https://acme.example.com', ''],
        ];
    }

    /**
     * An address an active spoke holds is refused, and nothing of that
     * spoke's is touched.
     *
     * @param string $wwwroot
     * @param string $cpspokeid
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('held_address_provider')]
    public function test_address_held_by_another_spoke_is_refused(string $wwwroot, string $cpspokeid): void {
        global $DB;
        $this->resetAfterTest();
        $a = $this->register_a();
        $spokes = $DB->count_records('local_nucleushub_spokes');

        try {
            register_spoke::execute($wwwroot, 'Intruder', $cpspokeid);
            $this->fail('Another spoke\'s address must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('spokeurlinuse', $e->errorcode);
        }
        $this->assert_a_untouched($a);
        $this->assertSame($spokes, $DB->count_records('local_nucleushub_spokes'));
    }

    /**
     * A suspended spoke still holds its address.
     */
    public function test_address_held_by_suspended_spoke_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $a = $this->register_a();
        $DB->set_field('local_nucleushub_spokes', 'status', 'suspended', ['id' => $a['spokeId']]);

        try {
            register_spoke::execute(self::ADDRESS, 'Intruder', 'cp-b');
            $this->fail('A suspended spoke\'s address must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('spokeurlinuse', $e->errorcode);
        }
        $this->assertTrue($DB->record_exists('external_tokens', ['token' => $a['token']]));
        $this->assertSame('suspended', $DB->get_field('local_nucleushub_spokes', 'status', ['id' => $a['spokeId']]));
    }

    /**
     * A removed spoke's address can be taken over by a new spoke.
     */
    public function test_removed_spokes_address_can_be_taken_over(): void {
        global $DB;
        $this->resetAfterTest();
        $a = $this->register_a();
        unregister_spoke::execute('cp-a');

        $b = register_spoke::execute(self::ADDRESS, 'Beta', 'cp-b');
        $this->assertNotSame($a['token'], $b['token']);
        $row = $DB->get_record('local_nucleushub_spokes', ['id' => $b['spokeId']], '*', MUST_EXIST);
        $this->assertSame('cp-b', $row->cpspokeid);
        $this->assertSame('active', $row->status);
        $this->assertNull(client_registry::find_active($a['clientid']));
        $this->assertFalse($DB->record_exists('external_tokens', ['token' => $a['token']]));
    }

    /**
     * A row with no Nucleus spoke ID (registered by an older caller) can be claimed.
     */
    public function test_row_without_cpspokeid_can_be_claimed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $legacy = register_spoke::execute(self::ADDRESS, 'Legacy', '');

        $b = register_spoke::execute(self::ADDRESS, 'Beta', 'cp-b');
        $this->assertSame($legacy['spokeId'], $b['spokeId']);
        $this->assertSame('cp-b', $DB->get_field('local_nucleushub_spokes', 'cpspokeid', ['id' => $b['spokeId']]));
    }

    /**
     * A different address is fine.
     */
    public function test_other_address_is_fine(): void {
        $this->resetAfterTest();
        $a = $this->register_a();
        $b = register_spoke::execute('https://beta.example.com', 'Beta', 'cp-b');
        $this->assertNotSame($a['spokeId'], $b['spokeId']);
        $this->assert_a_untouched($a);
    }
}
