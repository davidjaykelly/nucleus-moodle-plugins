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

namespace auth_nucleus\local;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/authlib.php');

/**
 * A hub sign-in that was refused.
 *
 * The error code picks a plain message for the person (lang string
 * error_{code}). The debug info says why, for the site's logs; it never
 * holds token contents, secrets or the person's details.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signin_exception extends \moodle_exception {
    /** @var string Short reason code, e.g. 'state' or 'siteadmin'. */
    public string $reasoncode;

    /** @var int AUTH_LOGIN_* reason for the failed-login event. */
    public int $loginreason;

    /** @var int|null The local account involved, when there is one. */
    public ?int $userid;

    /**
     * Constructor.
     *
     * @param string $reasoncode Reason code; the message is lang string error_{code}.
     * @param int $loginreason AUTH_LOGIN_* reason for the failed-login event.
     * @param string $debuginfo Why, for the logs. No secrets or token contents.
     * @param mixed $a Value for the message's {$a}.
     * @param int|null $userid The local account involved, if any.
     */
    public function __construct(string $reasoncode, int $loginreason = AUTH_LOGIN_FAILED, string $debuginfo = '',
            $a = null, ?int $userid = null) {
        $this->reasoncode = $reasoncode;
        $this->loginreason = $loginreason;
        $this->userid = $userid;
        parent::__construct('error_' . $reasoncode, 'auth_nucleus', '', $a, $debuginfo);
    }
}
