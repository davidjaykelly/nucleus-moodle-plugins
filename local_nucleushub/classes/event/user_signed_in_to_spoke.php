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

namespace local_nucleushub\event;

/**
 * Someone signed in to a spoke through the hub (ADR-023 section 3).
 *
 * Fired when the token endpoint issues tokens to a spoke for a user.
 * `userid` is the person who signed in, `objectid` the spoke's OpenID
 * Connect client row.
 *
 * @property-read array $other {
 *      - string clientid: the spoke's client id.
 *      - string spoke: the spoke's address.
 * }
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_signed_in_to_spoke extends \core\event\base {
    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_nucleushub_oidc_client';
    }

    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_user_signed_in_to_spoke', 'local_nucleushub');
    }

    /**
     * Event description.
     *
     * @return string
     */
    public function get_description() {
        $spoke = s((string) ($this->other['spoke'] ?? ''));
        return "The user with id '{$this->userid}' signed in to the spoke '{$spoke}' through the hub.";
    }

    /**
     * Check the data.
     *
     * @return void
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['clientid'])) {
            throw new \coding_exception('The \'clientid\' value must be set in other.');
        }
        if (!isset($this->other['spoke'])) {
            throw new \coding_exception('The \'spoke\' value must be set in other.');
        }
    }

    /**
     * The client row isn't backed up, so there is nothing to map.
     *
     * @return int
     */
    public static function get_objectid_mapping() {
        return \core\event\base::NOT_MAPPED;
    }

    /**
     * Nothing in `other` needs mapping.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
