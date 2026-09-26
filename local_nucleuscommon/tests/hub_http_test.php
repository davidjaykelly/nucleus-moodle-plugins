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

namespace local_nucleuscommon;

use local_nucleuscommon\transport\hub_client;
use local_nucleuscommon\transport\hub_http;

/**
 * The hub transports: TLS verification, the exact issuer and the only
 * endpoints the sign-in transport may call.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_nucleuscommon\transport\hub_http
 * @covers     \local_nucleuscommon\transport\hub_client
 */
final class hub_http_test extends \advanced_testcase {

    /**
     * https requests verify the certificate and host name; http ones don't need to.
     */
    public function test_tls_is_verified_on_https(): void {
        $this->assertSame(['CURLOPT_SSL_VERIFYPEER' => 1, 'CURLOPT_SSL_VERIFYHOST' => 2],
            hub_client::tls_options('https://hub.example.com/webservice/rest/server.php'));
        $this->assertSame(['CURLOPT_SSL_VERIFYPEER' => 1, 'CURLOPT_SSL_VERIFYHOST' => 2],
            hub_client::tls_options('HTTPS://hub.example.com/'));
        $this->assertSame([], hub_client::tls_options('http://hub.nucleus-hub.svc.cluster.local/'));

        $http = new hub_http('https://hub.example.com');
        $options = $http->curl_options('https://hub.example.com/local/nucleushub/oidc/token.php');
        $this->assertSame(1, $options['CURLOPT_SSL_VERIFYPEER']);
        $this->assertSame(2, $options['CURLOPT_SSL_VERIFYHOST']);
        $this->assertFalse($options['CURLOPT_FOLLOWLOCATION']);

        $internal = new hub_http('https://hub.example.com', 'http://hub.internal');
        $this->assertArrayNotHasKey('CURLOPT_SSL_VERIFYPEER',
            $internal->curl_options('http://hub.internal/local/nucleushub/oidc/token.php'));
    }

    /**
     * The issuer must be exactly {wwwroot}/local/nucleushub/oidc.
     */
    public function test_issuer_must_be_exact(): void {
        $http = new hub_http('https://hub.example.com/');
        $this->assertSame('https://hub.example.com/local/nucleushub/oidc', $http->issuer());
        $this->assertTrue($http->is_issuer('https://hub.example.com/local/nucleushub/oidc'));
        $this->assertFalse($http->is_issuer('https://hub.example.com/local/nucleushub/oidc/'));
        $this->assertFalse($http->is_issuer('https://hub.example.com/local/other'));
        $this->assertFalse($http->is_issuer('https://hub.example.com'));
        $this->assertFalse($http->is_issuer('https://hub.example.com.evil.test/local/nucleushub/oidc'));
    }

    /**
     * Only token.php and jwks.php are called, on the connect address.
     */
    public function test_only_the_two_endpoints_are_called(): void {
        $http = new hub_http('https://hub.example.com', 'http://hub.internal:8080');
        $this->assertSame('http://hub.internal:8080/local/nucleushub/oidc/token.php',
            $http->internal_url('https://hub.example.com/local/nucleushub/oidc/token.php'));
        $this->assertSame('http://hub.internal:8080/local/nucleushub/oidc/jwks.php',
            $http->internal_url('https://hub.example.com/local/nucleushub/oidc/jwks.php'));

        foreach ([
            'https://hub.example.com/local/nucleushub/oidc/userinfo.php',
            'https://hub.example.com/webservice/rest/server.php',
            'https://hub.example.com/local/nucleushub/oidc/token.php?x=1',
            'https://hub.example.com/local/nucleushub/oidc/../oidc/token.php',
            'https://evil.test/local/nucleushub/oidc/token.php',
        ] as $url) {
            try {
                $http->internal_url($url);
                $this->fail('Allowed ' . $url);
            } catch (\moodle_exception $e) {
                $this->assertSame('huberror', $e->errorcode);
            }
        }
    }

    /**
     * Without a pinned issuer, the Host header comes from the wwwroot when
     * connecting internally, and isn't sent when connecting to the wwwroot.
     */
    public function test_host_header_only_for_internal_connections(): void {
        $this->assertSame(['Host: hub.example.com'],
            (new hub_http('https://hub.example.com', 'http://hub.internal:8080'))->host_headers());
        $this->assertSame(['Host: hub.example.com:8443'],
            (new hub_http('https://hub.example.com:8443', 'http://hub.internal'))->host_headers());
        $this->assertSame([], (new hub_http('https://hub.example.com'))->host_headers());
        $this->assertSame([], (new hub_http('https://hub.example.com', ''))->host_headers());
    }

    /**
     * A hub that has moved: the issuer is the pinned one, exactly, and
     * nothing else (not the new address's issuer) counts.
     */
    public function test_pinned_issuer_must_be_exact(): void {
        $pinned = 'https://acme-hub.n.example.com/local/nucleushub/oidc';
        $http = new hub_http('https://training.example.org', 'http://hub.internal:8080', 15, $pinned . '/');

        $this->assertSame($pinned, $http->issuer());
        $this->assertTrue($http->is_issuer($pinned));
        $this->assertFalse($http->is_issuer($pinned . '/'));
        $this->assertFalse($http->is_issuer('https://training.example.org/local/nucleushub/oidc'));
        $this->assertFalse($http->is_issuer('https://acme-hub.n.example.com'));
        $this->assertFalse($http->is_issuer('https://acme-hub.n.example.com.evil.test/local/nucleushub/oidc'));
        $this->assertFalse($http->is_issuer(''));

        // No hub address, no issuer, pinned or not.
        $this->assertFalse((new hub_http('', null, 15, $pinned))->is_issuer($pinned));

        // Empty means the wwwroot's, as before.
        $this->assertSame('https://training.example.org/local/nucleushub/oidc',
            (new hub_http('https://training.example.org', null, 15, ''))->issuer());
    }

    /**
     * A hub that has moved: token.php and jwks.php named under the pinned
     * issuer (the old host) go to the connect address with the new
     * address as Host. Named under the wwwroot they go to the same place,
     * as before. Anything else is refused.
     */
    public function test_pinned_issuer_endpoints_go_to_the_hub_connection(): void {
        $pinned = 'https://acme-hub.n.example.com/local/nucleushub/oidc';
        $http = new hub_http('https://training.example.org', 'http://hub.internal:8080', 15, $pinned);

        foreach (['token.php', 'jwks.php'] as $endpoint) {
            $this->assertSame('http://hub.internal:8080/local/nucleushub/oidc/' . $endpoint,
                $http->internal_url($pinned . '/' . $endpoint));
            $this->assertSame('http://hub.internal:8080/local/nucleushub/oidc/' . $endpoint,
                $http->internal_url('https://training.example.org/local/nucleushub/oidc/' . $endpoint));
        }
        $this->assertSame(['Host: training.example.org'], $http->host_headers());
        $this->assertSame('https://training.example.org/local/nucleushub/oidc/authorize.php',
            $http->endpoint('authorize.php'));

        foreach ([
            $pinned . '/userinfo.php',
            $pinned . '/authorize.php',
            $pinned . '/token.php?x=1',
            $pinned . '/../oidc/token.php',
            $pinned . '/token.php/',
            $pinned . 'token.php',
            'https://acme-hub.n.example.com/webservice/rest/server.php',
            'https://acme-hub.n.example.com/local/other/token.php',
            'https://acme-hub.n.example.com.evil.test/local/nucleushub/oidc/token.php',
            'https://training.example.org/local/nucleushub/oidc/userinfo.php',
            'https://evil.test/local/nucleushub/oidc/token.php',
        ] as $url) {
            try {
                $http->internal_url($url);
                $this->fail('Allowed ' . $url);
            } catch (\moodle_exception $e) {
                $this->assertSame('huberror', $e->errorcode);
            }
        }

        // Without a connect address, the request goes to the new address
        // itself, which needs no Host header.
        $direct = new hub_http('https://training.example.org', '', 15, $pinned);
        $this->assertSame('https://training.example.org/local/nucleushub/oidc/token.php',
            $direct->internal_url($pinned . '/token.php'));
        $this->assertSame([], $direct->host_headers());
    }

    /**
     * The spoke's settings: hubissuer when set, else the hubwwwroot's issuer.
     */
    public function test_from_spoke_config_reads_hubissuer(): void {
        $this->resetAfterTest();
        $pinned = 'https://acme-hub.n.example.com/local/nucleushub/oidc';
        set_config('hubwwwroot', 'https://training.example.org', 'local_nucleusspoke');
        set_config('hubconnecturl', 'http://hub.internal:8080', 'local_nucleusspoke');

        $this->assertSame('https://training.example.org/local/nucleushub/oidc', hub_http::from_spoke_config()->issuer());
        set_config('hubissuer', '', 'local_nucleusspoke');
        $this->assertSame('https://training.example.org/local/nucleushub/oidc', hub_http::from_spoke_config()->issuer());

        set_config('hubissuer', $pinned, 'local_nucleusspoke');
        $http = hub_http::from_spoke_config();
        $this->assertSame($pinned, $http->issuer());
        $this->assertTrue($http->is_issuer($pinned));
        $this->assertSame('http://hub.internal:8080/local/nucleushub/oidc/token.php',
            $http->internal_url($pinned . '/token.php'));
        $this->assertSame(['Host: training.example.org'], $http->host_headers());
    }
}
