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
 * Version metadata for auth_nucleus.
 *
 * Sign in with the federation's hub (ADR-023): an OpenID Connect client
 * for the hub's provider, configured by Nucleus through the spoke's
 * control-plane web services.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@dklabs.co.uk>
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'auth_nucleus';
$plugin->version   = 2026092800;
$plugin->release   = '0.1.1';
$plugin->maturity  = MATURITY_ALPHA;
$plugin->requires  = 2025100600;
$plugin->dependencies = [
    'local_nucleusspoke' => 2026092800,
    'local_nucleuscommon' => 2026092800,
];
