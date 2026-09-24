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
 * Admin settings for local_nucleushub.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_nucleuscommon\local\site;

site::add_admin_category($ADMIN);

// Hub pages only on sites that are hubs. Registered outside the
// $hassiteconfig check so managers with the publish capability (but
// not site config) can still open them.
if (site::is_hub()) {
    $ADMIN->add(site::ADMIN_CATEGORY, new admin_externalpage(
        'local_nucleushub_families',
        new lang_string('families_title', 'local_nucleushub'),
        new moodle_url('/local/nucleushub/families.php'),
        'local/nucleushub:publish'
    ));
    $ADMIN->add(site::ADMIN_CATEGORY, new admin_externalpage(
        'local_nucleushub_spokes',
        new lang_string('spokes_title', 'local_nucleushub'),
        new moodle_url('/local/nucleushub/spokes.php'),
        'local/nucleushub:publish'
    ));
}
