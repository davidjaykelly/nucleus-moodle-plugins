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

namespace local_nucleuscommon\local;

/**
 * Facts about this site's place in a Nucleus federation.
 *
 * Every Nucleus Moodle has all three plugins installed, so the role
 * (hub or spoke) comes from configuration: a site that points at a
 * hub (local_nucleusspoke/hubwwwroot set) is a spoke; any other site
 * is treated as a hub.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class site {
    /** @var string Admin tree category that holds every Nucleus page. */
    public const ADMIN_CATEGORY = 'local_nucleus';

    /**
     * Is this a site DK Labs hosts?
     *
     * The hosted image forces local_nucleuscommon/hosted in config.php
     * ($CFG->forced_plugin_settings). Self-hosted and customer-run
     * sites never set it.
     *
     * @return bool
     */
    public static function is_hosted(): bool {
        return !empty(get_config('local_nucleuscommon', 'hosted'));
    }

    /**
     * Is this site a spoke (it points at a hub)?
     *
     * @return bool
     */
    public static function is_spoke(): bool {
        return (string) get_config('local_nucleusspoke', 'hubwwwroot') !== '';
    }

    /**
     * Is this site a hub (it doesn't point at another hub)?
     *
     * @return bool
     */
    public static function is_hub(): bool {
        return !self::is_spoke();
    }

    /**
     * Can the current user publish course versions on this hub?
     *
     * @return bool
     */
    public static function can_publish(): bool {
        return self::is_hub()
            && has_capability('local/nucleushub:publish', \context_system::instance());
    }

    /**
     * Can the current user pull course versions onto this spoke?
     *
     * @return bool
     */
    public static function can_pull(): bool {
        return self::is_spoke()
            && has_capability('local/nucleusspoke:pull', \context_system::instance());
    }

    /**
     * Does the current user run the Nucleus side of this site?
     *
     * @return bool
     */
    public static function is_operator(): bool {
        return self::can_publish() || self::can_pull();
    }

    /**
     * The optional "If this keeps happening, email ..." line.
     *
     * Empty when no support contact is set (self-hosted sites by
     * default), so callers can append it unconditionally.
     *
     * @return string Plain text, or '' when there's no contact.
     */
    public static function support_line(): string {
        $contact = trim((string) get_config('local_nucleuscommon', 'supportcontact'));
        if ($contact === '') {
            return '';
        }
        return get_string('supportline', 'local_nucleuscommon', $contact);
    }

    /**
     * Add the "Nucleus" category to the admin tree once.
     *
     * Each plugin's settings.php calls this, because local plugins'
     * settings load in display-name order and any of them may come
     * first.
     *
     * @param \part_of_admin_tree $admin The admin tree root ($ADMIN).
     */
    public static function add_admin_category(\part_of_admin_tree $admin): void {
        if ($admin->locate(self::ADMIN_CATEGORY)) {
            return;
        }
        $admin->add('localplugins', new \admin_category(
            self::ADMIN_CATEGORY,
            new \lang_string('admincategory', 'local_nucleuscommon')
        ));
    }
}
