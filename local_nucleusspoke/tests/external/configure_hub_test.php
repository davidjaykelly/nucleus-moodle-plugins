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

namespace local_nucleusspoke\external;

use core_external\external_api;
use local_nucleuscommon\transport\hub_http;

/**
 * Tests for local_nucleusspoke_configure_hub, and its optional hubissuer.
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(configure_hub::class)]
final class configure_hub_test extends \advanced_testcase {
    /** @var string The hub's issuer, pinned at its original address. */
    private const PINNED = 'https://acme-hub.n.example.com/local/nucleushub/oidc';

    /**
     * Call it as the web service layer does: validated parameters, the
     * optional ones left out unless given.
     *
     * @param array $params
     * @return array
     */
    private static function call(array $params): array {
        $params = external_api::validate_parameters(configure_hub::execute_parameters(), $params);
        $result = configure_hub::execute(...array_values($params));
        return external_api::clean_returnvalue(configure_hub::execute_returns(), $result);
    }

    /**
     * The spoke's hub settings.
     *
     * @return array
     */
    private static function settings(): array {
        $settings = [];
        foreach (['hubwwwroot', 'hubtoken', 'hubconnecturl', 'hubissuer'] as $name) {
            $settings[$name] = get_config('local_nucleusspoke', $name);
        }
        return $settings;
    }

    /**
     * Without hubissuer it stores what it always did, and the issuer is
     * the hub address's.
     */
    public function test_without_hubissuer_behaves_as_before(): void {
        $this->resetAfterTest();

        $this->assertTrue(self::call([
            'hubwwwroot' => 'https://hub.example.com/',
            'hubtoken' => 'token-1',
            'hubconnecturl' => 'http://hub.internal:8080/',
        ])['ok']);
        $this->assertSame([
            'hubwwwroot' => 'https://hub.example.com',
            'hubtoken' => 'token-1',
            'hubconnecturl' => 'http://hub.internal:8080',
            'hubissuer' => '',
        ], self::settings());
        $http = hub_http::from_spoke_config();
        $this->assertSame('https://hub.example.com/local/nucleushub/oidc', $http->issuer());
        $this->assertSame('http://hub.internal:8080/local/nucleushub/oidc/token.php',
            $http->internal_url('https://hub.example.com/local/nucleushub/oidc/token.php'));

        // Only the two required parameters: no connect address, as before.
        self::call(['hubwwwroot' => 'https://hub.example.com', 'hubtoken' => 'token-2']);
        $this->assertSame([
            'hubwwwroot' => 'https://hub.example.com',
            'hubtoken' => 'token-2',
            'hubconnecturl' => '',
            'hubissuer' => '',
        ], self::settings());
        $this->assertSame('https://hub.example.com/local/nucleushub/oidc', hub_http::from_spoke_config()->issuer());
    }

    /**
     * hubissuer is stored without a trailing slash and becomes the issuer,
     * whatever the hub's address now is.
     */
    public function test_hubissuer_is_stored_and_used(): void {
        $this->resetAfterTest();

        self::call([
            'hubwwwroot' => 'https://training.example.org',
            'hubtoken' => 'token-1',
            'hubconnecturl' => 'http://hub.internal:8080',
            'hubissuer' => self::PINNED . '/',
        ]);
        $this->assertSame(self::PINNED, get_config('local_nucleusspoke', 'hubissuer'));
        $this->assertSame('https://training.example.org', get_config('local_nucleusspoke', 'hubwwwroot'));
        $http = hub_http::from_spoke_config();
        $this->assertSame(self::PINNED, $http->issuer());
        $this->assertSame('http://hub.internal:8080/local/nucleushub/oidc/token.php',
            $http->internal_url(self::PINNED . '/token.php'));
        $this->assertSame(['Host: training.example.org'], $http->host_headers());
    }

    /**
     * Every call sets hubissuer, as it does hubconnecturl: leaving it out
     * goes back to the hub address's issuer.
     */
    public function test_call_without_hubissuer_clears_it(): void {
        $this->resetAfterTest();

        self::call(['hubwwwroot' => 'https://hub.example.com', 'hubtoken' => 't', 'hubissuer' => self::PINNED]);
        $this->assertSame(self::PINNED, hub_http::from_spoke_config()->issuer());

        self::call(['hubwwwroot' => 'https://hub.example.com', 'hubtoken' => 't']);
        $this->assertSame('', get_config('local_nucleusspoke', 'hubissuer'));
        $this->assertSame('https://hub.example.com/local/nucleushub/oidc', hub_http::from_spoke_config()->issuer());
    }

    /**
     * Anything that isn't a hub issuer URL is refused, and then nothing
     * is changed.
     */
    public function test_bad_hubissuer_is_refused_and_changes_nothing(): void {
        $this->resetAfterTest();
        self::call([
            'hubwwwroot' => 'https://hub.example.com',
            'hubtoken' => 'token-1',
            'hubconnecturl' => 'http://hub.internal',
            'hubissuer' => self::PINNED,
        ]);
        $before = self::settings();

        foreach ([
            'https://acme-hub.n.example.com',
            'https://acme-hub.n.example.com/local/other',
            'https://acme-hub.n.example.com/local/nucleushub/oidc/token.php',
            'https://acme-hub.n.example.com/xlocal/nucleushub/oidc',
            'https://acme-hub.n.example.com/local/nucleushub/oidc?x=1',
            'https://acme-hub.n.example.com/local/nucleushub/oidc#x',
            'https://user:pass@acme-hub.n.example.com/local/nucleushub/oidc',
            'ftp://acme-hub.n.example.com/local/nucleushub/oidc',
            '/local/nucleushub/oidc',
            'acme-hub.n.example.com/local/nucleushub/oidc',
        ] as $issuer) {
            try {
                self::call([
                    'hubwwwroot' => 'https://other.example.com',
                    'hubtoken' => 'token-2',
                    'hubissuer' => $issuer,
                ]);
                $this->fail('Accepted ' . $issuer);
            } catch (\invalid_parameter_exception $e) {
                $this->assertSame($before, self::settings(), $issuer);
            }
        }
    }
}
