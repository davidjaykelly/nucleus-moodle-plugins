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

namespace auth_nucleus;

use auth_nucleus\local\accounts;
use auth_nucleus\local\config;
use core_external\external_api;
use local_nucleusspoke\external\apply_links;
use local_nucleusspoke\external\configure_signin;
use local_nucleusspoke\external\disable_signin;
use local_nucleusspoke\external\list_accounts_for_linking;
use local_nucleusspoke\external\signin_status;

/**
 * The spoke web services Nucleus uses to turn hub sign-in on and off.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_nucleusspoke\external\configure_signin
 * @covers     \local_nucleusspoke\external\disable_signin
 * @covers     \local_nucleusspoke\external\list_accounts_for_linking
 * @covers     \local_nucleusspoke\external\apply_links
 * @covers     \local_nucleusspoke\external\signin_status
 * @covers     \auth_nucleus\local\config
 * @covers     \auth_nucleus\local\accounts
 */
final class signin_webservices_test extends \advanced_testcase {
    /** @var string */
    private const HUB = 'https://hub.example.com';

    /** @var string */
    private const ISSUER = self::HUB . '/local/nucleushub/oidc';

    /**
     * An admin caller on a spoke connected to its hub.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('hubwwwroot', self::HUB, 'local_nucleusspoke');
    }

    /**
     * A hub sub: 32 lower-case hex characters.
     *
     * @param int|string $n
     * @return string
     */
    private static function sub($n): string {
        return md5('sub-' . $n);
    }

    /**
     * Configure sign-in as the control plane does (booleans as 1/0).
     *
     * @param string $issuer
     * @return array
     */
    private static function configure(string $issuer = self::ISSUER): array {
        $result = configure_signin::execute($issuer, 'client-1', 'the-client-secret', 'Acme Hub', 1, 1);
        return external_api::clean_returnvalue(configure_signin::execute_returns(), $result);
    }

    /**
     * Configuring stores the secret encrypted and turns the plugin on,
     * keeping manual accounts on.
     */
    public function test_configure_encrypts_secret_and_enables(): void {
        $this->assertTrue(self::configure()['ok']);

        $this->assertTrue(is_enabled_auth('nucleus'));
        $this->assertTrue(is_enabled_auth('manual'));
        $this->assertSame(self::ISSUER, config::issuer());
        $this->assertSame('client-1', config::clientid());
        $this->assertSame('Acme Hub', config::hubname());
        $this->assertTrue(config::autoredirect());
        $this->assertTrue(config::singlesignout());

        $stored = get_config('auth_nucleus', 'clientsecret');
        $this->assertNotSame('the-client-secret', $stored);
        $this->assertStringNotContainsString('the-client-secret', $stored);
        $this->assertSame('the-client-secret', config::clientsecret());

        foreach (config::LOCKED_FIELDS as $field) {
            $this->assertSame('locked', get_config('auth_nucleus', 'field_lock_' . $field));
        }
    }

    /**
     * Booleans sent as 0 turn the options off.
     */
    public function test_configure_with_options_off(): void {
        configure_signin::execute(self::ISSUER, 'client-1', 'secret', 'Acme Hub', 0, 0);
        $this->assertFalse(config::autoredirect());
        $this->assertFalse(config::singlesignout());
    }

    /**
     * The issuer must be exactly {hubwwwroot}/local/nucleushub/oidc.
     */
    public function test_configure_refuses_any_other_issuer(): void {
        foreach ([
            'https://hub.example.com.evil.test/local/nucleushub/oidc',
            self::HUB . '/local/nucleushub/oidc/extra',
            self::HUB . '/local/other',
            self::HUB,
            'http://hub.example.com/local/nucleushub/oidc',
        ] as $issuer) {
            try {
                configure_signin::execute($issuer, 'client-1', 'secret', 'Acme Hub', 1, 1);
                $this->fail('Accepted ' . $issuer);
            } catch (\moodle_exception $e) {
                $this->assertSame('signin_issuernotonhub', $e->errorcode);
            }
        }
        $this->assertFalse(is_enabled_auth('nucleus'));
        // A trailing slash is the same issuer.
        $this->assertTrue(configure_signin::execute(self::ISSUER . '/', 'client-1', 'secret', 'Acme Hub', 1, 1)['ok']);
        $this->assertSame(self::ISSUER, config::issuer());
    }

    /**
     * Only someone who can configure the site may call these.
     */
    public function test_configure_needs_site_config(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        self::configure();
    }

    /**
     * Configuring the same issuer again keeps the links.
     */
    public function test_same_issuer_keeps_links(): void {
        global $DB;

        self::configure();
        $user = $this->getDataGenerator()->create_user();
        accounts::apply_links([['userid' => $user->id, 'sub' => self::sub(1)]]);

        self::configure();
        $this->assertTrue($DB->record_exists('auth_nucleus_link', ['userid' => $user->id]));
        $this->assertSame('nucleus', $DB->get_field('user', 'auth', ['id' => $user->id]));
    }

    /**
     * Switching to another issuer gives hub accounts their own login back
     * and clears every link first.
     */
    public function test_changing_issuer_clears_links_and_converts(): void {
        global $DB;

        self::configure();
        $generator = $this->getDataGenerator();
        $linked = $generator->create_user(['auth' => 'manual']);
        accounts::apply_links([['userid' => $linked->id, 'sub' => self::sub(2)]]);
        $hubmade = $generator->create_user(['auth' => 'nucleus']);

        set_config('hubwwwroot', 'https://newhub.example.com', 'local_nucleusspoke');
        self::configure('https://newhub.example.com/local/nucleushub/oidc');

        $this->assertSame(0, $DB->count_records('auth_nucleus_link'));
        $this->assertSame('manual', $DB->get_field('user', 'auth', ['id' => $linked->id]));
        $this->assertSame('manual', $DB->get_field('user', 'auth', ['id' => $hubmade->id]));
        $this->assertSame('https://newhub.example.com/local/nucleushub/oidc', config::issuer());
        $this->assertTrue(is_enabled_auth('nucleus'));
    }

    /**
     * Turning sign-in off gives every hub account a login of its own (its
     * previous method when still enabled, otherwise manual), one update
     * each, keeps the links, forgets the secret and removes the plugin
     * from $CFG->auth.
     */
    public function test_disable_restores_accounts_and_keeps_links(): void {
        global $CFG, $DB;

        self::configure();
        set_config('auth', $CFG->auth . ',email');
        $generator = $this->getDataGenerator();
        $wasmanual = $generator->create_user(['auth' => 'manual']);
        $wasemail = $generator->create_user(['auth' => 'email']);
        $emailoff = $generator->create_user(['auth' => 'email']);
        accounts::apply_links([
            ['userid' => $wasmanual->id, 'sub' => self::sub(3)],
            ['userid' => $wasemail->id, 'sub' => self::sub(4)],
        ]);
        $hubmade = $generator->create_user(['auth' => 'nucleus']);
        $localuser = $generator->create_user(['auth' => 'manual']);

        $sink = $this->redirectEvents();
        $result = external_api::clean_returnvalue(disable_signin::execute_returns(), disable_signin::execute());
        $updated = array_filter($sink->get_events(), fn($event) => $event instanceof \core\event\user_updated);
        $sink->close();

        $this->assertSame(3, $result['converted']);
        $this->assertCount(3, $updated);
        $this->assertFalse(is_enabled_auth('nucleus'));
        $this->assertTrue(is_enabled_auth('manual'));
        $this->assertSame('manual', $DB->get_field('user', 'auth', ['id' => $wasmanual->id]));
        $this->assertSame('email', $DB->get_field('user', 'auth', ['id' => $wasemail->id]));
        $this->assertSame('manual', $DB->get_field('user', 'auth', ['id' => $hubmade->id]));
        $this->assertSame('manual', $DB->get_field('user', 'auth', ['id' => $localuser->id]));
        $this->assertSame('email', $DB->get_field('user', 'auth', ['id' => $emailoff->id]));
        $this->assertSame(2, $DB->count_records('auth_nucleus_link'));
        $this->assertSame('', (string) get_config('auth_nucleus', 'clientsecret'));
        $this->assertFalse(config::is_configured());
        // The rest stays for the status.
        $this->assertSame(self::ISSUER, config::issuer());

        // Doing it again is harmless.
        $this->assertSame(0, disable_signin::execute()['converted']);
    }

    /**
     * A previous login method whose plugin is off by then becomes manual.
     */
    public function test_disable_uses_manual_when_previous_auth_is_off(): void {
        global $CFG, $DB;

        self::configure();
        set_config('auth', $CFG->auth . ',email');
        $user = $this->getDataGenerator()->create_user(['auth' => 'email']);
        accounts::apply_links([['userid' => $user->id, 'sub' => self::sub(5)]]);
        set_config('auth', 'nucleus');

        disable_signin::execute();
        $this->assertSame('manual', $DB->get_field('user', 'auth', ['id' => $user->id]));
    }

    /**
     * The linking list leaves out deleted accounts and the guest, and
     * flags admins, privileged, suspended and linked accounts.
     */
    public function test_list_accounts_for_linking(): void {
        global $CFG;

        self::configure();
        $generator = $this->getDataGenerator();
        $suspended = $generator->create_user(['suspended' => 1]);
        $deleted = $generator->create_user();
        delete_user($deleted);
        $linked = $generator->create_user();
        accounts::apply_links([['userid' => $linked->id, 'sub' => self::sub(77)]]);
        $manager = $generator->create_user();
        $generator->role_assign('manager', $manager->id);

        $result = external_api::clean_returnvalue(list_accounts_for_linking::execute_returns(),
            list_accounts_for_linking::execute(0, 500));
        $byid = array_column($result['accounts'], null, 'userid');

        $this->assertArrayNotHasKey((int) $CFG->siteguest, $byid);
        $this->assertArrayNotHasKey((int) $deleted->id, $byid);

        $admin = $byid[(int) get_admin()->id];
        $this->assertTrue($admin['siteadmin']);
        $this->assertTrue($admin['privileged']);

        $this->assertFalse($byid[(int) $manager->id]['siteadmin']);
        $this->assertTrue($byid[(int) $manager->id]['privileged']);

        $this->assertTrue($byid[(int) $suspended->id]['suspended']);
        $this->assertFalse($byid[(int) $suspended->id]['siteadmin']);
        $this->assertFalse($byid[(int) $suspended->id]['privileged']);
        $this->assertSame('', $byid[(int) $suspended->id]['sub']);

        $this->assertSame(self::sub(77), $byid[(int) $linked->id]['sub']);
        $this->assertSame('nucleus', $byid[(int) $linked->id]['auth']);
        $this->assertSame(count($byid), $result['total']);
    }

    /**
     * perpage is capped at 500 and pages don't overlap.
     */
    public function test_list_accounts_pages(): void {
        $generator = $this->getDataGenerator();
        for ($i = 0; $i < 3; $i++) {
            $generator->create_user();
        }
        $first = list_accounts_for_linking::execute(0, 2);
        $second = list_accounts_for_linking::execute(1, 2);
        $this->assertCount(2, $first['accounts']);
        $this->assertSame($first['total'], $second['total']);
        $this->assertEmpty(array_intersect(
            array_column($first['accounts'], 'userid'),
            array_column($second['accounts'], 'userid')
        ));

        $capped = list_accounts_for_linking::execute(0, 100000);
        $this->assertLessThanOrEqual(500, count($capped['accounts']));
    }

    /**
     * apply_links through the web service, with its return structure.
     */
    public function test_apply_links(): void {
        global $DB;

        self::configure();
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $manager = $generator->create_user();
        $generator->role_assign('manager', $manager->id);

        $result = external_api::clean_returnvalue(apply_links::execute_returns(), apply_links::execute([
            ['userid' => $user->id, 'sub' => self::sub(500)],
            ['userid' => get_admin()->id, 'sub' => self::sub(501)],
            ['userid' => $manager->id, 'sub' => self::sub(502)],
        ]));
        $this->assertSame(1, $result['applied']);
        $this->assertSame([
            ['userid' => (int) get_admin()->id, 'reason' => 'siteadmin'],
            ['userid' => (int) $manager->id, 'reason' => 'privileged'],
        ], $result['skipped']);
        $this->assertSame('nucleus', $DB->get_field('user', 'auth', ['id' => $user->id]));
        $this->assertSame(self::ISSUER, $DB->get_field('auth_nucleus_link', 'issuer', ['userid' => $user->id]));
        $this->assertSame('manual', $DB->get_field('auth_nucleus_link', 'previousauth', ['userid' => $user->id]));
    }

    /**
     * The status reports what's set, never the secret.
     */
    public function test_signin_status(): void {
        $before = external_api::clean_returnvalue(signin_status::execute_returns(), signin_status::execute());
        $this->assertFalse($before['enabled']);

        self::configure();
        $user = $this->getDataGenerator()->create_user();
        accounts::apply_links([['userid' => $user->id, 'sub' => self::sub(600)]]);

        $after = external_api::clean_returnvalue(signin_status::execute_returns(), signin_status::execute());
        $this->assertSame([
            'enabled' => true,
            'issuer' => self::ISSUER,
            'clientid' => 'client-1',
            'linked' => 1,
        ], $after);
        $this->assertStringNotContainsString('the-client-secret', json_encode($after));
    }
}
