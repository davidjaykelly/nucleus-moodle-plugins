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
 * The browser side of one sign-in: state, nonce and PKCE, kept in $SESSION.
 *
 * login.php starts a flow and sends the browser to the hub with its
 * state, nonce and PKCE challenge. callback.php takes the flow back out
 * of the session by its state, once: a state can't be used twice.
 *
 * A session can hold a few pending flows at once (sign-in started in
 * several tabs); each lasts ten minutes.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class flow {
    /** @var string $SESSION property holding the pending flows. */
    private const SESSIONKEY = 'auth_nucleus_flows';

    /** @var string $SESSION property holding the ID token, for sign-out. */
    public const IDTOKENKEY = 'auth_nucleus_idtoken';

    /** @var int How many flows a session can have pending. */
    public const MAX_PENDING = 5;

    /** @var int Seconds a flow stays valid. */
    public const LIFETIME = 600;

    /**
     * base64url without padding (RFC 7636 appendix A).
     *
     * @param string $bytes
     * @return string
     */
    public static function base64url(string $bytes): string {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * 32 random bytes from random_bytes(), base64url encoded (43 characters).
     *
     * @return string
     */
    public static function random_token(): string {
        return self::base64url(random_bytes(32));
    }

    /**
     * The PKCE S256 challenge for a verifier: base64url(sha256(verifier)).
     *
     * @param string $verifier
     * @return string
     */
    public static function pkce_challenge(string $verifier): string {
        return self::base64url(hash('sha256', $verifier, true));
    }

    /**
     * Start a sign-in and remember it in the session.
     *
     * @param string $wantsurl Where to go afterwards. Only a local URL is kept.
     * @return \stdClass {state, nonce, verifier, challenge, wantsurl, created}
     */
    public static function start(string $wantsurl): \stdClass {
        global $SESSION;

        $local = self::local_url($wantsurl);
        $verifier = self::random_token();
        $flow = (object) [
            'state' => self::random_token(),
            'nonce' => self::random_token(),
            'verifier' => $verifier,
            'challenge' => self::pkce_challenge($verifier),
            'wantsurl' => $local ? $local->out(false) : '',
            'created' => time(),
        ];

        $pending = self::pending();
        $pending[] = $flow;
        $SESSION->{self::SESSIONKEY} = array_slice($pending, -self::MAX_PENDING);
        return $flow;
    }

    /**
     * Take the flow with this state out of the session.
     *
     * The flow is removed whether or not the sign-in then succeeds, so a
     * state works once. States are compared with hash_equals().
     *
     * @param string $state The state the hub sent back.
     * @return \stdClass|null The flow, or null if there's none (unknown,
     *                        expired or already used).
     */
    public static function consume(string $state): ?\stdClass {
        global $SESSION;

        $pending = self::pending();
        $found = null;
        $keep = [];
        foreach ($pending as $flow) {
            if ($found === null && $state !== '' && hash_equals($flow->state, $state)) {
                $found = $flow;
                continue;
            }
            $keep[] = $flow;
        }
        $SESSION->{self::SESSIONKEY} = $keep;
        return $found;
    }

    /**
     * The flows in the session that haven't expired.
     *
     * @return \stdClass[]
     */
    private static function pending(): array {
        global $SESSION;

        $flows = $SESSION->{self::SESSIONKEY} ?? [];
        if (!is_array($flows)) {
            return [];
        }
        $now = time();
        $valid = [];
        foreach ($flows as $flow) {
            if (
                is_object($flow)
                && isset($flow->state, $flow->nonce, $flow->verifier, $flow->created)
                && is_string($flow->state)
                && (int) $flow->created + self::LIFETIME > $now
            ) {
                $valid[] = $flow;
            }
        }
        return $valid;
    }

    /**
     * A URL on this site, or null. Guards every redirect after sign-in
     * against being sent off-site (open redirects).
     *
     * @param string $url Absolute or root-relative URL.
     * @return \moodle_url|null
     */
    public static function local_url(string $url): ?\moodle_url {
        global $CFG;

        $url = clean_param($url, PARAM_LOCALURL);
        if ($url === '') {
            return null;
        }
        // Accept only root-relative URLs and absolute ones on this site;
        // PARAM_LOCALURL also lets through relative paths, which would
        // resolve against whichever page redirects.
        if (!str_starts_with($url, '/') && !str_starts_with($url, $CFG->wwwroot)) {
            return null;
        }
        try {
            $moodleurl = new \moodle_url($url);
        } catch (\Throwable $e) {
            return null;
        }
        return $moodleurl->is_local_url() ? $moodleurl : null;
    }

    /**
     * Where to send someone who has just signed in.
     *
     * @param string $wantsurl The flow's wantsurl ('' for the default).
     * @return string Absolute URL on this site.
     */
    public static function return_url(string $wantsurl): string {
        global $CFG, $SESSION;
        require_once($CFG->dirroot . '/login/lib.php');

        $local = self::local_url($wantsurl);
        if ($local !== null) {
            $SESSION->wantsurl = $local->out(false);
        }
        return core_login_get_return_url();
    }
}
