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
 * Silent sign-in: someone already signed in to the hub is signed in here
 * as soon as they open a page, without clicking Log in.
 *
 * When someone who isn't signed in here (or is the guest) opens an
 * ordinary page, the before_http_headers hook
 * ({@see \auth_nucleus\hook_callbacks}) may send their browser to the
 * hub's authorize endpoint with `prompt=none`: a normal flow from
 * {@see flow::start()} marked silent, with the page as its wantsurl. The
 * hub never shows anything then. It sends the browser straight back to
 * callback.php, either with a code, and the person is signed in exactly
 * as by the Log in button and sent back to the page, or with an error
 * (login_required when they aren't signed in to the hub), and the page is
 * shown again as before, with {@see MARKER} added to its address. No
 * error is ever shown for a silent check.
 *
 * At most one check per browser session: {@see CHECKEDKEY} is set in the
 * session before the browser leaves, and no check starts once it's set.
 *
 * Loops: a browser that doesn't keep cookies gets a new session each
 * time, so the session flag can't stop it. Instead, callback.php always
 * returns with {@see MARKER} in the address, and no check starts on an
 * address that has it. Such a browser also brings back a state the
 * session no longer has; a silent state is recognisable
 * ({@see flow::SILENT_STATE_PREFIX}), so callback.php still returns
 * quietly, to the front page (the page it started on was in the lost
 * session), rather than showing an error. After that, a link followed on
 * this site without the session cookie shows the browser isn't keeping
 * it, and no check starts, so it can't be sent back to the front page on
 * every click. Clients that aren't a browser opening a page (crawlers,
 * scripts, monitors, link previews, frames, fetches, prefetches) don't
 * start a check at all: the browser must say it's a top-level navigation
 * (Fetch Metadata).
 *
 * Signing out: login/logout.php ends the session here and, with single
 * sign-out, then sends the browser to the hub's end_session endpoint,
 * which ends the hub session before sending it back here. So a check on
 * the landing page would get login_required. But the hub session can
 * survive: single sign-out off, an account that isn't a hub account, or
 * the hub asking the person to confirm and their saying no. So signing
 * out also sets a cookie ({@see remember_signed_out()}, from the auth
 * plugin's postlogout_hook) that stops silent sign-in in this browser
 * until the person next signs in with the hub. The session flag can't do
 * this: the session is replaced, and closed, as part of signing out.
 *
 * Old hubs: a hub from before 2026092900 ignores `prompt` and would show
 * its login page. A check only starts if the hub's discovery document
 * lists `none` in `prompt_values_supported` ({@see hub::supports_prompt_none()}).
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class silent {
    /** @var string Query parameter on the address callback.php returns to: no check starts there. */
    public const MARKER = 'nucleussso';

    /** @var string $SESSION property: this session has had its silent check. */
    public const CHECKEDKEY = 'auth_nucleus_silentchecked';

    /** @var string Name of the "signed out here" cookie, before $CFG->sessioncookie. */
    public const SIGNEDOUT_COOKIE = 'MoodleNucleusSignedOut';

    /**
     * @var string[] Scripts (paths under the wwwroot) where no check starts,
     *      because they must never be interrupted or have no use for one:
     *      - /login/: log in, log out, sign up, passwords (the login page
     *        has its own redirect to the hub);
     *      - /auth/: every auth plugin's own pages, this one's included;
     *      - /admin/: administration, upgrades, the core OAuth 2 callback,
     *        multi-factor authentication, site policies, the mobile app's
     *        auto-login;
     *      - /lib/, /webservice/: helper scripts, never a page to return to;
     *      - /enrol/lti/, /mod/lti/: LTI launches and tool sign-in, which
     *        sign people in themselves;
     *      - /error/: error pages;
     *      - /user/policy.php: accepting the site policy;
     *      - /payment/, /repository/: payment gateway and repository
     *        returns, which other sites send the browser back to.
     */
    public const SKIP_SCRIPTS = [
        '/login/',
        '/auth/',
        '/admin/',
        '/lib/',
        '/webservice/',
        '/enrol/lti/',
        '/mod/lti/',
        '/error/',
        '/user/policy.php',
        '/payment/',
        '/repository/',
    ];

    /**
     * @var string[] Page layouts where no check starts: pages in frames or
     *      pop-ups, printed or redirecting pages, maintenance and error
     *      messages, secure (exam) windows and the login layout.
     */
    public const SKIP_LAYOUTS = ['embedded', 'popup', 'frametop', 'print', 'redirect', 'maintenance', 'secure', 'login'];

    /**
     * The facts about the current request that decide whether a check may
     * start, gathered in one place so the decision can be tested.
     *
     * @return array{cli: bool, ajax: bool, ws: bool, nocookies: bool, upgrading: bool, maintenance: bool,
     *      headerssent: bool, crawler: bool, signedout: bool, sessioncookie: bool, method: string,
     *      fetchmode: string, fetchdest: string, fetchsite: string, purpose: string, script: string, url: string}
     */
    public static function current_request(): array {
        global $CFG, $FULLME, $SCRIPT;

        $header = function (string $name): string {
            return strtolower(trim((string) ($_SERVER[$name] ?? '')));
        };
        return [
            'cli' => defined('CLI_SCRIPT') && CLI_SCRIPT,
            'ajax' => defined('AJAX_SCRIPT') && AJAX_SCRIPT,
            'ws' => defined('WS_SERVER') && WS_SERVER,
            'nocookies' => defined('NO_MOODLE_COOKIES') && NO_MOODLE_COOKIES,
            'upgrading' => during_initial_install() || !empty($CFG->upgraderunning) || moodle_needs_upgrading(false),
            'maintenance' => !empty($CFG->maintenance_enabled),
            'headerssent' => headers_sent(),
            'crawler' => \core_useragent::is_web_crawler(),
            'signedout' => !empty($_COOKIE[self::signed_out_cookie()]),
            // Did the browser send this site's session cookie?
            'sessioncookie' => !empty($_COOKIE['MoodleSession' . ($CFG->sessioncookie ?? '')]),
            'method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')),
            // Fetch Metadata, sent by every current browser.
            'fetchmode' => $header('HTTP_SEC_FETCH_MODE'),
            'fetchdest' => $header('HTTP_SEC_FETCH_DEST'),
            'fetchsite' => $header('HTTP_SEC_FETCH_SITE'),
            // Prefetch and prerender requests.
            'purpose' => trim($header('HTTP_SEC_PURPOSE') . ' ' . $header('HTTP_PURPOSE')),
            'script' => (string) ($SCRIPT ?? ''),
            'url' => (string) ($FULLME ?? ''),
        ];
    }

    /**
     * Should this page view start a silent check?
     *
     * Only when every one of these holds:
     * - the visitor isn't signed in here, or is the guest;
     * - this session hasn't had its check, and the browser hasn't signed
     *   out here since it last signed in with the hub;
     * - it's a plain GET of a page, opened by a browser as a top-level
     *   navigation: not a CLI, AJAX or web service script, not a script
     *   without cookies, not during installation, an upgrade or
     *   maintenance, not from a crawler, not a frame, fetch or prefetch,
     *   and not a link followed on this site by a browser that didn't
     *   send the session cookie (it isn't keeping cookies);
     * - the page's address is on this site and doesn't have {@see MARKER},
     *   the script isn't in {@see SKIP_SCRIPTS} and the page layout isn't
     *   in {@see SKIP_LAYOUTS};
     * - silent sign-in is on, hub sign-in is on and fully set up, the
     *   issuer is the connected hub's, and the hub supports prompt=none.
     *
     * @param \moodle_page $page The page being shown.
     * @param array $request From {@see current_request()}.
     * @return bool
     */
    public static function should_start(\moodle_page $page, array $request): bool {
        // Who, and whether this browser has been checked.
        if ((isloggedin() && !isguestuser()) || self::was_checked() || !empty($request['signedout'])) {
            return false;
        }

        // What kind of request.
        foreach (['cli', 'ajax', 'ws', 'nocookies', 'upgrading', 'maintenance', 'headerssent', 'crawler'] as $flag) {
            if (!empty($request[$flag])) {
                return false;
            }
        }
        if (($request['method'] ?? '') !== 'GET') {
            return false;
        }
        if (($request['fetchmode'] ?? '') !== 'navigate' || ($request['fetchdest'] ?? '') !== 'document') {
            return false;
        }
        if (($request['purpose'] ?? '') !== '') {
            return false;
        }
        if (($request['fetchsite'] ?? '') === 'same-origin' && empty($request['sessioncookie'])) {
            return false;
        }

        // Which page.
        $url = flow::local_url((string) ($request['url'] ?? ''));
        if ($url === null || $url->get_param(self::MARKER) !== null) {
            return false;
        }
        $script = (string) ($request['script'] ?? '');
        if ($script === '' || !str_starts_with($script, '/')) {
            return false;
        }
        foreach (self::SKIP_SCRIPTS as $skip) {
            if (str_starts_with($script, $skip)) {
                return false;
            }
        }
        if (in_array($page->pagelayout, self::SKIP_LAYOUTS, true)) {
            return false;
        }

        // Is it on, and can the hub do it? The discovery check is last: it
        // may ask the hub.
        if (!config::silentsignin() || !config::is_enabled() || !config::is_configured() || !hub::issuer_is_on_hub()) {
            return false;
        }
        return hub::supports_prompt_none();
    }

    /**
     * Start a silent check: note it in the session, then start a silent
     * flow back to this page.
     *
     * @param string $wantsurl The page's address. Only a local URL is kept.
     * @return \moodle_url The hub's authorize endpoint, with prompt=none.
     */
    public static function start(string $wantsurl): \moodle_url {
        // Before the browser leaves, so it's never checked twice.
        self::mark_checked();
        return hub::authorize_url(flow::start($wantsurl, true));
    }

    /**
     * Note that this session has had its silent check.
     */
    public static function mark_checked(): void {
        global $SESSION;
        $SESSION->{self::CHECKEDKEY} = time();
    }

    /**
     * Has this session had its silent check?
     *
     * @return bool
     */
    public static function was_checked(): bool {
        global $SESSION;
        return !empty($SESSION->{self::CHECKEDKEY});
    }

    /**
     * Where a silent check that didn't sign anyone in goes back to: the
     * page it started on, with {@see MARKER}, so it can't start again there.
     *
     * @param string $wantsurl The flow's wantsurl; the front page if it isn't local.
     * @return \moodle_url
     */
    public static function return_url(string $wantsurl): \moodle_url {
        $url = flow::local_url($wantsurl) ?? new \moodle_url('/');
        $url->param(self::MARKER, 0);
        return $url;
    }

    /**
     * The "signed out here" cookie's name.
     *
     * @return string
     */
    public static function signed_out_cookie(): string {
        global $CFG;
        return self::SIGNEDOUT_COOKIE . ($CFG->sessioncookie ?? '');
    }

    /**
     * Stop silent sign-in in this browser until it next signs in with the
     * hub. Called when anyone signs out here.
     *
     * A session cookie, on this site's session cookie path and domain.
     * It holds nothing but a flag: someone who forges it only stops
     * silent sign-in for themselves.
     */
    public static function remember_signed_out(): void {
        self::set_cookie('1', 0);
    }

    /**
     * Allow silent sign-in again. Called when someone signs in with the hub.
     */
    public static function forget_signed_out(): void {
        if (!empty($_COOKIE[self::signed_out_cookie()])) {
            self::set_cookie('', time() - HOURSECS);
        }
    }

    /**
     * Set or clear the "signed out here" cookie, for this request too.
     *
     * @param string $value '' to clear it.
     * @param int $expires 0 for a session cookie.
     */
    private static function set_cookie(string $value, int $expires): void {
        global $CFG;

        $name = self::signed_out_cookie();
        if ($value === '') {
            unset($_COOKIE[$name]);
        } else {
            $_COOKIE[$name] = $value;
        }
        if ((defined('NO_MOODLE_COOKIES') && NO_MOODLE_COOKIES) || headers_sent()) {
            return;
        }
        setcookie($name, $value, [
            'expires' => $expires,
            'path' => (string) ($CFG->sessioncookiepath ?? '') ?: '/',
            'domain' => (string) ($CFG->sessioncookiedomain ?? ''),
            'secure' => is_moodle_cookie_secure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
