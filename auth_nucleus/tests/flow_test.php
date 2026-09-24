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

use auth_nucleus\local\flow;
use auth_nucleus\local\hub;

/**
 * State, nonce, PKCE and the return URL.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \auth_nucleus\local\flow
 * @covers     \auth_nucleus\local\hub
 */
final class flow_test extends \advanced_testcase {

    /**
     * Each test starts with an empty session.
     */
    protected function setUp(): void {
        global $SESSION;
        parent::setUp();
        $this->resetAfterTest();
        unset($SESSION->auth_nucleus_flows);
    }

    /**
     * The S256 challenge matches RFC 7636 appendix B.
     */
    public function test_pkce_challenge_matches_rfc7636(): void {
        $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            flow::pkce_challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
    }

    /**
     * State, nonce and verifier are distinct 32-byte base64url values.
     */
    public function test_start_makes_random_values(): void {
        $flow = flow::start('');
        foreach (['state', 'nonce', 'verifier'] as $field) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $flow->$field);
        }
        $this->assertNotSame($flow->state, $flow->nonce);
        $this->assertSame(flow::pkce_challenge($flow->verifier), $flow->challenge);
        $this->assertNotSame($flow->state, flow::start('')->state);
    }

    /**
     * A state works once.
     */
    public function test_state_is_single_use(): void {
        $flow = flow::start('/course/view.php?id=2');
        $this->assertEquals($flow, flow::consume($flow->state));
        $this->assertNull(flow::consume($flow->state));
    }

    /**
     * Unknown, empty and expired states find nothing.
     */
    public function test_unknown_or_expired_state_is_refused(): void {
        global $SESSION;

        $flow = flow::start('');
        $this->assertNull(flow::consume(''));
        $this->assertNull(flow::consume('not-the-state-' . $flow->state));

        $SESSION->auth_nucleus_flows[0]->created = time() - flow::LIFETIME - 1;
        $this->assertNull(flow::consume($flow->state));
    }

    /**
     * Sign-in started in several tabs works in each, up to the limit.
     */
    public function test_several_pending_flows(): void {
        $flows = [];
        for ($i = 0; $i < flow::MAX_PENDING + 1; $i++) {
            $flows[] = flow::start('');
        }
        // The oldest was dropped.
        $this->assertNull(flow::consume($flows[0]->state));
        $this->assertNotNull(flow::consume($flows[2]->state));
        $this->assertNotNull(flow::consume($flows[1]->state));
    }

    /**
     * Only local URLs are kept as the place to return to.
     */
    public function test_wantsurl_must_be_local(): void {
        global $CFG;

        $this->assertSame($CFG->wwwroot . '/course/view.php?id=2', flow::start('/course/view.php?id=2')->wantsurl);
        $this->assertSame('', flow::start('https://evil.example.com/')->wantsurl);
        $this->assertSame('', flow::start('javascript:alert(1)')->wantsurl);
        $this->assertSame('', flow::start('course/view.php')->wantsurl);

        $this->assertNull(flow::local_url('https://evil.example.com/'));
        $this->assertNull(flow::local_url($CFG->wwwroot . '.evil.example.com/'));
        $local = flow::local_url('//evil.example.com/');
        $this->assertTrue($local === null || $local->is_local_url());
    }

    /**
     * The authorize URL carries everything the hub needs, and the
     * challenge rather than the verifier.
     */
    public function test_authorize_url(): void {
        global $CFG;

        set_config('issuer', 'https://hub.example.com/local/nucleushub/oidc', 'auth_nucleus');
        set_config('clientid', 'client-1', 'auth_nucleus');
        $flow = flow::start('');
        $url = hub::authorize_url($flow);

        $this->assertSame('https://hub.example.com/local/nucleushub/oidc/authorize.php', $url->out_omit_querystring());
        $this->assertSame('client-1', $url->get_param('client_id'));
        $this->assertSame($CFG->wwwroot . '/auth/nucleus/callback.php', $url->get_param('redirect_uri'));
        $this->assertSame('code', $url->get_param('response_type'));
        $this->assertSame('openid profile email', $url->get_param('scope'));
        $this->assertSame($flow->state, $url->get_param('state'));
        $this->assertSame($flow->nonce, $url->get_param('nonce'));
        $this->assertSame($flow->challenge, $url->get_param('code_challenge'));
        $this->assertSame('S256', $url->get_param('code_challenge_method'));
        $this->assertStringNotContainsString($flow->verifier, $url->out(false));
    }
}
