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

namespace local_nucleushub\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use local_nucleushub\local\oidc\subjects;
use local_nucleushub\local\oidc\tokens;

/**
 * Privacy tests for the sign-in data.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * The code and token tables and the flow to spokes are declared.
     */
    public function test_metadata(): void {
        $items = provider::get_metadata(new collection('local_nucleushub'))->get_collection();
        $names = array_map(fn($item) => $item->get_name(), $items);
        $this->assertContains('local_nucleushub_oidc_code', $names);
        $this->assertContains('local_nucleushub_oidc_token', $names);
        $this->assertContains('local_nucleushub_oidc_subject', $names);
        $this->assertContains('spokes', $names);
        foreach ($items as $item) {
            if ($item->get_name() === 'spokes') {
                $fields = array_keys($item->get_privacy_fields());
                foreach (['sub', 'email', 'given_name', 'family_name', 'locale'] as $field) {
                    $this->assertContains($field, $fields);
                }
            }
        }
    }

    /**
     * A person's codes, tokens and subject are found and deleted, and nobody else's.
     */
    public function test_find_and_delete_signins(): void {
        global $DB;
        $this->resetAfterTest();
        /** @var \local_nucleushub_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nucleushub');
        $registered = $generator->create_oidc_client();
        $alice = $this->getDataGenerator()->create_user();
        $bob = $this->getDataGenerator()->create_user();
        foreach ([$alice, $bob] as $user) {
            $generator->create_code($registered['client'], (int) $user->id, str_repeat('v', 43));
            tokens::issue_access_token($registered['client'], (int) $user->id);
            subjects::for_user((int) $user->id);
        }
        $carol = $this->getDataGenerator()->create_user();
        subjects::for_user((int) $carol->id);
        $system = \context_system::instance();

        $contexts = provider::get_contexts_for_userid((int) $alice->id)->get_contextids();
        $this->assertEquals([$system->id], array_values($contexts));
        $userlist = new userlist($system, 'local_nucleushub');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing(
            [(int) $alice->id, (int) $bob->id, (int) $carol->id],
            array_map('intval', $userlist->get_userids())
        );
        // A subject alone is personal data too.
        $this->assertNotEmpty(provider::get_contexts_for_userid((int) $carol->id)->get_contextids());

        provider::delete_data_for_user(new approved_contextlist($alice, 'local_nucleushub', [$system->id]));
        $this->assertSame(0, $DB->count_records(tokens::CODE_TABLE, ['userid' => $alice->id]));
        $this->assertSame(0, $DB->count_records(tokens::TOKEN_TABLE, ['userid' => $alice->id]));
        $this->assertNull(subjects::find_for_user((int) $alice->id));
        $this->assertSame(1, $DB->count_records(tokens::CODE_TABLE, ['userid' => $bob->id]));
        $this->assertNotNull(subjects::find_for_user((int) $bob->id));

        provider::delete_data_for_users(new approved_userlist($system, 'local_nucleushub', [(int) $bob->id]));
        $this->assertSame(0, $DB->count_records(tokens::CODE_TABLE));
        $this->assertSame(0, $DB->count_records(tokens::TOKEN_TABLE));
        $this->assertNull(subjects::find_for_user((int) $bob->id));
        $this->assertNotNull(subjects::find_for_user((int) $carol->id));
    }
}
