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

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

/**
 * Tests for the token endpoint logic: every refusal path, and the tokens issued.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(tokens::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(client_registry::class)]
final class token_test extends \advanced_testcase {
    /** @var string A valid PKCE verifier. */
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    /** @var string The form media type. */
    private const FORM = 'application/x-www-form-urlencoded';

    /**
     * The generator.
     *
     * @return \local_nucleushub_generator
     */
    private function generator(): \local_nucleushub_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_nucleushub');
    }

    /**
     * A client, a user and a fresh code for them.
     *
     * @return array{registered: array, user: \stdClass, code: string}
     */
    private function setup_code(): array {
        $registered = $this->generator()->create_oidc_client();
        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'email' => 'ada@example.com',
            'lang' => 'en',
        ]);
        $code = $this->generator()->create_code($registered['client'], (int) $user->id, self::VERIFIER, 'nonce-abc');
        return ['registered' => $registered, 'user' => $user, 'code' => $code];
    }

    /**
     * A good client_secret_post token request.
     *
     * @param array $setup From setup_code().
     * @param array $overrides Values to change; null removes a parameter.
     * @return array
     */
    private function post(array $setup, array $overrides = []): array {
        $post = [
            'grant_type' => 'authorization_code',
            'code' => $setup['code'],
            'redirect_uri' => $setup['registered']['client']->redirecturi,
            'code_verifier' => self::VERIFIER,
            'client_id' => $setup['registered']['clientid'],
            'client_secret' => $setup['registered']['secret'],
        ];
        foreach ($overrides as $name => $value) {
            if ($value === null) {
                unset($post[$name]);
            } else {
                $post[$name] = $value;
            }
        }
        return $post;
    }

    /**
     * An HTTP Basic header for a client.
     *
     * @param string $id
     * @param string $secret
     * @return string
     */
    private function basic(string $id, string $secret): string {
        return 'Basic ' . base64_encode(urlencode($id) . ':' . urlencode($secret));
    }

    /**
     * Check a response is a non-cacheable error.
     *
     * @param response $response
     * @param int $status
     * @param string $error
     */
    private function assert_error(response $response, int $status, string $error): void {
        $this->assertSame($status, $response->status);
        $this->assertSame($error, $response->body['error']);
        $this->assertArrayNotHasKey('access_token', $response->body);
        $this->assertArrayNotHasKey('id_token', $response->body);
        $this->assertSame('no-store', $response->headers['Cache-Control']);
        $this->assertSame('no-cache', $response->headers['Pragma']);
    }

    /**
     * A good exchange with client_secret_post: tokens, headers, claims, event,
     * and the code is gone.
     */
    public function test_success_client_secret_post(): void {
        global $DB;
        $this->resetAfterTest();
        $setup = $this->setup_code();
        $sink = $this->redirectEvents();

        $response = provider::token('POST', self::FORM, $this->post($setup), null);

        $this->assertSame(200, $response->status);
        $this->assertSame('no-store', $response->headers['Cache-Control']);
        $this->assertSame('no-cache', $response->headers['Pragma']);
        $this->assertSame('Bearer', $response->body['token_type']);
        $this->assertSame(300, $response->body['expires_in']);
        $this->assertMatchesRegularExpression(tokens::TOKEN_PATTERN, $response->body['access_token']);
        $this->assertSame(0, $DB->count_records(tokens::CODE_TABLE));

        // The access token is stored only as a hash.
        $this->assertFalse($DB->record_exists(tokens::TOKEN_TABLE, ['tokenhash' => $response->body['access_token']]));
        $row = $DB->get_record(
            tokens::TOKEN_TABLE,
            ['tokenhash' => hash('sha256', $response->body['access_token'])],
            '*',
            MUST_EXIST
        );
        $this->assertEquals($setup['user']->id, $row->userid);
        $this->assertLessThanOrEqual(time() + 300, (int) $row->expires);

        // The ID token verifies against the published JWKS.
        $claims = JWT::decode($response->body['id_token'], JWK::parseKeySet(keys::jwks()));
        [$header] = explode('.', $response->body['id_token']);
        $header = json_decode(JWT::urlsafeB64Decode($header));
        $this->assertSame('RS256', $header->alg);
        $this->assertSame(keys::current()['kid'], $header->kid);
        $this->assertSame(provider::issuer(), $claims->iss);
        // sub is the opaque subject, never the user id.
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $claims->sub);
        $this->assertSame(subjects::find_for_user((int) $setup['user']->id), $claims->sub);
        $this->assertNotSame((string) $setup['user']->id, $claims->sub);
        $this->assertSame($setup['registered']['clientid'], $claims->aud);
        $this->assertSame('nonce-abc', $claims->nonce);
        $this->assertLessThanOrEqual(time() + 300, $claims->exp);
        $this->assertGreaterThan(time() + 290, $claims->exp);
        $this->assertIsInt($claims->iat);
        $this->assertIsInt($claims->auth_time);
        $this->assertSame('ada@example.com', $claims->email);
        $this->assertTrue($claims->email_verified);
        $this->assertSame('Ada', $claims->given_name);
        $this->assertSame('Lovelace', $claims->family_name);
        $this->assertSame('Ada Lovelace', $claims->name);
        $this->assertSame('en', $claims->locale);

        // One sign-in event, for the person, naming the spoke.
        $events = array_values(array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \local_nucleushub\event\user_signed_in_to_spoke
        ));
        $this->assertCount(1, $events);
        $this->assertEquals($setup['user']->id, $events[0]->userid);
        $this->assertSame($setup['registered']['clientid'], $events[0]->other['clientid']);
        $this->assertSame($setup['registered']['spoke']->wwwroot, $events[0]->other['spoke']);
        $sink->close();
    }

    /**
     * A good exchange with client_secret_basic.
     */
    public function test_success_client_secret_basic(): void {
        $this->resetAfterTest();
        $setup = $this->setup_code();
        $post = $this->post($setup, ['client_id' => null, 'client_secret' => null]);
        $basic = $this->basic($setup['registered']['clientid'], $setup['registered']['secret']);

        $response = provider::token('POST', self::FORM, $post, $basic);
        $this->assertSame(200, $response->status);
        $this->assertNotEmpty($response->body['id_token']);
    }

    /**
     * client_id alongside Basic credentials is fine when it matches.
     */
    public function test_basic_with_matching_client_id(): void {
        $this->resetAfterTest();
        $setup = $this->setup_code();
        $post = $this->post($setup, ['client_secret' => null]);
        $basic = $this->basic($setup['registered']['clientid'], $setup['registered']['secret']);
        $this->assertSame(200, provider::token('POST', self::FORM, $post, $basic)->status);
    }

    /**
     * Only POST.
     *
     * @return array
     */
    public static function method_provider(): array {
        return [['GET'], ['PUT'], ['HEAD'], ['']];
    }

    /**
     * Anything but POST is refused, and the code survives.
     *
     * @param string $method
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('method_provider')]
    public function test_not_post_is_refused(string $method): void {
        global $DB;
        $this->resetAfterTest();
        $setup = $this->setup_code();
        $this->assert_error(provider::token($method, self::FORM, $this->post($setup), null), 400, 'invalid_request');
        $this->assertSame(1, $DB->count_records(tokens::CODE_TABLE));
    }

    /**
     * Only a form-encoded body.
     */
    public function test_wrong_content_type_is_refused(): void {
        $this->resetAfterTest();
        $setup = $this->setup_code();
        foreach (['application/json', 'multipart/form-data', ''] as $type) {
            $this->assert_error(provider::token('POST', $type, $this->post($setup), null), 400, 'invalid_request');
        }
    }

    /**
     * Client authentication failures.
     *
     * @return array
     */
    public static function bad_client_provider(): array {
        return [
            'no credentials' => [['client_id' => null, 'client_secret' => null], null, false],
            'client_id only' => [['client_secret' => null], null, false],
            'wrong secret' => [['client_secret' => 'wrong'], null, false],
            'empty secret' => [['client_secret' => ''], null, false],
            'unknown client' => [['client_id' => 'nucleus-unknown'], null, false],
            'repeated secret' => [['client_secret' => ['a', 'b']], null, false],
            'basic with wrong secret' => [['client_id' => null, 'client_secret' => null], 'WRONGSECRET', true],
            'basic unknown client' => [['client_id' => null, 'client_secret' => null], 'UNKNOWN', true],
            'basic not base64' => [['client_id' => null, 'client_secret' => null], 'Basic !!!', true],
            'basic without colon' => [['client_id' => null, 'client_secret' => null], 'NOCOLON', true],
        ];
    }

    /**
     * Bad client credentials get 401 invalid_client, and the code survives.
     *
     * @param array $overrides
     * @param string|null $authorization
     * @param bool $challenge Whether a Basic challenge is expected.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_client_provider')]
    public function test_bad_client_authentication(array $overrides, ?string $authorization, bool $challenge): void {
        global $DB;
        $this->resetAfterTest();
        $setup = $this->setup_code();
        $id = $setup['registered']['clientid'];
        $authorization = match ($authorization) {
            'WRONGSECRET' => $this->basic($id, 'wrong'),
            'UNKNOWN' => $this->basic('nucleus-unknown', $setup['registered']['secret']),
            'NOCOLON' => 'Basic ' . base64_encode($id),
            default => $authorization,
        };

        $response = provider::token('POST', self::FORM, $this->post($setup, $overrides), $authorization);
        $this->assert_error($response, 401, 'invalid_client');
        if ($challenge) {
            $this->assertStringStartsWith('Basic', $response->headers['WWW-Authenticate']);
        }
        $this->assertSame(1, $DB->count_records(tokens::CODE_TABLE));
    }

    /**
     * Using two authentication methods at once is refused.
     */
    public function test_two_auth_methods_are_refused(): void {
        $this->resetAfterTest();
        $setup = $this->setup_code();
        $basic = $this->basic($setup['registered']['clientid'], $setup['registered']['secret']);
        $this->assert_error(provider::token('POST', self::FORM, $this->post($setup), $basic), 400, 'invalid_request');
    }

    /**
     * A client_id in the body that differs from the Basic credentials is refused.
     */
    public function test_basic_with_other_client_id_is_refused(): void {
        $this->resetAfterTest();
        $setup = $this->setup_code();
        $post = $this->post($setup, ['client_id' => 'nucleus-other', 'client_secret' => null]);
        $basic = $this->basic($setup['registered']['clientid'], $setup['registered']['secret']);
        $this->assert_error(provider::token('POST', self::FORM, $post, $basic), 400, 'invalid_request');
    }

    /**
     * A client whose spoke is no longer active can't authenticate.
     */
    public function test_inactive_spoke_client_is_refused(): void {
        $this->resetAfterTest();
        $setup = $this->setup_code();
        $this->generator()->set_spoke_status($setup['registered']['spoke'], 'suspended');
        $this->assert_error(provider::token('POST', self::FORM, $this->post($setup), null), 401, 'invalid_client');
    }

    /**
     * A secret replaced by registering again stops working.
     */
    public function test_old_secret_is_refused_after_new_registration(): void {
        $this->resetAfterTest();
        $setup = $this->setup_code();
        $again = client_registry::register($setup['registered']['spoke']->cpspokeid);
        $this->assertSame($setup['registered']['clientid'], $again['clientid']);
        $this->assertNotSame($setup['registered']['secret'], $again['clientsecret']);

        $this->assert_error(provider::token('POST', self::FORM, $this->post($setup), null), 401, 'invalid_client');
        $response = provider::token('POST', self::FORM, $this->post($setup, ['client_secret' => $again['clientsecret']]), null);
        $this->assertSame(200, $response->status);
    }

    /**
     * Grant types other than authorization_code.
     *
     * @return array
     */
    public static function grant_type_provider(): array {
        return [
            'missing' => [null, 'invalid_request'],
            'empty' => ['', 'invalid_request'],
            'client_credentials' => ['client_credentials', 'unsupported_grant_type'],
            'password' => ['password', 'unsupported_grant_type'],
            'refresh_token' => ['refresh_token', 'unsupported_grant_type'],
            'implicit' => ['implicit', 'unsupported_grant_type'],
            'device code' => ['urn:ietf:params:oauth:grant-type:device_code', 'unsupported_grant_type'],
        ];
    }

    /**
     * Only authorization_code is accepted.
     *
     * @param string|null $granttype
     * @param string $error
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('grant_type_provider')]
    public function test_other_grant_types_are_refused(?string $granttype, string $error): void {
        $this->resetAfterTest();
        $setup = $this->setup_code();
        $response = provider::token('POST', self::FORM, $this->post($setup, ['grant_type' => $granttype]), null);
        $this->assert_error($response, 400, $error);
    }

    /**
     * Missing or malformed parameters.
     *
     * @return array
     */
    public static function missing_param_provider(): array {
        return [
            'no code' => [['code' => null]],
            'no redirect_uri' => [['redirect_uri' => null]],
            'no code_verifier' => [['code_verifier' => null]],
            'short code_verifier' => [['code_verifier' => str_repeat('a', 42)]],
            'long code_verifier' => [['code_verifier' => str_repeat('a', 129)]],
            'code_verifier with a bad character' => [['code_verifier' => str_repeat('a', 42) . '+']],
            'repeated code' => [['code' => ['a', 'b']]],
        ];
    }

    /**
     * Missing or malformed parameters are invalid_request, and the code survives.
     *
     * @param array $overrides
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('missing_param_provider')]
    public function test_missing_parameters_are_refused(array $overrides): void {
        global $DB;
        $this->resetAfterTest();
        $setup = $this->setup_code();
        $response = provider::token('POST', self::FORM, $this->post($setup, $overrides), null);
        $this->assert_error($response, 400, 'invalid_request');
        $this->assertSame(1, $DB->count_records(tokens::CODE_TABLE));
    }

    /**
     * An unknown or malformed code is invalid_grant.
     */
    public function test_unknown_code_is_refused(): void {
        $this->resetAfterTest();
        $setup = $this->setup_code();
        foreach ([tokens::random(), 'short', hash('sha256', $setup['code'])] as $code) {
            $response = provider::token('POST', self::FORM, $this->post($setup, ['code' => $code]), null);
            $this->assert_error($response, 400, 'invalid_grant');
        }
    }

    /**
     * A code works once only.
     */
    public function test_code_is_single_use(): void {
        $this->resetAfterTest();
        $setup = $this->setup_code();
        $this->assertSame(200, provider::token('POST', self::FORM, $this->post($setup), null)->status);
        $this->assert_error(provider::token('POST', self::FORM, $this->post($setup), null), 400, 'invalid_grant');
    }

    /**
     * A code that has expired is refused, and gone.
     */
    public function test_expired_code_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $setup = $this->setup_code();
        $DB->set_field(tokens::CODE_TABLE, 'expires', time() - 1, []);
        $this->assert_error(provider::token('POST', self::FORM, $this->post($setup), null), 400, 'invalid_grant');
        $this->assertSame(0, $DB->count_records(tokens::CODE_TABLE));
    }

    /**
     * A code issued to another client is refused, and burned so its real
     * client can't use it either.
     */
    public function test_code_from_another_client_is_refused_and_burned(): void {
        global $DB;
        $this->resetAfterTest();
        $setup = $this->setup_code();
        $other = $this->generator()->create_oidc_client();
        $post = $this->post($setup, [
            'client_id' => $other['clientid'],
            'client_secret' => $other['secret'],
            'redirect_uri' => $other['client']->redirecturi,
        ]);
        $this->assert_error(provider::token('POST', self::FORM, $post, null), 400, 'invalid_grant');
        $this->assertSame(0, $DB->count_records(tokens::CODE_TABLE));
        $this->assert_error(provider::token('POST', self::FORM, $this->post($setup), null), 400, 'invalid_grant');
    }

    /**
     * The redirect URI must be the one the code was issued for.
     */
    public function test_wrong_redirect_uri_is_refused(): void {
        $this->resetAfterTest();
        $setup = $this->setup_code();
        $uri = $setup['registered']['client']->redirecturi;
        $response = provider::token('POST', self::FORM, $this->post($setup, ['redirect_uri' => $uri . '/']), null);
        $this->assert_error($response, 400, 'invalid_grant');
    }

    /**
     * The PKCE verifier must match the challenge.
     */
    public function test_wrong_code_verifier_is_refused(): void {
        $this->resetAfterTest();
        $setup = $this->setup_code();
        $response = provider::token('POST', self::FORM, $this->post($setup, ['code_verifier' => str_repeat('v', 43)]), null);
        $this->assert_error($response, 400, 'invalid_grant');
    }

    /**
     * The challenge itself is not a valid verifier (S256 only, never plain).
     */
    public function test_challenge_as_verifier_is_refused(): void {
        $this->resetAfterTest();
        $setup = $this->setup_code();
        $post = $this->post($setup, ['code_verifier' => tokens::pkce_challenge(self::VERIFIER)]);
        $this->assert_error(provider::token('POST', self::FORM, $post, null), 400, 'invalid_grant');
    }

    /**
     * Accounts that stopped being allowed between authorize and token.
     *
     * @return array
     */
    public static function user_changed_provider(): array {
        return [
            'suspended' => ['suspended', 1],
            'deleted' => ['deleted', 1],
            'unconfirmed' => ['confirmed', 0],
            'now nologin' => ['auth', 'nologin'],
            'now webservice' => ['auth', 'webservice'],
        ];
    }

    /**
     * The account is checked again when the code is exchanged.
     *
     * @param string $field
     * @param mixed $value
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('user_changed_provider')]
    public function test_user_no_longer_allowed_is_refused(string $field, $value): void {
        global $DB;
        $this->resetAfterTest();
        $setup = $this->setup_code();
        $DB->set_field('user', $field, $value, ['id' => $setup['user']->id]);
        $this->assert_error(provider::token('POST', self::FORM, $this->post($setup), null), 400, 'invalid_grant');
    }

    /**
     * Deleting the client deletes its codes, so they can't be exchanged.
     */
    public function test_deleted_client_codes_are_gone(): void {
        global $DB;
        $this->resetAfterTest();
        $setup = $this->setup_code();
        client_registry::delete($setup['registered']['spoke']->cpspokeid);
        $this->assertSame(0, $DB->count_records(tokens::CODE_TABLE));
        $this->assert_error(provider::token('POST', self::FORM, $this->post($setup), null), 401, 'invalid_client');
    }

    /**
     * Error bodies never carry internal detail.
     */
    public function test_errors_are_plain(): void {
        $this->resetAfterTest();
        $setup = $this->setup_code();
        $response = provider::token('POST', self::FORM, $this->post($setup, ['code' => tokens::random()]), null);
        $this->assertSame(['error', 'error_description'], array_keys($response->body));
        $this->assertStringNotContainsString('local_nucleushub', $response->body['error_description']);
    }
}
