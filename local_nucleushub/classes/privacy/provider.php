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
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for local_nucleushub.
 *
 * The hub records who edited shared courses since their last published
 * version. That is site-level data (system context). Deleting a
 * person's data removes their user ID from those records.
 *
 * Each spoke's hub service account (ADR-023) belongs to a site, not a
 * person, so it holds no personal data.
 *
 * Sign in with the hub (ADR-023 section 3) keeps short-lived sign-in
 * codes (60 seconds) and access tokens (5 minutes) for a person, and a
 * random subject identifier, and sends that identifier, their name,
 * email and language to the spoke they sign in to. Spoke clients
 * themselves hold no personal data. Deleting a person's data deletes
 * their subject too, so spokes can no longer match them to the
 * accounts they had there.
 *
 * @package    local_nucleushub
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
        $collection->add_database_table('local_nucleushub_changelog', [
            'actoruserid' => 'privacy:metadata:changelog:actoruserid',
            'eventkind' => 'privacy:metadata:changelog:eventkind',
            'timecreated' => 'privacy:metadata:changelog:timecreated',
        ], 'privacy:metadata:changelog');

        $collection->add_database_table('local_nucleushub_oidc_code', [
            'userid' => 'privacy:metadata:oidc_code:userid',
            'clientid' => 'privacy:metadata:oidc_code:clientid',
            'nonce' => 'privacy:metadata:oidc_code:nonce',
            'authtime' => 'privacy:metadata:oidc_code:authtime',
            'expires' => 'privacy:metadata:oidc_code:expires',
        ], 'privacy:metadata:oidc_code');

        $collection->add_database_table('local_nucleushub_oidc_token', [
            'userid' => 'privacy:metadata:oidc_token:userid',
            'clientid' => 'privacy:metadata:oidc_token:clientid',
            'expires' => 'privacy:metadata:oidc_token:expires',
        ], 'privacy:metadata:oidc_token');

        $collection->add_database_table('local_nucleushub_oidc_subject', [
            'userid' => 'privacy:metadata:oidc_subject:userid',
            'sub' => 'privacy:metadata:oidc_subject:sub',
            'timecreated' => 'privacy:metadata:oidc_subject:timecreated',
        ], 'privacy:metadata:oidc_subject');

        $collection->add_external_location_link('spokes', [
            'sub' => 'privacy:metadata:spokes:sub',
            'email' => 'privacy:metadata:spokes:email',
            'given_name' => 'privacy:metadata:spokes:given_name',
            'family_name' => 'privacy:metadata:spokes:family_name',
            'name' => 'privacy:metadata:spokes:name',
            'locale' => 'privacy:metadata:spokes:locale',
        ], 'privacy:metadata:spokes');

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
            $DB->record_exists('local_nucleushub_changelog', ['actoruserid' => $userid])
            || $DB->record_exists('local_nucleushub_oidc_code', ['userid' => $userid])
            || $DB->record_exists('local_nucleushub_oidc_token', ['userid' => $userid])
            || $DB->record_exists('local_nucleushub_oidc_subject', ['userid' => $userid])
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
            'actoruserid',
            'SELECT actoruserid FROM {local_nucleushub_changelog} WHERE actoruserid > 0',
            []
        );
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_nucleushub_oidc_code}', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_nucleushub_oidc_token}', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_nucleushub_oidc_subject}', []);
    }

    /**
     * Export the course edits a user made, and their current sign-ins to spokes.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (!self::includes_system($contextlist)) {
            return;
        }
        $userid = (int) $contextlist->get_user()->id;
        $edits = array_values(array_map(fn($c) => [
            'kind' => $c->eventkind,
            'timecreated' => transform::datetime($c->timecreated),
        ], $DB->get_records('local_nucleushub_changelog', ['actoruserid' => $userid], 'timecreated ASC')));
        if ($edits) {
            writer::with_context(\context_system::instance())->export_data(
                [get_string('pluginname', 'local_nucleushub')],
                (object) ['courseedits' => $edits]
            );
        }

        // Codes and tokens themselves are only stored as hashes, so only
        // which spoke client they were for and when they expire is shown.
        $signins = [];
        foreach (['local_nucleushub_oidc_code', 'local_nucleushub_oidc_token'] as $table) {
            foreach ($DB->get_records($table, ['userid' => $userid], 'expires ASC', 'id, clientid, expires') as $row) {
                $signins[] = [
                    'clientid' => $row->clientid,
                    'expires' => transform::datetime($row->expires),
                ];
            }
        }
        $subject = $DB->get_record('local_nucleushub_oidc_subject', ['userid' => $userid]);
        if ($signins || $subject) {
            $data = ['signins' => $signins];
            if ($subject) {
                $data['subject'] = $subject->sub;
                $data['subjectcreated'] = transform::datetime($subject->timecreated);
            }
            writer::with_context(\context_system::instance())->export_data(
                [get_string('pluginname', 'local_nucleushub'), get_string('privacy:signins', 'local_nucleushub')],
                (object) $data
            );
        }
    }

    /**
     * Remove every user's ID from the course edit log, and every sign-in
     * code, token and subject.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if (!$context instanceof \context_system) {
            return;
        }
        $DB->set_field_select('local_nucleushub_changelog', 'actoruserid', 0, 'actoruserid > 0');
        $DB->delete_records('local_nucleushub_oidc_code');
        $DB->delete_records('local_nucleushub_oidc_token');
        $DB->delete_records('local_nucleushub_oidc_subject');
    }

    /**
     * Remove one user's ID from the course edit log, and their sign-in codes, tokens and subject.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        if (self::includes_system($contextlist)) {
            self::anonymise([(int) $contextlist->get_user()->id]);
        }
    }

    /**
     * Remove several users' IDs from the course edit log, and their sign-in codes, tokens and subject.
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
     * Replace these users' IDs with 0 in the course edit log, and delete
     * their sign-in codes, tokens and subject.
     *
     * @param int[] $userids
     */
    private static function anonymise(array $userids): void {
        global $DB;
        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->set_field_select('local_nucleushub_changelog', 'actoruserid', 0, "actoruserid {$insql}", $params);
        $DB->delete_records_select('local_nucleushub_oidc_code', "userid {$insql}", $params);
        $DB->delete_records_select('local_nucleushub_oidc_token', "userid {$insql}", $params);
        $DB->delete_records_select('local_nucleushub_oidc_subject', "userid {$insql}", $params);
    }
}
