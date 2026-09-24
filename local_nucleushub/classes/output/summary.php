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

namespace local_nucleushub\output;

use renderable;
use renderer_base;
use templatable;

/**
 * A short list of facts shown above the promote and publish forms.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class summary implements renderable, templatable {
    /** @var array[] Rows: ['label' => string, 'value' => string, 'lines' => string[]]. */
    private array $rows = [];

    /** @var string Optional note under the list. */
    private string $note = '';

    /**
     * Add a fact.
     *
     * @param string $label Plain text.
     * @param string $value Plain text.
     * @param string[] $lines Extra plain-text lines under the value.
     * @return self
     */
    public function add(string $label, string $value, array $lines = []): self {
        $this->rows[] = ['label' => $label, 'value' => $value, 'lines' => $lines];
        return $this;
    }

    /**
     * Set a note shown under the facts.
     *
     * @param string $note Plain text.
     * @return self
     */
    public function set_note(string $note): self {
        $this->note = $note;
        return $this;
    }

    /**
     * Template context.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        return [
            'rows' => array_map(fn(array $row) => [
                'label' => $row['label'],
                'value' => $row['value'],
                'lines' => array_map(fn($line) => ['text' => $line], $row['lines']),
            ], $this->rows),
            'note' => $this->note,
        ];
    }
}
