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
 * Applies completion.v1 envelopes to local state.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleusspoke\handler;

defined('MOODLE_INTERNAL') || die();

/**
 * Translate a completion.v1 envelope ({hub_user_id, spoke_user_id,
 * course_id = hub course id, completed_at}) into a local
 * `course_completions` row for the matching spoke user and spoke course.
 *
 * Lookup chain:
 *   envelope.course_id (hub)  → local_nucleusspoke_courses.hubcourseid → localcourseid
 *   envelope.spoke_user_id    → spoke's mdl_user.id (already local)
 *
 * Idempotency: `completion_completion::mark_complete()` silently no-ops
 * once a row exists (guarded by `if (!$this->id)`). Safe to replay.
 */
class completion_applier {

    /**
     * Apply one envelope. Returns a short status string for logging.
     *
     * @param array $envelope Full envelope from the publisher.
     * @return string One of: applied | skipped_mode | unknown_course | unknown_user | enrol_missing
     */
    public static function apply(array $envelope): string {
        global $CFG, $DB;

        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/completion/completion_completion.php');

        $payload = $envelope['payload'] ?? [];
        $hubcourseid = (int)($payload['course_id'] ?? 0);
        $spokeuserid = (int)($payload['spoke_user_id'] ?? 0);
        $completedat = (int)($payload['completed_at'] ?? time());

        if ($hubcourseid <= 0 || $spokeuserid <= 0) {
            return 'bad_envelope';
        }

        $fed = $DB->get_record('local_nucleusspoke_courses', [
            'hubcourseid' => $hubcourseid,
            'mode'        => 'identity',
        ]);
        if (!$fed) {
            return 'unknown_course';
        }

        if (!$DB->record_exists('user', ['id' => $spokeuserid, 'deleted' => 0])) {
            return 'unknown_user';
        }

        // Completion tracking is a prerequisite. The enable_federation
        // action sets course->enablecompletion on the placeholder at
        // creation time, but an older row (from before that wiring) or
        // a hand-tweaked one might not have it — keep the consumer robust.
        $course = $DB->get_record('course', ['id' => $fed->localcourseid], '*', MUST_EXIST);
        if (empty($course->enablecompletion)) {
            $DB->set_field('course', 'enablecompletion', 1, ['id' => $course->id]);
            $course->enablecompletion = 1;
            // Bust the cache so the next completion_info() sees the flag.
            \cache_helper::purge_by_event('changesincourse');
        }

        $completion = new \completion_completion([
            'course' => (int)$fed->localcourseid,
            'userid' => $spokeuserid,
        ]);
        $completion->mark_complete($completedat);

        return 'applied';
    }
}
