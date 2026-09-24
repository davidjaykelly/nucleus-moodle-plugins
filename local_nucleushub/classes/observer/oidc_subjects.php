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

namespace local_nucleushub\observer;

use local_nucleushub\local\oidc\subjects;
use local_nucleushub\local\oidc\tokens;

/**
 * Sign in with the hub: forget a deleted user's subject, codes and tokens.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class oidc_subjects {
    /**
     * A user was deleted.
     *
     * @param \core\event\user_deleted $event
     * @return void
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        global $DB;

        $userid = (int) $event->objectid;
        if ($userid <= 0) {
            return;
        }
        subjects::delete_for_user($userid);
        $DB->delete_records(tokens::CODE_TABLE, ['userid' => $userid]);
        $DB->delete_records(tokens::TOKEN_TABLE, ['userid' => $userid]);
    }
}
