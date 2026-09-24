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

namespace local_nucleushub\task;

use local_nucleushub\local\oidc\tokens;

/**
 * Tests for the expired code and token clean-up.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(cleanup_oidc::class)]
final class cleanup_oidc_test extends \advanced_testcase {
    /**
     * Expired rows go, live ones stay.
     */
    public function test_deletes_only_expired(): void {
        global $DB;
        $this->resetAfterTest();
        /** @var \local_nucleushub_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_nucleushub');
        $registered = $generator->create_oidc_client();
        $user = $this->getDataGenerator()->create_user();
        $generator->create_code($registered['client'], (int) $user->id, str_repeat('v', 43));
        $generator->create_code($registered['client'], (int) $user->id, str_repeat('w', 43));
        tokens::issue_access_token($registered['client'], (int) $user->id);
        tokens::issue_access_token($registered['client'], (int) $user->id);
        $code = $DB->get_records(tokens::CODE_TABLE, null, 'id ASC', 'id', 0, 1);
        $DB->set_field(tokens::CODE_TABLE, 'expires', time() - 1, ['id' => key($code)]);
        $token = $DB->get_records(tokens::TOKEN_TABLE, null, 'id ASC', 'id', 0, 1);
        $DB->set_field(tokens::TOKEN_TABLE, 'expires', time() - 1, ['id' => key($token)]);

        (new cleanup_oidc())->execute();

        $this->assertSame(1, $DB->count_records(tokens::CODE_TABLE));
        $this->assertSame(1, $DB->count_records(tokens::TOKEN_TABLE));
        $this->assertFalse($DB->record_exists(tokens::CODE_TABLE, ['id' => key($code)]));
        $this->assertFalse($DB->record_exists(tokens::TOKEN_TABLE, ['id' => key($token)]));
    }
}
