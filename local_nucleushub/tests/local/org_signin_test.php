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

namespace local_nucleushub\local;

/**
 * Tests for the organisation sign-in input rules.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(org_signin::class)]
final class org_signin_test extends \advanced_testcase {
    /**
     * Tenants are lower-cased GUIDs or verified domains.
     */
    public function test_normalise_tenant(): void {
        $this->assertSame(
            '0f5c1a6e-2b3d-4c5e-8f90-a1b2c3d4e5f6',
            org_signin::normalise_tenant(' 0F5C1A6E-2B3D-4C5E-8F90-A1B2C3D4E5F6 ')
        );
        $this->assertSame('contoso.onmicrosoft.com', org_signin::normalise_tenant('Contoso.onmicrosoft.com'));
    }

    /**
     * Domains are lower-cased, de-duplicated and stripped of spaces and a leading @.
     */
    public function test_normalise_domains(): void {
        $this->assertSame(
            ['contoso.org', 'fabrikam.org'],
            org_signin::normalise_domains(' Contoso.org, @fabrikam.org ,contoso.org,, ')
        );
        $this->assertSame([], org_signin::normalise_domains(''));
    }

    /**
     * Discovery URLs become the base URL core appends the well-known path to.
     *
     * @return array
     */
    public static function discovery_url_provider(): array {
        return [
            'full URL' => ['https://idp.example.com/realms/c/.well-known/openid-configuration', 'https://idp.example.com/realms/c'],
            'trailing slash' => ['https://idp.example.com/', 'https://idp.example.com'],
            'port and upper case' => [
                'https://IDP.example.com:8443/x/.well-known/openid-configuration/',
                'https://idp.example.com:8443/x',
            ],
            'Okta style' => ['https://contoso.okta.com/oauth2/default', 'https://contoso.okta.com/oauth2/default'],
        ];
    }

    /**
     * Discovery URL normalisation.
     *
     * @param string $url
     * @param string $base
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('discovery_url_provider')]
    public function test_normalise_discovery_url(string $url, string $base): void {
        $this->assertSame($base, org_signin::normalise_discovery_url($url));
    }

    /**
     * The redirect URI is core's OAuth 2 callback on this hub.
     */
    public function test_redirect_uri(): void {
        global $CFG;
        $this->assertSame($CFG->wwwroot . '/admin/oauth2callback.php', org_signin::redirect_uri());
    }
}
