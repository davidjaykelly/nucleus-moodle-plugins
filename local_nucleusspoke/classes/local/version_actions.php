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

namespace local_nucleusspoke\local;

use local_nucleusspoke\version\puller;

/**
 * The actions on the Course versions page, and what they act on.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class version_actions {
    /** @var string[] Actions on an update (the id is a notification id). */
    public const NOTIFICATION_ACTIONS = ['pull', 'pullhidden', 'snooze', 'dismiss', 'reactivate'];

    /** @var string[] Actions on a pulled course (the id is an instance id). */
    public const INSTANCE_ACTIONS = ['makevisible', 'close', 'reopen', 'rollback'];

    /** @var string[] Actions that ask before they act. */
    public const CONFIRM_ACTIONS = ['pull', 'pullhidden', 'rollback', 'close', 'dismiss'];

    /**
     * Pull a version held in the local registry as a new course.
     *
     * @param \stdClass $family local_nucleuscommon_family row.
     * @param \stdClass $version local_nucleuscommon_version row.
     * @param bool $hidden Pull it hidden from learners.
     * @param int $userid Who is pulling.
     * @return array puller::pull() result.
     */
    public static function pull(\stdClass $family, \stdClass $version, bool $hidden, int $userid): array {
        return puller::pull(
            ['guid' => $family->guid, 'slug' => $family->slug, 'hubfederationid' => $family->hubfederationid],
            [
                'guid' => $version->guid,
                'versionnumber' => $version->versionnumber,
                'severity' => $version->severity,
                'snapshotref' => $version->snapshotref,
                'snapshothash' => $version->snapshothash,
                'hubcourseid' => (int) $version->hubcourseid,
                'timepublished' => (int) $version->timepublished,
                'releasenotes' => $version->releasenotes,
                'lockedforspokeedit' => (int) ($version->lockedforspokeedit ?? 0) === 1,
            ],
            1,
            $userid,
            $hidden
        );
    }

    /**
     * The latest earlier version, not deprecated, that a pulled course
     * would roll back to.
     *
     * @param int $familyid
     * @param int $before timepublished of the current version.
     * @return \stdClass|null Version row.
     */
    public static function rollback_target(int $familyid, int $before): ?\stdClass {
        global $DB;
        $rows = $DB->get_records_select(
            'local_nucleuscommon_version',
            'familyid = :familyid AND deprecated = 0 AND timepublished < :before',
            ['familyid' => $familyid, 'before' => $before],
            'timepublished DESC',
            '*',
            0,
            1
        );
        return $rows ? reset($rows) : null;
    }
}
