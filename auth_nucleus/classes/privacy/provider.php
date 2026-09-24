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

namespace auth_nucleus\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for auth_nucleus.
 *
 * Each linked account has a row saying which hub account it belongs to
 * (user context). At sign-in the hub sends the person's name, email and
 * language, which update their account here; at sign-out their browser
 * hands the hub's sign-in token back to the hub.
 *
 * @package    auth_nucleus
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
        $collection->add_database_table('auth_nucleus_link', [
            'userid' => 'privacy:metadata:link:userid',
            'issuer' => 'privacy:metadata:link:issuer',
            'sub' => 'privacy:metadata:link:sub',
            'previousauth' => 'privacy:metadata:link:previousauth',
            'timecreated' => 'privacy:metadata:link:timecreated',
        ], 'privacy:metadata:link');
        $collection->add_external_location_link('hub', [
            'idtoken' => 'privacy:metadata:hub:idtoken',
        ], 'privacy:metadata:hub');
        $collection->link_subsystem('core_auth', 'privacy:metadata:coreauth');
        return $collection;
    }

    /**
     * The user context, when the person's account is linked.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT ctx.id
                  FROM {auth_nucleus_link} l
                  JOIN {context} ctx ON ctx.instanceid = l.userid AND ctx.contextlevel = :contextlevel
                 WHERE l.userid = :userid";
        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, ['contextlevel' => CONTEXT_USER, 'userid' => $userid]);
        return $contextlist;
    }

    /**
     * The person whose user context this is, if their account is linked.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_user) {
            return;
        }
        $userlist->add_from_sql('userid', "SELECT userid FROM {auth_nucleus_link} WHERE userid = :userid",
            ['userid' => $context->instanceid]);
    }

    /**
     * Export the person's link to the hub.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_USER || (int) $context->instanceid !== $userid) {
                continue;
            }
            $link = $DB->get_record('auth_nucleus_link', ['userid' => $userid]);
            if (!$link) {
                continue;
            }
            writer::with_context($context)->export_data([get_string('privacy:metadata:link', 'auth_nucleus')], (object) [
                'issuer' => $link->issuer,
                'sub' => $link->sub,
                'previousauth' => (string) ($link->previousauth ?? ''),
                'timecreated' => transform::datetime($link->timecreated),
            ]);
        }
    }

    /**
     * Delete the link for the user whose context this is.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if ($context->contextlevel == CONTEXT_USER) {
            self::delete_link((int) $context->instanceid);
        }
    }

    /**
     * Delete the person's link.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_USER && (int) $context->instanceid === $userid) {
                self::delete_link($userid);
            }
        }
    }

    /**
     * Delete the links of the users in a user context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if ($context instanceof \context_user && in_array((int) $context->instanceid, $userlist->get_userids())) {
            self::delete_link((int) $context->instanceid);
        }
    }

    /**
     * Delete one account's link.
     *
     * @param int $userid
     */
    private static function delete_link(int $userid): void {
        global $DB;
        $DB->delete_records('auth_nucleus_link', ['userid' => $userid]);
    }
}
