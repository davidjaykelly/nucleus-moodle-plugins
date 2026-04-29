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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * Instance lifecycle actions — close-to-enrolment and reopen
 * (ADR-014 Phase 2).
 *
 * Close is a "soft" transition: existing enrolments keep working
 * (learners can still access content they're already enrolled in),
 * but every enrol plugin on the course is disabled so nobody new
 * can self-enrol, be manually enrolled, or come in via cohort sync.
 * Reopen flips the plugins back on.
 *
 * Archive is a harder transition and gated on "no in-progress
 * completions" — left for a follow-up because it involves tearing
 * down content, not just enrolment access.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

namespace local_nucleusspoke\version;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/enrollib.php');

class lifecycle {

    /**
     * Close an active instance to new enrolments. Every enrol
     * instance on the course is disabled; existing enrolments are
     * untouched (users keep access). Idempotent if already closed.
     *
     * @param int $instanceid local_nucleusspoke_instance.id
     * @return \stdClass Updated row.
     * @throws \moodle_exception
     */
    public static function close_to_enrolment(int $instanceid): \stdClass {
        global $DB;

        $row = $DB->get_record(
            'local_nucleusspoke_instance',
            ['id' => $instanceid],
            '*',
            MUST_EXIST
        );
        if ($row->state === 'closed-to-enrolment') {
            return $row;
        }
        if ($row->state === 'archived') {
            throw new \moodle_exception(
                'instancearchived', 'local_nucleusspoke', '',
                $row->state
            );
        }
        self::toggle_enrol_instances((int) $row->localcourseid, false);

        $DB->update_record('local_nucleusspoke_instance', (object) [
            'id' => $row->id,
            'state' => 'closed-to-enrolment',
            'timemodified' => time(),
        ]);
        return $DB->get_record(
            'local_nucleusspoke_instance',
            ['id' => $instanceid],
            '*',
            MUST_EXIST
        );
    }

    /**
     * Reopen a closed instance — flip enrol plugins back on and
     * set state=active.
     *
     * @param int $instanceid
     * @return \stdClass Updated row.
     * @throws \moodle_exception
     */
    public static function reopen(int $instanceid): \stdClass {
        global $DB;

        $row = $DB->get_record(
            'local_nucleusspoke_instance',
            ['id' => $instanceid],
            '*',
            MUST_EXIST
        );
        if ($row->state !== 'closed-to-enrolment') {
            throw new \moodle_exception(
                'instancenotclosed', 'local_nucleusspoke', '',
                $row->state
            );
        }
        self::toggle_enrol_instances((int) $row->localcourseid, true);

        $DB->update_record('local_nucleusspoke_instance', (object) [
            'id' => $row->id,
            'state' => 'active',
            'timemodified' => time(),
        ]);
        return $DB->get_record(
            'local_nucleusspoke_instance',
            ['id' => $instanceid],
            '*',
            MUST_EXIST
        );
    }

    /**
     * Flip every enrol instance on a course between enabled (open)
     * and disabled (closed). Existing role assignments / enrolments
     * are untouched — this only affects new-enrolment surfaces.
     *
     * @param int $courseid
     * @param bool $enable
     */
    private static function toggle_enrol_instances(int $courseid, bool $enable): void {
        global $DB;

        $targetstatus = $enable ? ENROL_INSTANCE_ENABLED : ENROL_INSTANCE_DISABLED;
        $instances = $DB->get_records('enrol', ['courseid' => $courseid]);
        foreach ($instances as $inst) {
            if ((int) $inst->status === $targetstatus) {
                continue;
            }
            $plugin = enrol_get_plugin($inst->enrol);
            if ($plugin) {
                $plugin->update_status($inst, $targetstatus);
            } else {
                // Unusual but survivable: toggle directly.
                $DB->set_field('enrol', 'status', $targetstatus, ['id' => $inst->id]);
            }
        }
    }
}
