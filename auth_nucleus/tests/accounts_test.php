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
use auth_nucleus\local\signin_exception;

/**
 * Linking rules: by issuer and sub only, never by email, never a
 * privileged account; and giving accounts their own login back.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \auth_nucleus\local\accounts
 * @covers     \auth_nucleus\observer
 */
final class accounts_test extends \advanced_testcase {
    /** @var string The configured issuer. */
    private const ISSUER = 'https://hub.example.com/local/nucleushub/oidc';

    /**
     * Reset the database after each test; sign-in is set up for ISSUER.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('issuer', self::ISSUER, 'auth_nucleus');
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
     * Verified claims for a hub person.
     *
     * @param int|string $n Which person (their sub is self::sub($n)).
     * @param array $overrides
     * @return \stdClass
     */
    private static function claims($n, array $overrides = []): \stdClass {
        return (object) array_merge([
            'iss' => self::ISSUER,
            'sub' => self::sub($n),
            'email' => "person{$n}@example.com",
            'given_name' => 'Pat',
            'family_name' => 'Example',
            'locale' => 'en',
        ], $overrides);
    }

    /**
     * Link an account directly.
     *
     * @param int $userid
     * @param int|string $n Which person.
     * @param string|null $previousauth
     * @param string $issuer
     */
    private static function link(int $userid, $n, ?string $previousauth = null, string $issuer = self::ISSUER): void {
        global $DB;
        $DB->insert_record('auth_nucleus_link', (object) [
            'userid' => $userid,
            'issuer' => $issuer,
            'issuerhash' => accounts::issuer_hash($issuer),
            'sub' => self::sub($n),
            'previousauth' => $previousauth,
            'timecreated' => time(),
        ]);
    }

    /**
     * Give an account the manager role at site level (privileged).
     *
     * @param int $userid
     */
    private function make_manager(int $userid): void {
        $this->getDataGenerator()->role_assign('manager', $userid);
    }

    /**
     * Assert that sign-in is refused with this reason.
     *
     * @param \stdClass $claims
     * @param string $reason
     * @return signin_exception
     */
    private function assert_refused(\stdClass $claims, string $reason): signin_exception {
        try {
            accounts::sign_in($claims);
        } catch (signin_exception $e) {
            $this->assertSame($reason, $e->reasoncode);
            return $e;
        }
        $this->fail('Sign-in was allowed');
    }

    /**
     * A first sign-in creates a hub account and links it to the issuer.
     */
    public function test_new_person_gets_an_account(): void {
        global $DB;

        $user = accounts::sign_in(self::claims(42));
        $this->assertSame('hub-' . self::sub(42), $user->username);
        $this->assertSame('nucleus', $user->auth);
        $this->assertEquals(1, $user->confirmed);
        $this->assertSame(AUTH_PASSWORD_NOT_CACHED, $DB->get_field('user', 'password', ['id' => $user->id]));
        $this->assertSame('person42@example.com', $user->email);
        $this->assertSame('en', $user->lang);

        $link = $DB->get_record('auth_nucleus_link', ['userid' => $user->id]);
        $this->assertSame(self::ISSUER, $link->issuer);
        $this->assertSame(hash('sha256', self::ISSUER), $link->issuerhash);
        $this->assertSame(self::sub(42), $link->sub);
        $this->assertNull($link->previousauth);

        // The next sign-in finds the same account.
        $again = accounts::sign_in(self::claims(42));
        $this->assertEquals($user->id, $again->id);
        $this->assertSame(1, $DB->count_records('user', ['username' => 'hub-' . self::sub(42)]));
    }

    /**
     * Language comes from the hub only when the account is made.
     */
    public function test_language_is_only_set_when_the_account_is_made(): void {
        global $DB;

        $user = accounts::sign_in(self::claims(43));
        $DB->set_field('user', 'lang', 'xx', ['id' => $user->id]);
        $again = accounts::sign_in(self::claims(43));
        $this->assertSame('xx', $again->lang);
    }

    /**
     * Claims that aren't from the configured issuer are refused.
     */
    public function test_claims_from_another_issuer_are_refused(): void {
        $this->assert_refused(self::claims(44, ['iss' => 'https://other.example.com/local/nucleushub/oidc']), 'notenabled');
        unset_config('issuer', 'auth_nucleus');
        $this->assert_refused(self::claims(44), 'notenabled');
    }

    /**
     * An unlinked sub whose email an account here already has is refused:
     * nothing is linked and no second account is made.
     */
    public function test_existing_email_is_refused_not_linked(): void {
        global $DB;

        $existing = $this->getDataGenerator()->create_user(['email' => 'Person42@Example.com']);

        $e = $this->assert_refused(self::claims(42), 'emailexists');
        $this->assertSame('person42@example.com', $e->a);
        $this->assertStringContainsString('local account ' . $existing->id . ' ', $e->debuginfo);
        $this->assertSame(
            'There\'s already an account for person42@example.com on this site. '
                . 'Ask the site\'s administrator to link it to your hub account in Nucleus.',
            get_string('error_emailexists', 'auth_nucleus', $e->a)
        );

        $this->assertFalse($DB->record_exists('auth_nucleus_link', ['sub' => self::sub(42)]));
        $this->assertFalse($DB->record_exists('auth_nucleus_link', ['userid' => $existing->id]));
        $this->assertFalse($DB->record_exists('user', ['username' => 'hub-' . self::sub(42)]));
        $this->assertSame('manual', $DB->get_field('user', 'auth', ['id' => $existing->id]));
    }

    /**
     * A site admin's email is refused the same way, and the admin untouched.
     */
    public function test_site_admin_email_is_refused_not_linked(): void {
        global $DB;

        $admin = get_admin();
        $e = $this->assert_refused(self::claims(7, ['email' => $admin->email]), 'emailexists');
        $this->assertStringContainsString('privileged local account', $e->debuginfo);
        $this->assertFalse($DB->record_exists('auth_nucleus_link', ['userid' => $admin->id]));
        $this->assertSame($admin->auth, $DB->get_field('user', 'auth', ['id' => $admin->id]));
        $this->assertSame($admin->password, $DB->get_field('user', 'password', ['id' => $admin->id]));
    }

    /**
     * A privileged account's email is refused too, and nothing is created.
     */
    public function test_privileged_email_is_refused_not_created(): void {
        global $DB;

        $manager = $this->getDataGenerator()->create_user(['email' => 'person45@example.com']);
        $this->make_manager($manager->id);
        $e = $this->assert_refused(self::claims(45), 'emailexists');
        $this->assertStringContainsString('privileged local account ' . $manager->id . ' ', $e->debuginfo);
        $this->assertFalse($DB->record_exists('user', ['username' => 'hub-' . self::sub(45)]));
        $this->assertSame('manual', $DB->get_field('user', 'auth', ['id' => $manager->id]));
    }

    /**
     * Suspended accounts count as existing accounts for the email check.
     */
    public function test_suspended_account_email_is_refused(): void {
        $this->getDataGenerator()->create_user(['email' => 'person8@example.com', 'suspended' => 1]);
        $this->assert_refused(self::claims(8), 'emailexists');
    }

    /**
     * A deleted account with the email doesn't block a new account.
     */
    public function test_deleted_account_email_does_not_block(): void {
        $old = $this->getDataGenerator()->create_user(['email' => 'person9@example.com']);
        delete_user($old);

        $user = accounts::sign_in(self::claims(9));
        $this->assertSame('hub-' . self::sub(9), $user->username);
    }

    /**
     * A linked person signs in to their account, and the hub's profile wins.
     */
    public function test_linked_person_signs_in_and_profile_is_refreshed(): void {
        $user = $this->getDataGenerator()->create_user([
            'auth' => 'nucleus',
            'email' => 'old@example.com',
            'firstname' => 'Old',
            'lastname' => 'Name',
        ]);
        self::link($user->id, 46);

        $signedin = accounts::sign_in(self::claims(46, [
            'email' => 'new@example.com',
            'given_name' => 'New',
            'family_name' => 'Person',
        ]));
        $this->assertEquals($user->id, $signedin->id);
        $this->assertSame('new@example.com', $signedin->email);
        $this->assertSame('New', $signedin->firstname);
        $this->assertSame('Person', $signedin->lastname);
    }

    /**
     * A link made for another issuer is never used.
     */
    public function test_link_for_another_issuer_is_not_used(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['auth' => 'nucleus', 'email' => 'old@example.com']);
        self::link($user->id, 47, null, 'https://other.example.com/local/nucleushub/oidc');

        $signedin = accounts::sign_in(self::claims(47));
        $this->assertNotEquals($user->id, $signedin->id);
        $this->assertSame('old@example.com', $DB->get_field('user', 'email', ['id' => $user->id]));
    }

    /**
     * A linked account made manual when sign-in was off becomes a hub
     * account again, without a local password.
     */
    public function test_linked_manual_account_becomes_hub_account(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['auth' => 'manual', 'password' => 'Old-password-1']);
        self::link($user->id, 48, 'manual');
        set_user_preference('auth_forcepasswordchange', 1, $user->id);

        $signedin = accounts::sign_in(self::claims(48));
        $this->assertSame('nucleus', $signedin->auth);
        $this->assertSame(AUTH_PASSWORD_NOT_CACHED, $DB->get_field('user', 'password', ['id' => $user->id]));
        $this->assertNull(get_user_preferences('auth_forcepasswordchange', null, $user->id));
    }

    /**
     * An account given back its previous login method signs in again.
     */
    public function test_linked_account_with_its_previous_auth_signs_in(): void {
        $user = $this->getDataGenerator()->create_user(['auth' => 'email']);
        self::link($user->id, 49, 'email');

        $this->assertSame('nucleus', accounts::sign_in(self::claims(49))->auth);
    }

    /**
     * A site admin is never signed in to, even when linked.
     */
    public function test_linked_site_admin_is_refused(): void {
        global $CFG, $DB;

        $admin = get_admin();
        self::link($admin->id, 50);
        $this->assert_refused(self::claims(50, ['email' => 'someone@example.com']), 'siteadmin');
        $this->assertSame($admin->auth, $DB->get_field('user', 'auth', ['id' => $admin->id]));

        // Nor an account made an admin after it was linked.
        $user = $this->getDataGenerator()->create_user(['auth' => 'nucleus']);
        self::link($user->id, 51);
        set_config('siteadmins', $CFG->siteadmins . ',' . $user->id);
        $this->assert_refused(self::claims(51), 'siteadmin');
    }

    /**
     * A privileged account is never signed in to, even when linked.
     */
    public function test_linked_privileged_account_is_refused(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['auth' => 'nucleus']);
        self::link($user->id, 52);
        $this->make_manager($user->id);
        $e = $this->assert_refused(self::claims(52), 'privileged');
        $this->assertEquals($user->id, $e->userid);
        $this->assertSame('nucleus', $DB->get_field('user', 'auth', ['id' => $user->id]));
    }

    /**
     * Any role at site level makes an account privileged, and so does a
     * privileged capability at site level however it's granted.
     */
    public function test_what_counts_as_privileged(): void {
        global $CFG;

        $generator = $this->getDataGenerator();
        $plain = $generator->create_user();
        $this->assertFalse(accounts::is_privileged($plain->id));
        $this->assertTrue(accounts::is_privileged(get_admin()->id));

        $creator = $generator->create_user();
        $generator->role_assign('coursecreator', $creator->id);
        $this->assertTrue(accounts::is_privileged($creator->id));

        // A course role doesn't count.
        $teacher = $generator->create_user();
        $course = $generator->create_course();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->assertFalse(accounts::is_privileged($teacher->id));

        // A site-level capability through the role every user has.
        assign_capability('moodle/user:update', CAP_ALLOW, $CFG->defaultuserroleid,
            \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(accounts::is_privileged($plain->id));
    }

    /**
     * When every account would be privileged, none is created at sign-in.
     */
    public function test_privileged_new_account_is_not_created(): void {
        global $CFG, $DB;

        assign_capability('moodle/role:assign', CAP_ALLOW, $CFG->defaultuserroleid,
            \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assert_refused(self::claims(53), 'privileged');
        $this->assertFalse($DB->record_exists('user', ['username' => 'hub-' . self::sub(53)]));
        $this->assertFalse($DB->record_exists('auth_nucleus_link', ['sub' => self::sub(53)]));
    }

    /**
     * A suspended linked account is refused.
     */
    public function test_linked_suspended_account_is_refused(): void {
        $user = $this->getDataGenerator()->create_user(['auth' => 'nucleus', 'suspended' => 1]);
        self::link($user->id, 54);
        $this->assert_refused(self::claims(54), 'account');
    }

    /**
     * A deleted linked account is refused.
     */
    public function test_linked_deleted_account_is_refused(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['auth' => 'nucleus']);
        self::link($user->id, 55);
        // Marked deleted without delete_user(), which would remove the link.
        $DB->set_field('user', 'deleted', 1, ['id' => $user->id]);
        $this->assert_refused(self::claims(55), 'account');
    }

    /**
     * A linked account that has been blocked (nologin) is refused.
     */
    public function test_linked_nologin_account_is_refused(): void {
        $user = $this->getDataGenerator()->create_user(['auth' => 'nologin']);
        self::link($user->id, 56);
        $this->assert_refused(self::claims(56), 'account');
    }

    /**
     * An unlinked account that happens to have the username is never taken over.
     */
    public function test_username_taken_is_refused(): void {
        global $DB;

        $squatter = $this->getDataGenerator()->create_user([
            'username' => 'hub-' . self::sub(60),
            'email' => 'other@example.com',
        ]);
        $this->assert_refused(self::claims(60), 'account');
        $this->assertFalse($DB->record_exists('auth_nucleus_link', ['userid' => $squatter->id]));
    }

    /**
     * "Prevent account creation when authenticating" is respected.
     */
    public function test_account_creation_can_be_prevented(): void {
        set_config('authpreventaccountcreation', 1);
        $this->assert_refused(self::claims(61), 'nocreate');
    }

    /**
     * Sign-in needs a valid sub, an email address and both names.
     */
    public function test_missing_claims_are_refused(): void {
        $this->assert_refused(self::claims(62, ['sub' => '62']), 'claims');
        $this->assert_refused(self::claims(62, ['sub' => strtoupper(self::sub(62))]), 'claims');
        $this->assert_refused(self::claims(62, ['email' => '']), 'claims');
        $this->assert_refused(self::claims(62, ['email' => 'not an email']), 'claims');
        $this->assert_refused(self::claims(62, ['given_name' => '']), 'claims');
        $claims = self::claims(62);
        unset($claims->family_name);
        $this->assert_refused($claims, 'claims');
    }

    /**
     * Deleting an account removes its link, so the next sign-in makes a new one.
     */
    public function test_deleting_an_account_removes_its_link(): void {
        global $DB;

        $user = accounts::sign_in(self::claims(63));
        delete_user($DB->get_record('user', ['id' => $user->id]));
        $this->assertFalse($DB->record_exists('auth_nucleus_link', ['sub' => self::sub(63)]));

        $again = accounts::sign_in(self::claims(63));
        $this->assertNotEquals($user->id, $again->id);
    }

    /**
     * apply_links links ordinary accounts and skips everything else, with
     * the reason codes the portal maps.
     */
    public function test_apply_links_rules(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $admin = get_admin();
        $plain = $generator->create_user(['auth' => 'manual']);
        $manager = $generator->create_user();
        $this->make_manager($manager->id);
        $suspended = $generator->create_user(['suspended' => 1]);
        $deleted = $generator->create_user();
        delete_user($deleted);
        $linked = $generator->create_user();
        self::link($linked->id, 100, 'manual');
        $other = $generator->create_user();
        $service = $generator->create_user(['auth' => 'webservice']);

        $result = accounts::apply_links([
            ['userid' => $plain->id, 'sub' => self::sub(200)],
            ['userid' => $admin->id, 'sub' => self::sub(201)],
            ['userid' => $manager->id, 'sub' => self::sub(207)],
            ['userid' => $suspended->id, 'sub' => self::sub(202)],
            ['userid' => $deleted->id, 'sub' => self::sub(203)],
            ['userid' => $linked->id, 'sub' => self::sub(204)],
            ['userid' => $other->id, 'sub' => self::sub(100)],
            ['userid' => $service->id, 'sub' => self::sub(205)],
            ['userid' => 999999, 'sub' => self::sub(206)],
            ['userid' => $other->id, 'sub' => 'not-a-sub'],
            ['userid' => $other->id, 'sub' => '208'],
        ]);

        $this->assertSame(1, $result['applied']);
        $reasons = [];
        foreach ($result['skipped'] as $skip) {
            $reasons[] = [$skip['userid'], $skip['reason']];
        }
        $this->assertSame([
            [(int) $admin->id, 'siteadmin'],
            [(int) $manager->id, 'privileged'],
            [(int) $suspended->id, 'suspended'],
            [(int) $deleted->id, 'deleted'],
            [(int) $linked->id, 'already_linked'],
            [(int) $other->id, 'sub_linked'],
            [(int) $service->id, 'not_allowed'],
            [999999, 'not_found'],
            [(int) $other->id, 'invalid_sub'],
            [(int) $other->id, 'invalid_sub'],
        ], $reasons);

        // The applied link is stored against the issuer with the account's
        // login method, and made it a hub account with no password.
        $link = $DB->get_record('auth_nucleus_link', ['userid' => $plain->id]);
        $this->assertSame(self::ISSUER, $link->issuer);
        $this->assertSame(self::sub(200), $link->sub);
        $this->assertSame('manual', $link->previousauth);
        $this->assertSame('nucleus', $DB->get_field('user', 'auth', ['id' => $plain->id]));
        $this->assertSame(AUTH_PASSWORD_NOT_CACHED, $DB->get_field('user', 'password', ['id' => $plain->id]));

        // Nothing else changed.
        $this->assertSame($admin->auth, $DB->get_field('user', 'auth', ['id' => $admin->id]));
        $this->assertSame('manual', $DB->get_field('user', 'auth', ['id' => $manager->id]));
        $this->assertFalse($DB->record_exists('auth_nucleus_link', ['userid' => $admin->id]));
        $this->assertFalse($DB->record_exists('auth_nucleus_link', ['userid' => $manager->id]));
        $this->assertSame(1, $DB->count_records('auth_nucleus_link', ['userid' => $linked->id]));
        $this->assertSame('webservice', $DB->get_field('user', 'auth', ['id' => $service->id]));
    }

    /**
     * Applying the same link twice is harmless and keeps the first login method.
     */
    public function test_apply_links_is_idempotent(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['auth' => 'email']);
        $links = [['userid' => $user->id, 'sub' => self::sub(300)]];
        $this->assertSame(1, accounts::apply_links($links)['applied']);
        $this->assertSame(1, accounts::apply_links($links)['applied']);
        $this->assertSame(1, $DB->count_records('auth_nucleus_link', ['sub' => self::sub(300)]));
        $this->assertSame('email', $DB->get_field('auth_nucleus_link', 'previousauth', ['userid' => $user->id]));
    }

    /**
     * Nothing can be linked before an issuer is configured.
     */
    public function test_apply_links_needs_an_issuer(): void {
        unset_config('issuer', 'auth_nucleus');
        $user = $this->getDataGenerator()->create_user();
        $this->expectException(\moodle_exception::class);
        accounts::apply_links([['userid' => $user->id, 'sub' => self::sub(301)]]);
    }

    /**
     * A linked account signs in through its link even though its email
     * matches: the email check is only for unlinked subs.
     */
    public function test_linked_account_with_same_email_signs_in(): void {
        $user = $this->getDataGenerator()->create_user(['email' => 'person302@example.com']);
        accounts::apply_links([['userid' => $user->id, 'sub' => self::sub(302)]]);

        $signedin = accounts::sign_in(self::claims(302));
        $this->assertEquals($user->id, $signedin->id);
    }

    /**
     * Giving accounts their own login back: the previous method when it's
     * still enabled, otherwise manual; hub-made accounts become manual.
     * Links stay.
     */
    public function test_release_restores_previous_auth(): void {
        global $DB;

        set_config('auth', 'email');
        $generator = $this->getDataGenerator();
        $wasmanual = $generator->create_user(['auth' => 'manual']);
        $wasemail = $generator->create_user(['auth' => 'email']);
        $wasdisabled = $generator->create_user(['auth' => 'email']);
        accounts::apply_links([
            ['userid' => $wasmanual->id, 'sub' => self::sub(400)],
            ['userid' => $wasemail->id, 'sub' => self::sub(401)],
            ['userid' => $wasdisabled->id, 'sub' => self::sub(402)],
        ]);
        // This one's plugin is turned off before sign-in with the hub is.
        $DB->set_field('auth_nucleus_link', 'previousauth', 'ldap', ['userid' => $wasdisabled->id]);
        $hubmade = accounts::sign_in(self::claims(403));

        $sink = $this->redirectEvents();
        $this->assertSame(4, accounts::release_hub_accounts());
        $updated = array_filter($sink->get_events(), fn($event) => $event instanceof \core\event\user_updated);
        $sink->close();

        $this->assertCount(4, $updated);
        $this->assertSame('manual', $DB->get_field('user', 'auth', ['id' => $wasmanual->id]));
        $this->assertSame('email', $DB->get_field('user', 'auth', ['id' => $wasemail->id]));
        $this->assertSame('manual', $DB->get_field('user', 'auth', ['id' => $wasdisabled->id]));
        $this->assertSame('manual', $DB->get_field('user', 'auth', ['id' => $hubmade->id]));
        $this->assertSame(4, $DB->count_records('auth_nucleus_link'));
        $this->assertSame(0, $DB->count_records('user', ['auth' => 'nucleus']));
    }
}
