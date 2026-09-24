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

namespace local_nucleuscommon\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for local_nucleuscommon.
 *
 * Course families and versions record who created or published them.
 * That is site-level data (system context). Deleting a person's data
 * removes their user ID from these records rather than the records,
 * which spokes still depend on.
 *
 * @package    local_nucleuscommon
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
        $collection->add_database_table('local_nucleuscommon_family', [
            'slug' => 'privacy:metadata:family:slug',
            'createdbyid' => 'privacy:metadata:family:createdbyid',
            'timecreated' => 'privacy:metadata:family:timecreated',
        ], 'privacy:metadata:family');
        $collection->add_database_table('local_nucleuscommon_version', [
            'versionnumber' => 'privacy:metadata:version:versionnumber',
            'publishedbyid' => 'privacy:metadata:version:publishedbyid',
            'timepublished' => 'privacy:metadata:version:timepublished',
            'releasenotes' => 'privacy:metadata:version:releasenotes',
        ], 'privacy:metadata:version');
        $collection->add_external_location_link('nucleus', [
            'userid' => 'privacy:metadata:nucleus:userid',
            'coursebackup' => 'privacy:metadata:nucleus:coursebackup',
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
        $contextlist = new contextlist();
        if (self::has_data($userid)) {
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
        $userlist->add_from_sql('createdbyid', 'SELECT createdbyid FROM {local_nucleuscommon_family} WHERE createdbyid > 0', []);
        $userlist->add_from_sql(
            'publishedbyid',
            'SELECT publishedbyid FROM {local_nucleuscommon_version} WHERE publishedbyid > 0',
            []
        );
    }

    /**
     * Export the families a user created and the versions they published.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (!self::includes_system($contextlist)) {
            return;
        }
        $userid = (int) $contextlist->get_user()->id;
        $families = array_values(array_map(fn($f) => [
            'identifier' => $f->slug,
            'timecreated' => transform::datetime($f->timecreated),
        ], $DB->get_records('local_nucleuscommon_family', ['createdbyid' => $userid], 'timecreated ASC')));
        $versions = array_values(array_map(fn($v) => [
            'version' => $v->versionnumber,
            'timepublished' => transform::datetime($v->timepublished),
            'releasenotes' => (string) $v->releasenotes,
        ], $DB->get_records('local_nucleuscommon_version', ['publishedbyid' => $userid], 'timepublished ASC')));
        if ($families || $versions) {
            writer::with_context(\context_system::instance())->export_data(
                [get_string('pluginname', 'local_nucleuscommon')],
                (object) ['familiescreated' => $families, 'versionspublished' => $versions]
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
        $DB->set_field_select('local_nucleuscommon_family', 'createdbyid', 0, 'createdbyid > 0');
        $DB->set_field_select('local_nucleuscommon_version', 'publishedbyid', 0, 'publishedbyid > 0');
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
     * Does this user appear in any record?
     *
     * @param int $userid
     * @return bool
     */
    private static function has_data(int $userid): bool {
        global $DB;
        return $DB->record_exists('local_nucleuscommon_family', ['createdbyid' => $userid])
            || $DB->record_exists('local_nucleuscommon_version', ['publishedbyid' => $userid]);
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
     * Replace these users' IDs with 0.
     *
     * @param int[] $userids
     */
    private static function anonymise(array $userids): void {
        global $DB;
        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->set_field_select('local_nucleuscommon_family', 'createdbyid', 0, "createdbyid {$insql}", $params);
        $DB->set_field_select('local_nucleuscommon_version', 'publishedbyid', 0, "publishedbyid {$insql}", $params);
    }
}
