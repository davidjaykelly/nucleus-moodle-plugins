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

namespace local_nucleusspoke\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for local_nucleusspoke.
 *
 * The spoke records who pulled each course version and who dealt with
 * each update (system context). Deleting a person's data removes their
 * user ID from these records. It sends no one's details to the hub.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data this plugin stores and sends.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_nucleusspoke_instance', [
            'localcourseid' => 'privacy:metadata:instance:localcourseid',
            'pulledbyid' => 'privacy:metadata:instance:pulledbyid',
            'timepulled' => 'privacy:metadata:instance:timepulled',
        ], 'privacy:metadata:instance');
        $collection->add_database_table('local_nucleusspoke_notification', [
            'state' => 'privacy:metadata:notification:state',
            'resolvedbyid' => 'privacy:metadata:notification:resolvedbyid',
            'timeresolved' => 'privacy:metadata:notification:timeresolved',
        ], 'privacy:metadata:notification');
        $collection->add_external_location_link('nucleus', [
            'userid' => 'privacy:metadata:nucleus:userid',
        ], 'privacy:metadata:nucleus');
        return $collection;
    }

    /**
     * Contexts holding data for a user: the system context, if any.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();
        if (
            $DB->record_exists('local_nucleusspoke_instance', ['pulledbyid' => $userid])
                || $DB->record_exists('local_nucleusspoke_notification', ['resolvedbyid' => $userid])
        ) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        $userlist->add_from_sql(
            'pulledbyid',
            'SELECT pulledbyid FROM {local_nucleusspoke_instance} WHERE pulledbyid > 0',
            []
        );
        $userlist->add_from_sql(
            'resolvedbyid',
            'SELECT resolvedbyid FROM {local_nucleusspoke_notification} WHERE resolvedbyid > 0',
            []
        );
    }

    /**
     * Export the courses a user pulled and the updates they dealt with.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (!self::includes_system($contextlist)) {
            return;
        }
        $userid = (int) $contextlist->get_user()->id;
        $pulls = array_values(array_map(fn($i) => [
            'courseid' => (int) $i->localcourseid,
            'timepulled' => transform::datetime($i->timepulled),
        ], $DB->get_records('local_nucleusspoke_instance', ['pulledbyid' => $userid], 'timepulled ASC')));
        $updates = array_values(array_map(fn($n) => [
            'state' => $n->state,
            'timeresolved' => $n->timeresolved ? transform::datetime($n->timeresolved) : '',
        ], $DB->get_records('local_nucleusspoke_notification', ['resolvedbyid' => $userid], 'timereceived ASC')));
        if ($pulls || $updates) {
            writer::with_context(\context_system::instance())->export_data(
                [get_string('pluginname', 'local_nucleusspoke')],
                (object) ['coursespulled' => $pulls, 'updatesresolved' => $updates]
            );
        }
    }

    /**
     * Remove every user's ID from these records.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if (!$context instanceof \context_system) {
            return;
        }
        $DB->set_field_select('local_nucleusspoke_instance', 'pulledbyid', 0, 'pulledbyid > 0');
        $DB->set_field_select('local_nucleusspoke_notification', 'resolvedbyid', null, 'resolvedbyid > 0');
    }

    /**
     * Remove one user's ID from these records.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        if (self::includes_system($contextlist)) {
            self::anonymise([(int) $contextlist->get_user()->id]);
        }
    }

    /**
     * Remove several users' IDs from these records.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        if ($userlist->get_context() instanceof \context_system) {
            self::anonymise($userlist->get_userids());
        }
    }

    /**
     * Is the system context among the approved contexts?
     *
     * @param approved_contextlist $contextlist
     * @return bool
     */
    private static function includes_system(approved_contextlist $contextlist): bool {
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove these users' IDs.
     *
     * @param int[] $userids
     */
    private static function anonymise(array $userids): void {
        global $DB;
        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->set_field_select('local_nucleusspoke_instance', 'pulledbyid', 0, "pulledbyid {$insql}", $params);
        $DB->set_field_select('local_nucleusspoke_notification', 'resolvedbyid', null, "resolvedbyid {$insql}", $params);
    }
}
