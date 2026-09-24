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

namespace local_nucleuscommon\output;

use plugin_renderer_base;

/**
 * Renderer for local_nucleuscommon.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends plugin_renderer_base {
    /**
     * The whole Nucleus bar.
     *
     * @param statusbar $bar
     * @return string HTML
     */
    protected function render_statusbar(statusbar $bar): string {
        return $this->render_from_template('local_nucleuscommon/statusbar', $bar->export_for_template($this));
    }

    /**
     * The parts of the bar that change while a page is open, for the
     * polling endpoint.
     *
     * @param statusbar $bar
     * @return array ['hash' => string, 'segments' => string, 'actions' => string, 'panel' => string]
     */
    public function statusbar_parts(statusbar $bar): array {
        $context = $bar->export_for_template($this);
        return [
            'hash' => $context['hash'],
            'segments' => $this->render_from_template('local_nucleuscommon/statusbar_segments', $context),
            'actions' => $this->render_from_template('local_nucleuscommon/statusbar_actions', $context),
            'panel' => $this->render_from_template('local_nucleuscommon/statusbar_panel', $context),
        ];
    }
}
