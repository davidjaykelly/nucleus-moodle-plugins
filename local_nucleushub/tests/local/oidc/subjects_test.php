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
 * Tests for opaque subjects (the sub claim).
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(subjects::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_nucleushub\observer\oidc_subjects::class)]
final class subjects_test extends \advanced_testcase {
    /**
     * A subject is 32 lowercase hex characters, stable per user, different
     * between users, and never the user id.
     */
    public function test_format_and_stability(): void {
        $this->resetAfterTest();
        $alice = $this->getDataGenerator()->create_user();
        $bob = $this->getDataGenerator()->create_user();

        $this->assertNull(subjects::find_for_user((int) $alice->id));
        $sub = subjects::for_user((int) $alice->id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $sub);
        $this->assertSame(32, strlen($sub));
        $this->assertSame($sub, subjects::for_user((int) $alice->id));
        $this->assertSame($sub, subjects::find_for_user((int) $alice->id));
        $this->assertNotSame($sub, subjects::for_user((int) $bob->id));
        $this->assertNotSame((string) $alice->id, $sub);
        $this->assertMatchesRegularExpression(subjects::PATTERN, subjects::generate());
    }

    /**
     * Looking a subject up never makes one.
     */
    public function test_find_does_not_create(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->assertNull(subjects::find_for_user((int) $user->id));
        $this->assertNull(subjects::find_for_user(0));
        $this->assertSame(0, $DB->count_records(subjects::TABLE));
    }

    /**
     * Losing the race to another request returns the winner's subject.
     */
    public function test_lost_race_uses_the_winners_subject(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $winner = subjects::generate();

        $sub = subjects::for_user((int) $user->id, function () use ($DB, $user, $winner) {
            // Another request inserts first, just before this one does.
            $DB->insert_record(subjects::TABLE, (object) [
                'userid' => $user->id,
                'sub' => $winner,
                'timecreated' => time(),
            ]);
            return subjects::generate();
        });

        $this->assertSame($winner, $sub);
        $this->assertSame(1, $DB->count_records(subjects::TABLE, ['userid' => $user->id]));
    }

    /**
     * A random value that is already taken is replaced by a new one.
     */
    public function test_taken_value_is_retried(): void {
        global $DB;
        $this->resetAfterTest();
        $alice = $this->getDataGenerator()->create_user();
        $bob = $this->getDataGenerator()->create_user();
        $taken = subjects::for_user((int) $alice->id);
        $fresh = subjects::generate();
        $values = [$taken, $fresh];

        $sub = subjects::for_user((int) $bob->id, function () use (&$values) {
            return array_shift($values);
        });

        $this->assertSame($fresh, $sub);
        $this->assertSame($taken, subjects::find_for_user((int) $alice->id));
        $this->assertSame(2, $DB->count_records(subjects::TABLE));
    }

    /**
     * Giving up after repeated clashes is an error, not a shared subject.
     */
    public function test_gives_up_after_repeated_clashes(): void {
        $this->resetAfterTest();
        $alice = $this->getDataGenerator()->create_user();
        $bob = $this->getDataGenerator()->create_user();
        $taken = subjects::for_user((int) $alice->id);

        try {
            subjects::for_user((int) $bob->id, fn() => $taken);
            $this->fail('A subject that is taken must never be shared.');
        } catch (\moodle_exception $e) {
            $this->assertSame('oidc_nosubject', $e->errorcode);
        }
        $this->assertNull(subjects::find_for_user((int) $bob->id));
    }

    /**
     * Only well-formed subjects are stored, and only for real users.
     */
    public function test_bad_input_is_refused(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        foreach ([fn() => (string) $user->id, fn() => 'ABCDEF' . str_repeat('0', 26), fn() => ''] as $generate) {
            try {
                subjects::for_user((int) $user->id, $generate);
                $this->fail('A malformed subject must be refused.');
            } catch (\coding_exception $e) {
                $this->assertNull(subjects::find_for_user((int) $user->id));
            }
        }
        $this->expectException(\coding_exception::class);
        subjects::for_user(0);
    }

    /**
     * Subjects for several users at once: existing ones kept, missing ones made.
     */
    public function test_for_users(): void {
        $this->resetAfterTest();
        $alice = $this->getDataGenerator()->create_user();
        $bob = $this->getDataGenerator()->create_user();
        $existing = subjects::for_user((int) $alice->id);

        $subs = subjects::for_users([(int) $alice->id, (int) $bob->id, (int) $bob->id, 0]);
        $this->assertCount(2, $subs);
        $this->assertSame($existing, $subs[(int) $alice->id]);
        $this->assertSame(subjects::find_for_user((int) $bob->id), $subs[(int) $bob->id]);
        $this->assertSame([], subjects::for_users([]));
    }

    /**
     * Deleting a user deletes their subject, codes and tokens, so an account
     * that later gets the same id (for example after a restore) gets a new
     * subject and can't reach their spoke accounts.
     */
    public function test_deleted_user_loses_subject(): void {
        global $DB;
        $this->resetAfterTest();
        /** @var \local_nucleushub_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nucleushub');
        $registered = $generator->create_oidc_client();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $old = subjects::for_user((int) $user->id);
        $kept = subjects::for_user((int) $other->id);
        $generator->create_code($registered['client'], (int) $user->id, str_repeat('v', 43));
        tokens::issue_access_token($registered['client'], (int) $user->id);

        delete_user($user);

        $this->assertNull(subjects::find_for_user((int) $user->id));
        $this->assertSame(0, $DB->count_records(tokens::CODE_TABLE, ['userid' => $user->id]));
        $this->assertSame(0, $DB->count_records(tokens::TOKEN_TABLE, ['userid' => $user->id]));
        $this->assertSame($kept, subjects::find_for_user((int) $other->id));

        // The same id, handed out again, never gets the old subject back.
        $this->assertNotSame($old, subjects::for_user((int) $user->id));
    }
}
