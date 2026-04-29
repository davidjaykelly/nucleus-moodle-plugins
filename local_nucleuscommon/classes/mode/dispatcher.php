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
 * Mode dispatcher — resolves the active federation mode from plugin config.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleuscommon\mode;

defined('MOODLE_INTERNAL') || die();

/**
 * One shared entry point so neither hub nor spoke code needs to know how
 * the mode is configured — they just ask the dispatcher for the current
 * strategy.
 *
 * Phase 0 resolution is from the `federationmode` plugin setting; Phase 1
 * resolves from the control plane's federation registry via a cached lookup.
 */
class dispatcher {

    /**
     * Default mode when nothing is configured. Chosen as 'content' because
     * it's the non-invasive option (no user projection side-effects).
     */
    const DEFAULT_MODE = 'content';

    /**
     * Return the strategy implementation for the configured mode.
     *
     * @return strategy
     */
    public static function current(): strategy {
        $mode = get_config('local_nucleuscommon', 'federationmode');
        if (!is_string($mode) || $mode === '') {
            $mode = self::DEFAULT_MODE;
        }
        // 'both' = identity is the superset for protocol routing
        // decisions (project-on-enrol, apply completions). Mode A
        // courses still work because the per-course `mode` column on
        // local_nucleusspoke_courses gates the actual behaviour
        // — the federation-level mode is just discoverability.
        return match ($mode) {
            'identity', 'both' => new identity_strategy(),
            default            => new content_strategy(),
        };
    }

    /**
     * Return the current mode name without instantiating a strategy.
     * Useful for cheap checks in observers and external functions.
     *
     * @return string Either 'content' or 'identity'.
     */
    public static function current_mode(): string {
        return self::current()->name();
    }
}
