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
 * Install steps for local_nucleushub.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Pin the sign-in issuer to the address the hub is installed at.
 *
 * Spokes key their account links to the issuer, so it must stay the same
 * if the hub later moves to another address (custom domains). Nucleus
 * installs every hub at its original address, which is also what the
 * control plane records as the hub's issuer.
 *
 * @return bool
 */
function xmldb_local_nucleushub_install(): bool {
    \local_nucleushub\local\oidc\provider::pin_issuer();
    return true;
}
