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

namespace local_nucleushub\local\oidc;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use local_nucleushub\event\user_signed_in_to_spoke;

/**
 * "Sign in with the hub": a minimal OpenID Connect provider for this
 * hub's spokes (ADR-023 section 3, phase 2 spec section 1).
 *
 * Authorisation code flow with PKCE S256 and confidential clients only.
 * No implicit or hybrid flow, no other grant types, no refresh tokens,
 * no consent screen (spokes are first-party clients registered by
 * Nucleus), no request objects.
 *
 * The endpoint files in `oidc/` are thin: they read the request, call
 * one method here and send what it returns.
 *
 * The issuer is always built from `$CFG->wwwroot`, never from the
 * request, because spokes call the server-to-server endpoints through
 * a cluster-internal address with a Host header.
 *
 * `sub` is the user's opaque subject from {@see subjects}, never the
 * user id.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider {
    /** @var string[] Scopes this provider knows. `openid` is required. */
    public const SCOPES = ['openid', 'profile', 'email'];

    /** @var string[] Auth plugins whose accounts never sign in to spokes. */
    public const REFUSED_AUTH = ['webservice', 'nologin'];

    /** @var string `state`: 1 to 512 visible ASCII characters (RFC 6749 VSCHAR). */
    public const STATE_PATTERN = '/^[\x20-\x7E]{1,512}$/';

    /** @var string `nonce`: 1 to 255 visible ASCII characters. */
    public const NONCE_PATTERN = '/^[\x20-\x7E]{1,255}$/';

    /** @var string An S256 `code_challenge`: exactly 43 base64url characters. */
    public const CHALLENGE_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    /** @var string A `code_verifier` (RFC 7636 section 4.1). */
    public const VERIFIER_PATTERN = '/^[A-Za-z0-9._~-]{43,128}$/';

    /** @var string Media type of token and userinfo request bodies. */
    public const FORM_TYPE = 'application/x-www-form-urlencoded';

    /** @var array Headers on every token and userinfo response. */
    public const NO_STORE = ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'];

    /** @var string Longest ID token accepted as an end_session hint. */
    private const MAX_HINT_LENGTH = 8192;

    /**
     * The issuer: the public, browser-facing address of the provider.
     *
     * @return string
     */
    public static function issuer(): string {
        global $CFG;
        return $CFG->wwwroot . '/local/nucleushub/oidc';
    }

    /**
     * The public URL of one of the endpoint files.
     *
     * @param string $file For example 'token.php'.
     * @return string
     */
    public static function endpoint(string $file): string {
        return self::issuer() . '/' . $file;
    }

    /**
     * The OpenID Provider configuration (OpenID Connect Discovery 1.0).
     *
     * @return array
     */
    public static function discovery(): array {
        return [
            'issuer' => self::issuer(),
            'authorization_endpoint' => self::endpoint('authorize.php'),
            'token_endpoint' => self::endpoint('token.php'),
            'userinfo_endpoint' => self::endpoint('userinfo.php'),
            'jwks_uri' => self::endpoint('jwks.php'),
            'end_session_endpoint' => self::endpoint('end_session.php'),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => [keys::ALG],
            'scopes_supported' => self::SCOPES,
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'code_challenge_methods_supported' => ['S256'],
            'claims_supported' => [
                'iss', 'sub', 'aud', 'exp', 'iat', 'auth_time', 'nonce',
                'email', 'email_verified', 'given_name', 'family_name', 'name', 'locale',
            ],
            'claims_parameter_supported' => false,
            'request_parameter_supported' => false,
            'request_uri_parameter_supported' => false,
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    /**
     * May this hub account sign in to spokes?
     *
     * Refuses guests, deleted, suspended and unconfirmed accounts,
     * `webservice` and `nologin` accounts, and accounts whose auth
     * plugin is turned off (Moodle's own login treats those as
     * suspended).
     *
     * @param \stdClass|null $user A full user row, read fresh from the database.
     * @return bool
     */
    public static function user_can_sign_in(?\stdClass $user): bool {
        if (!$user || empty($user->id) || isguestuser($user)) {
            return false;
        }
        if (!empty($user->deleted) || !empty($user->suspended) || empty($user->confirmed)) {
            return false;
        }
        $auth = empty($user->auth) ? 'manual' : (string) $user->auth;
        if (in_array($auth, self::REFUSED_AUTH, true)) {
            return false;
        }
        return is_enabled_auth($auth);
    }

    /**
     * The profile claims for a user, as sent in ID tokens and by userinfo.
     *
     * `locale` is the user's Moodle language code (for example `en` or
     * `pt_br`), which is what spokes set their `lang` from.
     *
     * @param \stdClass $user Full user row.
     * @return array
     */
    public static function claims(\stdClass $user): array {
        global $CFG;
        return [
            'email' => (string) $user->email,
            'email_verified' => !empty($user->confirmed),
            'given_name' => (string) $user->firstname,
            'family_name' => (string) $user->lastname,
            'name' => fullname($user),
            'locale' => (string) (!empty($user->lang) ? $user->lang : ($CFG->lang ?? 'en')),
        ];
    }

    // Authorisation endpoint.

    /**
     * Check an authorisation request.
     *
     * The client and its exact redirect URI are checked first. If either
     * is wrong, nothing may be sent to the redirect URI, so this throws
     * and the endpoint shows an error page. Any other problem is
     * returned in the request's `error`, to be sent back to the client.
     *
     * @param array $params The query parameters.
     * @return authorize_request
     * @throws \moodle_exception If the client is unknown or the redirect URI isn't its registered one.
     */
    public static function parse_authorize_request(array $params): authorize_request {
        $clientid = self::param($params, 'client_id') ?? '';
        $redirecturi = self::param($params, 'redirect_uri') ?? '';
        $client = client_registry::find_active($clientid);
        if (!$client || $redirecturi === '' || $redirecturi !== (string) $client->redirecturi) {
            throw new \moodle_exception('oidc_badclient', 'local_nucleushub');
        }

        $state = self::param($params, 'state') ?? '';
        $stateok = $state !== '' && preg_match(self::STATE_PATTERN, $state);
        $nonce = self::param($params, 'nonce') ?? '';
        $challenge = self::param($params, 'code_challenge') ?? '';
        $method = self::param($params, 'code_challenge_method');
        $responsetype = self::param($params, 'response_type');
        $responsemode = self::param($params, 'response_mode');
        $scopes = preg_split('/ +/', trim(self::param($params, 'scope') ?? ''), -1, PREG_SPLIT_NO_EMPTY);

        $error = null;
        $description = '';
        if ($responsetype === null || $responsetype === '') {
            [$error, $description] = ['invalid_request', 'response_type is required.'];
        } else if ($responsetype !== 'code') {
            [$error, $description] = ['unsupported_response_type', 'Only the authorization code flow is supported.'];
        } else if (self::param($params, 'request') !== null) {
            [$error, $description] = ['request_not_supported', 'Request objects are not supported.'];
        } else if (self::param($params, 'request_uri') !== null) {
            [$error, $description] = ['request_uri_not_supported', 'Request objects are not supported.'];
        } else if ($responsemode !== null && $responsemode !== 'query') {
            [$error, $description] = ['invalid_request', 'Only the query response mode is supported.'];
        } else if (!in_array('openid', $scopes, true)) {
            [$error, $description] = ['invalid_scope', 'The openid scope is required.'];
        } else if (!$stateok) {
            [$error, $description] = ['invalid_request', 'state is required.'];
        } else if ($nonce === '' || !preg_match(self::NONCE_PATTERN, $nonce)) {
            [$error, $description] = ['invalid_request', 'nonce is required.'];
        } else if ($method !== 'S256') {
            [$error, $description] = ['invalid_request', 'code_challenge_method must be S256.'];
        } else if (!preg_match(self::CHALLENGE_PATTERN, $challenge)) {
            [$error, $description] = ['invalid_request', 'code_challenge is required.'];
        }

        return new authorize_request(
            $client,
            (string) $client->redirecturi,
            $stateok ? $state : '',
            $error === null ? $nonce : '',
            $error === null ? $challenge : '',
            $error,
            $description
        );
    }

    /**
     * Where to send the browser to report an error to the client.
     *
     * @param authorize_request $request
     * @param string|null $error Defaults to the request's own error.
     * @param string $description
     * @return \moodle_url The registered redirect URI with `error`, `error_description`, `state` and `iss`.
     */
    public static function authorize_error_url(
        authorize_request $request,
        ?string $error = null,
        string $description = ''
    ): \moodle_url {
        if ($error === null) {
            $error = $request->error ?? 'server_error';
            $description = $request->errordescription;
        }
        $params = ['error' => $error];
        if ($description !== '') {
            $params['error_description'] = $description;
        }
        if ($request->state !== '') {
            $params['state'] = $request->state;
        }
        $params['iss'] = self::issuer();
        return new \moodle_url($request->redirecturi, $params);
    }

    /**
     * Finish a good authorisation request for the signed-in user.
     *
     * @param authorize_request $request A request with no error.
     * @param \stdClass $user The signed-in user, read fresh from the database.
     * @param int $authtime When they signed in to the hub.
     * @param bool $loggedinas True if this is a "Log in as" session.
     * @return \moodle_url The redirect URI with `code`, `state` and `iss`, or with `error=access_denied`.
     */
    public static function complete_authorize(
        authorize_request $request,
        \stdClass $user,
        int $authtime,
        bool $loggedinas
    ): \moodle_url {
        if ($request->error !== null) {
            throw new \coding_exception('complete_authorize() needs a request with no error.');
        }
        // Someone using "Log in as" is never passed on to another site
        // as the person they are looking at.
        if ($loggedinas || !self::user_can_sign_in($user)) {
            return self::authorize_error_url($request, 'access_denied', 'This account can\'t sign in to other sites.');
        }

        // Make the user's subject now, outside any transaction, so the
        // token endpoint only has to read it.
        subjects::for_user((int) $user->id);

        $code = tokens::issue_code(
            $request->client,
            (int) $user->id,
            $request->redirecturi,
            $request->nonce,
            $request->codechallenge,
            $authtime > 0 ? $authtime : time()
        );
        $params = ['code' => $code];
        if ($request->state !== '') {
            $params['state'] = $request->state;
        }
        $params['iss'] = self::issuer();
        return new \moodle_url($request->redirecturi, $params);
    }

    // Token endpoint.

    /**
     * Exchange an authorisation code for an ID token and access token.
     *
     * @param string $method HTTP method.
     * @param string $contenttype Request media type, lower case, no parameters.
     * @param array $post The form body ($_POST). Query parameters are never read.
     * @param string|null $authorization The Authorization header, if any.
     * @return response
     */
    public static function token(string $method, string $contenttype, array $post, ?string $authorization): response {
        if ($method !== 'POST') {
            return self::token_error(400, 'invalid_request', 'The token endpoint only accepts POST.');
        }
        if ($contenttype !== self::FORM_TYPE) {
            return self::token_error(400, 'invalid_request', 'The body must be application/x-www-form-urlencoded.');
        }

        // Authenticate the client before looking at anything else.
        $auth = self::authenticate_client($post, $authorization);
        if ($auth instanceof response) {
            return $auth;
        }
        $client = $auth;

        $granttype = self::param($post, 'grant_type') ?? '';
        if ($granttype === '') {
            return self::token_error(400, 'invalid_request', 'grant_type is required.');
        }
        if ($granttype !== 'authorization_code') {
            return self::token_error(400, 'unsupported_grant_type', 'Only authorization_code is supported.');
        }
        $code = self::param($post, 'code') ?? '';
        $redirecturi = self::param($post, 'redirect_uri') ?? '';
        $verifier = self::param($post, 'code_verifier') ?? '';
        if ($code === '' || $redirecturi === '' || $verifier === '') {
            return self::token_error(400, 'invalid_request', 'code, redirect_uri and code_verifier are required.');
        }
        if (!preg_match(self::VERIFIER_PATTERN, $verifier)) {
            return self::token_error(400, 'invalid_request', 'code_verifier is malformed.');
        }

        // The code is gone from here on, whatever the checks below say.
        $row = tokens::redeem_code($code);
        $invalid = self::token_error(400, 'invalid_grant', 'The code is invalid, expired or already used.');
        if (!$row || (int) $row->expires <= time()) {
            return $invalid;
        }
        if (!hash_equals((string) $row->clientid, (string) $client->clientid)) {
            return $invalid;
        }
        if ((string) $row->redirecturi !== $redirecturi) {
            return $invalid;
        }
        if (!hash_equals((string) $row->codechallenge, tokens::pkce_challenge($verifier))) {
            return $invalid;
        }
        $user = self::load_user((int) $row->userid);
        if (!self::user_can_sign_in($user)) {
            return $invalid;
        }

        $idtoken = self::id_token($client, $user, (string) $row->nonce, (int) $row->authtime);
        $accesstoken = tokens::issue_access_token($client, (int) $user->id);

        user_signed_in_to_spoke::create([
            'context' => \context_system::instance(),
            'userid' => (int) $user->id,
            'objectid' => (int) $client->id,
            'other' => [
                'clientid' => (string) $client->clientid,
                'spoke' => (string) $client->spokewwwroot,
            ],
        ])->trigger();

        return new response(200, [
            'access_token' => $accesstoken,
            'token_type' => 'Bearer',
            'expires_in' => tokens::ACCESS_TOKEN_TTL,
            'id_token' => $idtoken,
        ], self::NO_STORE);
    }

    /**
     * The signed ID token for a user and client.
     *
     * @param \stdClass $client
     * @param \stdClass $user
     * @param string $nonce
     * @param int $authtime
     * @return string
     */
    public static function id_token(\stdClass $client, \stdClass $user, string $nonce, int $authtime): string {
        $now = time();
        $claims = [
            'iss' => self::issuer(),
            'sub' => subjects::for_user((int) $user->id),
            'aud' => (string) $client->clientid,
            'exp' => $now + tokens::ID_TOKEN_TTL,
            'iat' => $now,
            'auth_time' => $authtime,
            'nonce' => $nonce,
        ] + self::claims($user);
        return keys::sign($claims);
    }

    /**
     * Authenticate the client with client_secret_basic or client_secret_post.
     *
     * Using both at once is refused (RFC 6749 section 2.3).
     *
     * @param array $post
     * @param string|null $authorization
     * @return \stdClass|response The active client, or the error to send.
     */
    private static function authenticate_client(array $post, ?string $authorization) {
        $basic = null;
        if ($authorization !== null && preg_match('/^Basic\s+(\S+)$/i', trim($authorization), $matches)) {
            $decoded = base64_decode($matches[1], true);
            if ($decoded === false || !str_contains($decoded, ':')) {
                return self::invalid_client(true);
            }
            [$id, $secret] = explode(':', $decoded, 2);
            // RFC 6749 section 2.3.1: both are form-encoded first.
            $basic = [urldecode($id), urldecode($secret)];
        }

        $postid = self::param($post, 'client_id');
        $postsecret = self::param($post, 'client_secret');
        if ($basic !== null) {
            if ($postsecret !== null) {
                return self::token_error(400, 'invalid_request', 'Use one client authentication method.');
            }
            if ($postid !== null && $postid !== $basic[0]) {
                return self::token_error(400, 'invalid_request', 'client_id doesn\'t match the credentials.');
            }
            [$clientid, $secret] = $basic;
        } else if ($postid !== null && $postsecret !== null) {
            [$clientid, $secret] = [$postid, $postsecret];
        } else {
            return self::invalid_client(false);
        }

        $client = client_registry::authenticate($clientid, $secret);
        return $client ?? self::invalid_client($basic !== null);
    }

    /**
     * The invalid_client response.
     *
     * @param bool $basic True if the client tried HTTP Basic, which then needs a WWW-Authenticate challenge.
     * @return response
     */
    private static function invalid_client(bool $basic): response {
        $headers = self::NO_STORE;
        if ($basic) {
            $headers['WWW-Authenticate'] = 'Basic realm="Nucleus"';
        }
        return response::error(401, 'invalid_client', 'Client authentication failed.', $headers);
    }

    /**
     * A token endpoint error, not to be cached.
     *
     * @param int $status
     * @param string $error
     * @param string $description
     * @return response
     */
    private static function token_error(int $status, string $error, string $description): response {
        return response::error($status, $error, $description, self::NO_STORE);
    }

    // Userinfo endpoint.

    /**
     * The claims for a Bearer access token.
     *
     * The token is taken from the Authorization header, or from a POSTed
     * form body. Tokens in the query string are refused (RFC 6750
     * section 2.3 advises against them), as is sending two.
     *
     * @param string $method
     * @param string $contenttype
     * @param array $get Query parameters, only checked for a misplaced token.
     * @param array $post Form body.
     * @param string|null $authorization
     * @return response
     */
    public static function userinfo(
        string $method,
        string $contenttype,
        array $get,
        array $post,
        ?string $authorization
    ): response {
        if ($method !== 'GET' && $method !== 'POST') {
            return self::bearer_error(400, 'invalid_request', 'Use GET or POST.');
        }
        if (self::param($get, 'access_token') !== null) {
            return self::bearer_error(400, 'invalid_request', 'Send the access token in the Authorization header.');
        }

        $fromheader = null;
        if ($authorization !== null && preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $matches)) {
            $fromheader = $matches[1];
        }
        $frombody = null;
        if ($method === 'POST' && $contenttype === self::FORM_TYPE) {
            $frombody = self::param($post, 'access_token');
        }
        if ($fromheader !== null && $frombody !== null) {
            return self::bearer_error(400, 'invalid_request', 'Send the access token once.');
        }
        $token = $fromheader ?? $frombody ?? '';
        if ($token === '') {
            // RFC 6750 section 3.1: no error code in the challenge when
            // no credentials were sent.
            return response::error(401, 'invalid_token', 'An access token is required.', self::NO_STORE + [
                'WWW-Authenticate' => 'Bearer realm="Nucleus"',
            ]);
        }

        $row = tokens::find_access_token($token);
        $client = $row ? client_registry::find_active((string) $row->clientid) : null;
        $user = $row ? self::load_user((int) $row->userid) : null;
        if (!$row || !$client || !self::user_can_sign_in($user)) {
            return self::bearer_error(401, 'invalid_token', 'The access token is invalid or expired.');
        }

        return new response(200, ['sub' => subjects::for_user((int) $user->id)] + self::claims($user), self::NO_STORE);
    }

    /**
     * A Bearer (RFC 6750) error, with the matching WWW-Authenticate challenge.
     *
     * @param int $status
     * @param string $error
     * @param string $description
     * @return response
     */
    private static function bearer_error(int $status, string $error, string $description): response {
        $headers = self::NO_STORE + [
            'WWW-Authenticate' => 'Bearer realm="Nucleus", error="' . $error . '"',
        ];
        return response::error($status, $error, $description, $headers);
    }

    // End session endpoint.

    /**
     * Decide what end_session.php does.
     *
     * The browser is only ever sent to a registered post-logout URI:
     * the hinted client's own if a valid `id_token_hint` is given,
     * otherwise any active client's.
     *
     * Signing out happens at once when nobody is signed in, when the
     * hint is a valid ID token for the signed-in user, or when the user
     * has confirmed (with their sesskey). Otherwise the user is asked
     * first, so a link on another site can't sign people out of the hub.
     *
     * @param array $params The request parameters (query string, or the confirmation form).
     * @param \stdClass $user The session user ($USER).
     * @param bool $loggedinas True if this is a "Log in as" session.
     * @param bool $confirmed True if the user confirmed with a valid sesskey.
     * @return array{logout: bool, confirm: bool, redirect: ?\moodle_url, params: array}
     *      `params` are the checked parameters to carry through a confirmation.
     */
    public static function end_session(array $params, \stdClass $user, bool $loggedinas, bool $confirmed): array {
        $uri = self::param($params, 'post_logout_redirect_uri') ?? '';
        $state = self::param($params, 'state') ?? '';
        if ($state !== '' && !preg_match(self::STATE_PATTERN, $state)) {
            $state = '';
        }
        $hint = self::param($params, 'id_token_hint') ?? '';
        $hinted = $hint !== '' ? self::verify_id_token_hint($hint) : null;

        $target = null;
        if ($uri !== '') {
            if ($hinted) {
                $target = $uri === $hinted['postlogouturi'] ? $uri : null;
            } else if (client_registry::is_post_logout_uri($uri)) {
                $target = $uri;
            }
        }
        $redirect = null;
        $carry = [];
        if ($target !== null) {
            $redirect = new \moodle_url($target, $state !== '' ? ['state' => $state] : []);
            $carry['post_logout_redirect_uri'] = $target;
            if ($state !== '') {
                $carry['state'] = $state;
            }
            if ($hinted) {
                $carry['id_token_hint'] = $hint;
            }
        }

        $signedin = !empty($user->id) && !isguestuser($user);
        $ownsub = $signedin ? subjects::find_for_user((int) $user->id) : null;
        $logout = !$signedin
            || $confirmed
            || (!$loggedinas && $hinted && $ownsub !== null && hash_equals($ownsub, $hinted['sub']));

        return [
            'logout' => $logout,
            'confirm' => !$logout,
            'redirect' => $redirect,
            'params' => $carry,
        ];
    }

    /**
     * Check an ID token this hub issued, given back as a logout hint.
     *
     * The signature, `kid`, `iss` and `aud` must be good, and `sub` an
     * opaque subject (older tokens carrying a user id don't count). Expiry is
     * ignored: hints are usually older than the 5 minutes an ID token
     * lasts (OpenID Connect RP-Initiated Logout section 2).
     *
     * @param string $jwt
     * @return array{clientid: string, postlogouturi: string, sub: string}|null
     */
    public static function verify_id_token_hint(string $jwt): ?array {
        if ($jwt === '' || strlen($jwt) > self::MAX_HINT_LENGTH) {
            return null;
        }
        try {
            $keys = keys::verification_keys();
            if (!$keys) {
                return null;
            }
            try {
                $payload = JWT::decode($jwt, $keys);
            } catch (ExpiredException $e) {
                // Thrown only after the signature has been checked.
                $payload = $e->getPayload();
            }
        } catch (\Throwable $e) {
            return null;
        }
        if (
            ($payload->iss ?? null) !== self::issuer()
            || !isset($payload->aud) || !is_string($payload->aud)
            || !isset($payload->sub) || !is_string($payload->sub) || !preg_match(subjects::PATTERN, $payload->sub)
        ) {
            return null;
        }
        $client = client_registry::find_active($payload->aud);
        if (!$client) {
            return null;
        }
        return [
            'clientid' => (string) $client->clientid,
            'postlogouturi' => (string) $client->postlogouturi,
            'sub' => $payload->sub,
        ];
    }

    // Helpers.

    /**
     * Note an unexpected failure in the web server's error log.
     *
     * The client only ever gets `server_error`; the class and message go
     * to the log for whoever runs the hub.
     *
     * @param string $endpoint For example 'token'.
     * @param \Throwable $e
     * @return void
     */
    public static function log_failure(string $endpoint, \Throwable $e): void {
        // Always logged, whatever the debug level: sign-in failures need to be seen.
        // phpcs:ignore
        error_log('local_nucleushub OIDC ' . $endpoint . ': ' . get_class($e) . ': ' . $e->getMessage());
    }

    /**
     * A single string parameter.
     *
     * @param array $source $_GET, $_POST or a test array.
     * @param string $name
     * @return string|null Null if absent. A repeated (array) parameter reads as '', which every check refuses.
     */
    public static function param(array $source, string $name): ?string {
        if (!array_key_exists($name, $source)) {
            return null;
        }
        return is_string($source[$name]) ? $source[$name] : '';
    }

    /**
     * A user row read fresh from the database.
     *
     * @param int $userid
     * @return \stdClass|null
     */
    public static function load_user(int $userid): ?\stdClass {
        global $DB;
        if ($userid <= 0) {
            return null;
        }
        return $DB->get_record('user', ['id' => $userid]) ?: null;
    }
}
