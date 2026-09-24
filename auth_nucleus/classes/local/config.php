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

/**
 * The plugin's settings, and turning hub sign-in on and off.
 *
 * Nucleus writes these through local_nucleusspoke_configure_signin. The
 * client secret is stored encrypted with \core\encryption and is only
 * ever decrypted to send it to the hub's token endpoint.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config {
    /** @var string Component name. */
    public const COMPONENT = 'auth_nucleus';

    /** @var string The auth plugin name, as in $CFG->auth and user.auth. */
    public const AUTH = 'nucleus';

    /** @var string[] Profile fields the hub owns: locked here, refreshed at each sign-in. */
    public const LOCKED_FIELDS = ['email', 'firstname', 'lastname'];

    /**
     * One raw setting, as a string.
     *
     * @param string $name
     * @return string
     */
    private static function get(string $name): string {
        $value = get_config(self::COMPONENT, $name);
        return $value === false || $value === null ? '' : (string) $value;
    }

    /**
     * The hub's issuer URL (browser-facing), without a trailing slash.
     *
     * @return string
     */
    public static function issuer(): string {
        return rtrim(self::get('issuer'), '/');
    }

    /**
     * This spoke's client id on the hub.
     *
     * @return string
     */
    public static function clientid(): string {
        return self::get('clientid');
    }

    /**
     * The client secret, decrypted. Only for the token request.
     *
     * @return string The secret, or '' if it isn't set or can't be decrypted.
     */
    public static function clientsecret(): string {
        $stored = self::get('clientsecret');
        if ($stored === '') {
            return '';
        }
        try {
            return \core\encryption::decrypt($stored);
        } catch (\Throwable $e) {
            // Wrong or missing site key. Never log the value.
            return '';
        }
    }

    /**
     * Is a client secret stored? (Without decrypting it.)
     *
     * @return bool
     */
    public static function has_clientsecret(): bool {
        return self::get('clientsecret') !== '';
    }

    /**
     * The hub's name, for "Sign in with {hub name}".
     *
     * @return string
     */
    public static function hubname(): string {
        $name = trim(self::get('hubname'));
        return $name !== '' ? $name : get_string('defaulthubname', self::COMPONENT);
    }

    /**
     * Send people from the login page straight to the hub?
     *
     * @return bool
     */
    public static function autoredirect(): bool {
        return !empty(get_config(self::COMPONENT, 'autoredirect'));
    }

    /**
     * Sign people out of the hub when they sign out here?
     *
     * @return bool
     */
    public static function singlesignout(): bool {
        return !empty(get_config(self::COMPONENT, 'singlesignout'));
    }

    /**
     * Is there enough configuration to sign in with the hub?
     *
     * @return bool
     */
    public static function is_configured(): bool {
        return self::issuer() !== '' && self::clientid() !== '' && self::has_clientsecret();
    }

    /**
     * Is the plugin on ('nucleus' in $CFG->auth)?
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return is_enabled_auth(self::AUTH);
    }

    /**
     * This spoke's callback URL, registered with the hub.
     *
     * @return string
     */
    public static function redirect_uri(): string {
        return (new \moodle_url('/auth/nucleus/callback.php'))->out(false);
    }

    /**
     * Store the configuration Nucleus sends.
     *
     * @param string $issuer Hub issuer URL.
     * @param string $clientid Client id.
     * @param string $clientsecret Client secret, in plain text. Stored encrypted.
     * @param string $hubname Name for the sign-in button.
     * @param bool $autoredirect Send the login page straight to the hub.
     * @param bool $singlesignout Sign out of the hub too.
     */
    public static function save(string $issuer, string $clientid, string $clientsecret, string $hubname,
            bool $autoredirect, bool $singlesignout): void {
        set_config('issuer', rtrim($issuer, '/'), self::COMPONENT);
        set_config('clientid', $clientid, self::COMPONENT);
        set_config('clientsecret', \core\encryption::encrypt($clientsecret), self::COMPONENT);
        set_config('hubname', $hubname, self::COMPONENT);
        set_config('autoredirect', $autoredirect ? 1 : 0, self::COMPONENT);
        set_config('singlesignout', $singlesignout ? 1 : 0, self::COMPONENT);
        self::lock_profile_fields();
        // The keys may have changed with the issuer; fetch them afresh.
        \cache::make(self::COMPONENT, 'jwks')->purge();
    }

    /**
     * Forget the client secret. The other settings stay, for the status.
     */
    public static function clear_secret(): void {
        set_config('clientsecret', '', self::COMPONENT);
    }

    /**
     * Lock the profile fields the hub owns, so people can't edit them here.
     */
    public static function lock_profile_fields(): void {
        foreach (self::LOCKED_FIELDS as $field) {
            set_config('field_lock_' . $field, 'locked', self::COMPONENT);
            set_config('field_updatelocal_' . $field, 'onlogin', self::COMPONENT);
        }
    }

    /**
     * Turn the plugin on or off the way Site administration > Manage
     * authentication does (admin/auth.php). Manual accounts are always on.
     *
     * @param bool $enabled
     * @return bool Whether $CFG->auth changed.
     */
    public static function set_enabled(bool $enabled): bool {
        $class = \core_plugin_manager::resolve_plugininfo_class('auth');
        return $class::enable_plugin(self::AUTH, $enabled ? 1 : 0);
    }
}
