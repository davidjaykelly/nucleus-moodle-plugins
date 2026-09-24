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

namespace local_nucleushub\local\oidc;

/**
 * Tests for spoke client registration, deletion and moves.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(client_registry::class)]
final class client_registry_test extends \advanced_testcase {
    /**
     * The generator.
     *
     * @return \local_nucleushub_generator
     */
    private function generator(): \local_nucleushub_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_nucleushub');
    }

    /**
     * Registering stores a hash of a strong secret, derives the URIs from the
     * spoke row, keeps the client id on re-registration and changes the secret.
     */
    public function test_register(): void {
        global $DB;
        $this->resetAfterTest();
        $spoke = $this->generator()->create_spoke(['wwwroot' => 'https://acme.example.com']);

        $first = client_registry::register($spoke->cpspokeid);
        $this->assertSame(provider::issuer(), $first['issuer']);
        $this->assertGreaterThanOrEqual(43, strlen($first['clientsecret']));
        $row = $DB->get_record(client_registry::TABLE, ['clientid' => $first['clientid']], '*', MUST_EXIST);
        $this->assertEquals($spoke->id, $row->spokeid);
        $this->assertSame('https://acme.example.com/auth/nucleus/callback.php', $row->redirecturi);
        $this->assertSame('https://acme.example.com/', $row->postlogouturi);
        $this->assertStringNotContainsString($first['clientsecret'], $row->secrethash);
        // A plain SHA-256: the secret is 256 random bits, so no slow hash.
        $this->assertSame(hash('sha256', $first['clientsecret']), $row->secrethash);
        $this->assertSame(client_registry::hash_secret($first['clientsecret']), $row->secrethash);

        $second = client_registry::register($spoke->cpspokeid);
        $this->assertSame($first['clientid'], $second['clientid']);
        $this->assertNotSame($first['clientsecret'], $second['clientsecret']);
        $this->assertSame(1, $DB->count_records(client_registry::TABLE));
        $this->assertNull(client_registry::authenticate($first['clientid'], $first['clientsecret']));
        $this->assertNotNull(client_registry::authenticate($first['clientid'], $second['clientsecret']));
    }

    /**
     * Registration needs an active spoke with that ID.
     *
     * @return array
     */
    public static function no_spoke_provider(): array {
        return [
            'unknown' => ['cp-unknown', 'active'],
            'empty' => ['', 'active'],
            'suspended' => [null, 'suspended'],
            'removed' => [null, 'removed'],
        ];
    }

    /**
     * No active spoke, no client.
     *
     * @param string|null $cpspokeid Null for the created spoke's own ID.
     * @param string $status
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('no_spoke_provider')]
    public function test_register_needs_active_spoke(?string $cpspokeid, string $status): void {
        $this->resetAfterTest();
        $spoke = $this->generator()->create_spoke(['status' => $status]);
        $this->expectException(\moodle_exception::class);
        client_registry::register($cpspokeid ?? $spoke->cpspokeid);
    }

    /**
     * Spoke addresses that can't be used for sign-in.
     *
     * @return array
     */
    public static function bad_address_provider(): array {
        return [
            'http while the hub is https' => ['http://acme.example.com'],
            'no host' => ['https://'],
            'not a URL' => ['acme'],
            'query' => ['https://acme.example.com?x=1'],
            'fragment' => ['https://acme.example.com#x'],
            'credentials' => ['https://user:pass@acme.example.com'],
            'other scheme' => ['ftp://acme.example.com'],
            'quote' => ['https://acme.example.com/"x'],
        ];
    }

    /**
     * Bad spoke addresses are refused.
     *
     * @param string $wwwroot
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_address_provider')]
    public function test_register_refuses_bad_address(string $wwwroot): void {
        global $CFG;
        $this->resetAfterTest();
        // The test site's own address is https.
        $this->assertStringStartsWith('https://', $CFG->wwwroot);
        $spoke = $this->generator()->create_spoke(['wwwroot' => $wwwroot]);
        $this->expectException(\moodle_exception::class);
        client_registry::register($spoke->cpspokeid);
    }

    /**
     * A spoke under a path works, and the URIs keep the path.
     */
    public function test_register_with_path(): void {
        $this->resetAfterTest();
        $uris = client_registry::uris_for('https://example.com/moodle/');
        $this->assertSame('https://example.com/moodle/auth/nucleus/callback.php', $uris['redirecturi']);
        $this->assertSame('https://example.com/moodle/', $uris['postlogouturi']);
    }

    /**
     * Deleting removes every client of the spoke with its codes and tokens,
     * leaves other spokes alone, and is idempotent.
     */
    public function test_delete(): void {
        global $DB;
        $this->resetAfterTest();
        $a = $this->generator()->create_oidc_client();
        $b = $this->generator()->create_oidc_client();
        $user = $this->getDataGenerator()->create_user();
        foreach ([$a, $b] as $registered) {
            $this->generator()->create_code($registered['client'], (int) $user->id, str_repeat('v', 43));
            tokens::issue_access_token($registered['client'], (int) $user->id);
        }

        client_registry::delete($a['spoke']->cpspokeid);
        $this->assertNull(client_registry::find_active($a['clientid']));
        $this->assertSame(0, $DB->count_records(tokens::CODE_TABLE, ['clientid' => $a['clientid']]));
        $this->assertSame(0, $DB->count_records(tokens::TOKEN_TABLE, ['clientid' => $a['clientid']]));
        $this->assertNotNull(client_registry::find_active($b['clientid']));
        $this->assertSame(1, $DB->count_records(tokens::CODE_TABLE, ['clientid' => $b['clientid']]));

        client_registry::delete($a['spoke']->cpspokeid);
        client_registry::delete('cp-never-existed');
        $this->assertSame(1, $DB->count_records(client_registry::TABLE));
    }

    /**
     * Authentication refuses unknown clients, wrong and empty secrets.
     */
    public function test_authenticate(): void {
        $this->resetAfterTest();
        $registered = $this->generator()->create_oidc_client();
        $this->assertNotNull(client_registry::authenticate($registered['clientid'], $registered['secret']));
        $this->assertNull(client_registry::authenticate($registered['clientid'], ''));
        $this->assertNull(client_registry::authenticate($registered['clientid'], $registered['secret'] . 'x'));
        $this->assertNull(client_registry::authenticate('nucleus-unknown', $registered['secret']));
        $this->assertNull(client_registry::authenticate(strtoupper($registered['clientid']), $registered['secret']));
        $this->assertNull(client_registry::authenticate('', ''));
    }

    /**
     * A spoke that moves address keeps its client, with new URIs; its codes are dropped.
     */
    public function test_move_follows_the_spoke(): void {
        global $DB;
        $this->resetAfterTest();
        $registered = $this->generator()->create_oidc_client(['wwwroot' => 'https://old.example.com']);
        $user = $this->getDataGenerator()->create_user();
        $this->generator()->create_code($registered['client'], (int) $user->id, str_repeat('v', 43));
        $new = $this->generator()->create_spoke([
            'wwwroot' => 'https://new.example.com',
            'cpspokeid' => $registered['spoke']->cpspokeid,
        ]);

        client_registry::move((int) $registered['spoke']->id, $new);
        $client = client_registry::find_active($registered['clientid']);
        $this->assertEquals($new->id, $client->spokeid);
        $this->assertSame('https://new.example.com/auth/nucleus/callback.php', $client->redirecturi);
        $this->assertSame('https://new.example.com/', $client->postlogouturi);
        $this->assertSame(0, $DB->count_records(tokens::CODE_TABLE));
        $this->assertNotNull(client_registry::authenticate($registered['clientid'], $registered['secret']));
    }

    /**
     * Unregistering a spoke deletes its client too.
     */
    public function test_unregister_spoke_deletes_client(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $registered = $this->generator()->create_oidc_client();
        \local_nucleushub\external\unregister_spoke::execute($registered['spoke']->cpspokeid);
        $this->assertNull(client_registry::find_active($registered['clientid']));
        $this->assertFalse(client_registry::is_post_logout_uri($registered['client']->postlogouturi));
    }
}
