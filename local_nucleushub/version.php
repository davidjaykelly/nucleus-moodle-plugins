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
 * Version metadata for local_nucleushub.
 *
 * Hub-side Nucleus federation plugin: publishes course versions (ADR-014),
 * gives each spoke its own hub account and token (ADR-023), exposes the
 * read-only catalogue functions spokes call, and signs people in to its
 * spokes as an OpenID Connect provider (ADR-023 section 3), optionally
 * through the organisation's own provider (ADR-023 section 5).
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@dklabs.co.uk>
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_nucleushub';
$plugin->version   = 2026092601;
$plugin->release   = '0.9.0';
$plugin->maturity  = MATURITY_ALPHA;
$plugin->requires  = 2025100600;
$plugin->dependencies = [
    'local_nucleuscommon' => 2026092404,
];
