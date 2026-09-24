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

/**
 * Heuristic severity recommendation for a draft's pending changes
 * (ADR-014 Phase 2).
 *
 * Rule of thumb:
 *   - Any deletion (module / section) → major. Removed content
 *     breaks learner expectations and quiz attempts.
 *   - Any module added or updated → minor. Structural additions
 *     or activity tweaks are forward-compatible.
 *   - Only section / course metadata edits → patch.
 *
 * Operators can override; the hint is advisory. The changelog is
 * cleared on publish so the hint naturally resets to "none".
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@dklabs.co.uk>
 */

namespace local_nucleushub\version;

defined('MOODLE_INTERNAL') || die();

/**
 * Suggest a type of change (patch, minor, major) from a course
 * family's unpublished changes.
 */
class severity_hint {
    /**
     * Suggest a type of change for a family's unpublished changes.
     *
     * @param int $familyid
     * @return array{suggested: string|null, counts: array<string,int>, rationale: string|null}
     */
    public static function for_family(int $familyid): array {
        global $DB;

        // One count per kind of change. (Fetching the eventkind column
        // on its own would key the rows by kind and count each kind once.)
        $counts = array_map('intval', $DB->get_records_sql_menu(
            "SELECT eventkind, COUNT(1)
               FROM {local_nucleushub_changelog}
              WHERE familyid = :familyid
           GROUP BY eventkind",
            ['familyid' => $familyid]
        ));
        if (!$counts) {
            return ['suggested' => null, 'counts' => [], 'rationale' => null];
        }

        $deletes = ($counts['module_deleted'] ?? 0)
            + ($counts['section_deleted'] ?? 0);
        $adds = ($counts['module_added'] ?? 0)
            + ($counts['section_added'] ?? 0);
        $moduleupdates = ($counts['module_updated'] ?? 0);

        if ($deletes > 0) {
            return [
                'suggested' => 'major',
                'counts' => $counts,
                'rationale' => get_string(
                    'severity_rationale_major',
                    'local_nucleushub',
                    $deletes
                ),
            ];
        }
        if ($adds > 0 || $moduleupdates > 0) {
            return [
                'suggested' => 'minor',
                'counts' => $counts,
                'rationale' => get_string(
                    'severity_rationale_minor',
                    'local_nucleushub',
                    (object) [
                        'added' => $adds,
                        'updated' => $moduleupdates,
                    ]
                ),
            ];
        }
        return [
            'suggested' => 'patch',
            'counts' => $counts,
            'rationale' => get_string(
                'severity_rationale_patch',
                'local_nucleushub'
            ),
        ];
    }
}
