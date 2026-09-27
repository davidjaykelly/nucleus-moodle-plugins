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

namespace auth_nucleus;

use auth_nucleus\local\config;
use auth_nucleus\local\flow;
use auth_nucleus\local\hub;
use auth_nucleus\local\silent;

/**
 * Silent sign-in: when a page view starts a check, and what it sends.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(silent::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(hub::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(flow::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(hook_callbacks::class)]
final class silent_test extends \advanced_testcase {
    /** @var string The hub's address. */
    private const HUB = 'https://hub.example.com';

    /** @var string The hub's issuer. */
    private const ISSUER = self::HUB . '/local/nucleushub/oidc';

    /**
     * A spoke with hub sign-in set up and on, a hub that supports
     * prompt=none, and a visitor who isn't signed in.
     */
    protected function setUp(): void {
        global $SESSION;
        parent::setUp();
        $this->resetAfterTest();
        unset($SESSION->auth_nucleus_flows, $SESSION->{silent::CHECKEDKEY}, $_COOKIE[silent::signed_out_cookie()]);

        set_config('hubwwwroot', self::HUB, 'local_nucleusspoke');
        config::save(self::ISSUER, 'client-1', 'the-client-secret', 'Acme Hub', true, true);
        config::set_enabled(true);
        $this->assertTrue(self::discovery(['none']));
        $this->setUser(null);
    }

    /**
     * Answer the next discovery fetch, with a fresh cache.
     *
     * @param array|null $promptvalues prompt_values_supported; null leaves it out (an older hub).
     * @param string $issuer
     * @return bool What hub::supports_prompt_none() says.
     */
    private static function discovery(?array $promptvalues, string $issuer = self::ISSUER): bool {
        \cache::make('auth_nucleus', 'discovery')->purge();
        $document = [
            'issuer' => $issuer,
            'authorization_endpoint' => self::HUB . '/local/nucleushub/oidc/authorize.php',
            'response_types_supported' => ['code'],
        ];
        if ($promptvalues !== null) {
            $document['prompt_values_supported'] = $promptvalues;
        }
        \curl::mock_response(json_encode($document));
        return hub::supports_prompt_none();
    }

    /**
     * The facts of a page view that may start a check, with changes.
     *
     * @param array $overrides
     * @return array As from silent::current_request().
     */
    private static function request(array $overrides = []): array {
        global $CFG;
        return array_merge([
            'cli' => false,
            'ajax' => false,
            'ws' => false,
            'nocookies' => false,
            'upgrading' => false,
            'maintenance' => false,
            'headerssent' => false,
            'crawler' => false,
            'signedout' => false,
            'sessioncookie' => false,
            'method' => 'GET',
            'fetchmode' => 'navigate',
            'fetchdest' => 'document',
            'fetchsite' => 'cross-site',
            'purpose' => '',
            'script' => '/course/view.php',
            'url' => $CFG->wwwroot . '/course/view.php?id=2',
        ], $overrides);
    }

    /**
     * A page with this layout.
     *
     * @param string $layout
     * @return \moodle_page
     */
    private static function page(string $layout = 'incourse'): \moodle_page {
        $page = new \moodle_page();
        $page->set_pagelayout($layout);
        return $page;
    }

    /**
     * A visitor's page view starts one check: prompt=none to the hub, with a
     * silent flow back to the page, noted in the session first.
     */
    public function test_visitor_is_checked_once(): void {
        global $CFG, $SESSION;

        $this->assertTrue(silent::should_start(self::page(), self::request()));
        $url = silent::start(self::request()['url']);

        $this->assertSame(self::HUB . '/local/nucleushub/oidc/authorize.php', $url->out_omit_querystring());
        $this->assertSame('none', $url->get_param('prompt'));
        $this->assertSame('client-1', $url->get_param('client_id'));
        $this->assertSame($CFG->wwwroot . '/auth/nucleus/callback.php', $url->get_param('redirect_uri'));
        $this->assertSame('code', $url->get_param('response_type'));
        $this->assertSame('S256', $url->get_param('code_challenge_method'));
        $this->assertStringStartsWith(flow::SILENT_STATE_PREFIX, $url->get_param('state'));
        $this->assertMatchesRegularExpression(flow::STATE_PATTERN, $url->get_param('state'));

        $this->assertTrue(silent::was_checked());
        $this->assertCount(1, $SESSION->auth_nucleus_flows);
        $flow = $SESSION->auth_nucleus_flows[0];
        $this->assertTrue($flow->silent);
        $this->assertSame($url->get_param('state'), $flow->state);
        $this->assertSame($url->get_param('nonce'), $flow->nonce);
        $this->assertSame($url->get_param('code_challenge'), $flow->challenge);
        $this->assertSame($CFG->wwwroot . '/course/view.php?id=2', $flow->wantsurl);

        // Not again in this session.
        $this->assertFalse(silent::should_start(self::page(), self::request()));
    }

    /**
     * The guest is checked too.
     */
    public function test_guest_is_checked(): void {
        $this->setGuestUser();
        $this->assertTrue(silent::should_start(self::page(), self::request()));
    }

    /**
     * Someone signed in is never sent anywhere.
     */
    public function test_signed_in_people_are_never_checked(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(silent::should_start(self::page(), self::request()));
        $this->setAdminUser();
        $this->assertFalse(silent::should_start(self::page(), self::request()));
    }

    /**
     * Requests that never start a check.
     *
     * @return array
     */
    public static function excluded_request_provider(): array {
        return [
            'CLI script' => [['cli' => true]],
            'AJAX script' => [['ajax' => true]],
            'web service' => [['ws' => true]],
            'script without cookies' => [['nocookies' => true]],
            'installing or upgrading' => [['upgrading' => true]],
            'maintenance mode' => [['maintenance' => true]],
            'headers already sent' => [['headerssent' => true]],
            'crawler' => [['crawler' => true]],
            'signed out here' => [['signedout' => true]],
            'POST' => [['method' => 'POST']],
            'HEAD' => [['method' => 'HEAD']],
            'no Fetch Metadata (not a browser)' => [['fetchmode' => '', 'fetchdest' => '']],
            'fetch' => [['fetchmode' => 'cors', 'fetchdest' => 'empty']],
            'no-cors' => [['fetchmode' => 'no-cors']],
            'frame' => [['fetchdest' => 'iframe']],
            'nested navigation in a frame' => [['fetchdest' => 'frame']],
            'prefetch' => [['purpose' => 'prefetch']],
            'prerender' => [['purpose' => 'prefetch;prerender']],
            'link here without the session cookie' => [['fetchsite' => 'same-origin', 'sessioncookie' => false]],
            'marker' => [['url' => 'MARKER']],
            'marker only' => [['url' => 'MARKERONLY']],
            'another site' => [['url' => 'https://evil.example.com/course/view.php?id=2']],
            'no address' => [['url' => '']],
            'no script' => [['script' => '']],
        ];
    }

    /**
     * Each excluded request starts nothing.
     *
     * @param array $overrides
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('excluded_request_provider')]
    public function test_excluded_requests(array $overrides): void {
        global $CFG;
        if (($overrides['url'] ?? '') === 'MARKER') {
            $overrides['url'] = $CFG->wwwroot . '/course/view.php?id=2&' . silent::MARKER . '=0';
        } else if (($overrides['url'] ?? '') === 'MARKERONLY') {
            $overrides['url'] = $CFG->wwwroot . '/?' . silent::MARKER . '=1';
            $overrides['script'] = '/index.php';
        }
        $this->assertTrue(silent::should_start(self::page(), self::request()));
        $this->assertFalse(silent::should_start(self::page(), self::request($overrides)));
        $this->assertFalse(silent::was_checked());
    }

    /**
     * A link followed here with the session cookie, or arriving from another
     * site (the hub, say, on a sibling address) or typed in, is checked.
     */
    public function test_navigations_that_are_checked(): void {
        foreach ([
            ['fetchsite' => 'same-origin', 'sessioncookie' => true],
            ['fetchsite' => 'same-site'],
            ['fetchsite' => 'cross-site'],
            ['fetchsite' => 'none'],
        ] as $overrides) {
            $this->assertTrue(silent::should_start(self::page(), self::request($overrides)), json_encode($overrides));
        }
    }

    /**
     * Scripts where no check starts.
     *
     * @return array
     */
    public static function skipped_script_provider(): array {
        return [
            ['/login/index.php'],
            ['/login/logout.php'],
            ['/login/signup.php'],
            ['/login/forgot_password.php'],
            ['/auth/nucleus/login.php'],
            ['/auth/nucleus/callback.php'],
            ['/auth/oauth2/login.php'],
            ['/admin/index.php'],
            ['/admin/oauth2callback.php'],
            ['/admin/tool/mfa/auth.php'],
            ['/admin/tool/policy/index.php'],
            ['/admin/tool/mobile/autologin.php'],
            ['/lib/ajax/service.php'],
            ['/webservice/rest/server.php'],
            ['/enrol/lti/launch.php'],
            ['/mod/lti/auth.php'],
            ['/error/index.php'],
            ['/user/policy.php'],
            ['/payment/gateway/paypal/return.php'],
            ['/repository/repository_callback.php'],
        ];
    }

    /**
     * No check on pages that must never be interrupted.
     *
     * @param string $script
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('skipped_script_provider')]
    public function test_skipped_scripts(string $script): void {
        global $CFG;
        $this->assertFalse(silent::should_start(self::page('standard'),
            self::request(['script' => $script, 'url' => $CFG->wwwroot . $script])));
    }

    /**
     * Ordinary pages anyone may see are checked.
     */
    public function test_ordinary_pages_are_checked(): void {
        global $CFG;
        foreach ([
            ['/index.php', '/', 'frontpage'],
            ['/index.php', '/?redirect=0', 'frontpage'],
            ['/course/index.php', '/course/index.php', 'coursecategory'],
            ['/course/view.php', '/course/view.php?id=2', 'course'],
            ['/mod/page/view.php', '/mod/page/view.php?id=3', 'incourse'],
        ] as [$script, $path, $layout]) {
            $this->assertTrue(silent::should_start(self::page($layout),
                self::request(['script' => $script, 'url' => $CFG->wwwroot . $path])), $path);
        }
    }

    /**
     * Page layouts where no check starts.
     *
     * @return array
     */
    public static function skipped_layout_provider(): array {
        return array_map(fn(string $layout): array => [$layout], silent::SKIP_LAYOUTS);
    }

    /**
     * No check in frames, pop-ups, redirects, maintenance and the like.
     *
     * @param string $layout
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('skipped_layout_provider')]
    public function test_skipped_layouts(string $layout): void {
        $this->assertFalse(silent::should_start(self::page($layout), self::request()));
    }

    /**
     * Only when silent sign-in and hub sign-in are both on and working.
     */
    public function test_needs_hub_signin_on_and_working(): void {
        $this->assertTrue(silent::should_start(self::page(), self::request()));

        // The site's switch.
        set_config('silentsignin', 0, 'auth_nucleus');
        $this->assertFalse(silent::should_start(self::page(), self::request()));
        set_config('silentsignin', 1, 'auth_nucleus');
        $this->assertTrue(silent::should_start(self::page(), self::request()));
        unset_config('silentsignin', 'auth_nucleus');
        $this->assertTrue(silent::should_start(self::page(), self::request()), 'On unless turned off');

        // The auth plugin turned off.
        config::set_enabled(false);
        $this->assertFalse(silent::should_start(self::page(), self::request()));
        config::set_enabled(true);
        $this->assertTrue(silent::should_start(self::page(), self::request()));

        // No client secret.
        config::clear_secret();
        $this->assertFalse(silent::should_start(self::page(), self::request()));
    }

    /**
     * Not when the issuer isn't the connected hub's.
     */
    public function test_needs_the_issuer_on_the_hub(): void {
        set_config('hubwwwroot', 'https://other-hub.example.com', 'local_nucleusspoke');
        $this->assertFalse(silent::should_start(self::page(), self::request()));
    }

    /**
     * An older hub, which doesn't list prompt=none, would show its login
     * page, so no check starts.
     */
    public function test_needs_a_hub_that_supports_prompt_none(): void {
        $this->assertFalse(self::discovery(null));
        $this->assertFalse(silent::should_start(self::page(), self::request()));

        $this->assertFalse(self::discovery(['login', 'consent']));
        $this->assertFalse(self::discovery([]));
        $this->assertFalse(self::discovery(['none'], 'https://evil.example.com/local/nucleushub/oidc'));
        $this->assertTrue(self::discovery(['none', 'login']));
        $this->assertTrue(silent::should_start(self::page(), self::request()));
    }

    /**
     * The hub's answer is cached, and saving the settings asks again. A hub
     * that can't be reached counts as not supporting it.
     */
    public function test_discovery_answer_is_cached(): void {
        $this->assertTrue(self::discovery(['none']));

        // Unreachable now, but not asked again.
        set_config('hubconnecturl', 'http://127.0.0.1:9', 'local_nucleusspoke');
        $this->assertTrue(hub::supports_prompt_none());

        // Saving the settings forgets the answer; the hub can't be reached.
        config::save(self::ISSUER, 'client-1', 'the-client-secret', 'Acme Hub', true, true);
        $this->assertFalse(hub::supports_prompt_none());
        $this->assertFalse(silent::should_start(self::page(), self::request()));
    }

    /**
     * Discovery is fetched from the hub connection, under the issuer.
     */
    public function test_discovery_goes_to_the_hub_connection(): void {
        $http = \local_nucleuscommon\transport\hub_http::from_spoke_config();
        $this->assertSame(self::HUB . '/local/nucleushub/oidc/discovery.php',
            $http->internal_url(hub::endpoint('discovery.php')));
    }

    /**
     * A quiet return goes back to the page with the marker, which stops a
     * second check even in a session that lost its flag (a browser without
     * cookies). Anything that isn't local goes to the front page.
     */
    public function test_quiet_return_has_the_marker_and_stops_a_second_check(): void {
        global $CFG, $SESSION;

        $url = silent::return_url($CFG->wwwroot . '/course/view.php?id=2');
        $this->assertSame($CFG->wwwroot . '/course/view.php?id=2&' . silent::MARKER . '=0', $url->out(false));

        unset($SESSION->{silent::CHECKEDKEY});
        $this->assertFalse(silent::should_start(self::page(), self::request(['url' => $url->out(false)])));

        $this->assertSame($CFG->wwwroot . '/?' . silent::MARKER . '=0',
            silent::return_url('https://evil.example.com/')->out(false));
        $this->assertSame($CFG->wwwroot . '/?' . silent::MARKER . '=0', silent::return_url('')->out(false));
    }

    /**
     * Signing out here stops silent sign-in in this browser, even though the
     * session is new; signing in with the hub allows it again.
     */
    public function test_signing_out_stops_silent_signin(): void {
        $user = $this->getDataGenerator()->create_user(['auth' => 'nucleus']);
        $auth = get_auth_plugin('nucleus');

        $auth->postlogout_hook($user);
        $this->assertSame('1', $_COOKIE[silent::signed_out_cookie()]);
        $request = silent::current_request();
        $this->assertTrue($request['signedout']);
        $this->assertFalse(silent::should_start(self::page(), self::request(['signedout' => $request['signedout']])));

        silent::forget_signed_out();
        $this->assertArrayNotHasKey(silent::signed_out_cookie(), $_COOKIE);
        $this->assertFalse(silent::current_request()['signedout']);

        // Without hub sign-in set up, signing out leaves nothing behind.
        config::clear_secret();
        $auth->postlogout_hook($user);
        $this->assertArrayNotHasKey(silent::signed_out_cookie(), $_COOKIE);
    }

    /**
     * The hook is registered.
     */
    public function test_hook_is_registered(): void {
        $callbacks = \core\di::get(\core\hook\manager::class)
            ->get_callbacks_for_hook(\core\hook\output\before_http_headers::class);
        $this->assertContains('auth_nucleus', array_column($callbacks, 'component'));
    }

    /**
     * The hook itself: under PHPUnit the request is a CLI script, so the
     * real request's facts start nothing, and the hook never throws.
     */
    public function test_hook_starts_nothing_for_a_cli_script(): void {
        global $PAGE, $SESSION;

        $this->assertTrue(silent::current_request()['cli']);
        hook_callbacks::before_http_headers(new \core\hook\output\before_http_headers($PAGE->get_renderer('core')));
        $this->assertFalse(silent::was_checked());
        $this->assertEmpty($SESSION->auth_nucleus_flows ?? []);
    }
}
