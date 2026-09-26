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

/**
 * Server-to-server requests from a spoke to its hub's sign-in provider.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleuscommon\transport;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Form POSTs and JSON GETs to the hub's sign-in provider (ADR-023).
 *
 * {@see hub_client} speaks Moodle's web service protocol. The hub's
 * OpenID Connect token and key endpoints are ordinary pages instead, so
 * this class calls them the same way hub_client calls the hub: over the
 * internal connect address when one is set, with the Host header the hub
 * expects, and verifying TLS whenever the connection is https.
 *
 * It is deliberately narrow. The issuer must be exactly the hub's: the
 * pinned issuer Nucleus gave this spoke (local_nucleusspoke hubissuer)
 * or, when there isn't one, {hub wwwroot}/local/nucleushub/oidc. The only
 * URLs it will request are token.php and jwks.php, named under that
 * issuer or under the hub's wwwroot, and always sent to the hub
 * connection (never to the issuer's host). Redirects are never followed.
 *
 * The issuer is pinned so a hub can move to a new address (a custom
 * domain) without changing it: spokes key their account links to it.
 */
class hub_http {

    /** @var string The issuer's path on the hub. */
    public const OIDC_PATH = '/local/nucleushub/oidc';

    /** @var string[] The only endpoints under the issuer this class calls. */
    public const ENDPOINTS = ['token.php', 'jwks.php'];

    /** @var string The hub's wwwroot, as the hub itself has it. */
    private string $wwwroot;

    /** @var string Where to connect: the wwwroot, or an internal address. */
    private string $connecturl;

    /** @var int Per-request timeout in seconds. */
    private int $timeout;

    /** @var string The hub's pinned issuer, or '' to work it out from the wwwroot. */
    private string $pinnedissuer;

    /**
     * Constructor.
     *
     * @param string $wwwroot The hub's wwwroot. Must match the hub's
     *                        $CFG->wwwroot exactly (it sets the Host header).
     * @param string|null $connecturl Optional internal address to connect to
     *                        instead, such as a cluster service name.
     * @param int $timeout Per-request timeout in seconds.
     * @param string|null $issuer The hub's pinned issuer, if Nucleus has
     *                        given one. Empty means {wwwroot}/local/nucleushub/oidc.
     */
    public function __construct(string $wwwroot, ?string $connecturl = null, int $timeout = 15,
            ?string $issuer = null) {
        $this->wwwroot = rtrim($wwwroot, '/');
        $this->connecturl = rtrim(($connecturl !== null && $connecturl !== '') ? $connecturl : $wwwroot, '/');
        $this->timeout = $timeout;
        $this->pinnedissuer = rtrim((string) $issuer, '/');
    }

    /**
     * Build one from this spoke's hub connection (local_nucleusspoke
     * settings hubwwwroot, hubconnecturl and hubissuer).
     *
     * @param int $timeout Per-request timeout in seconds.
     * @return self
     * @throws \moodle_exception If this site has no hub connection.
     */
    public static function from_spoke_config(int $timeout = 15): self {
        $wwwroot = (string) (get_config('local_nucleusspoke', 'hubwwwroot') ?: '');
        if ($wwwroot === '') {
            throw new \moodle_exception('huberror', 'local_nucleuscommon', '', 'no hub connection',
                'local_nucleusspoke/hubwwwroot is not set');
        }
        $connecturl = (string) (get_config('local_nucleusspoke', 'hubconnecturl') ?: '');
        $issuer = (string) (get_config('local_nucleusspoke', 'hubissuer') ?: '');
        return new self($wwwroot, $connecturl, $timeout, $issuer);
    }

    /**
     * The hub's issuer: the pinned one when Nucleus has given it,
     * otherwise {wwwroot}/local/nucleushub/oidc.
     *
     * @return string
     */
    public function issuer(): string {
        return $this->pinnedissuer !== '' ? $this->pinnedissuer : $this->wwwroot . self::OIDC_PATH;
    }

    /**
     * Is this exactly the hub's issuer?
     *
     * @param string $issuer
     * @return bool
     */
    public function is_issuer(string $issuer): bool {
        return $this->wwwroot !== '' && $issuer === $this->issuer();
    }

    /**
     * The public URL of one of the hub's sign-in endpoints on its current
     * address: {wwwroot}/local/nucleushub/oidc/{file}.
     *
     * This is where a browser is sent. Once the hub has moved it isn't
     * under the issuer, whose host is the hub's original address.
     *
     * @param string $file For example 'authorize.php'.
     * @return string
     */
    public function endpoint(string $file): string {
        return $this->wwwroot . self::OIDC_PATH . '/' . $file;
    }

    /**
     * Turn the public URL of an allowed endpoint into the URL to connect to.
     *
     * The URL may name the endpoint under the issuer (which, once the hub
     * has moved, is on its original address) or under the hub's wwwroot.
     * Either way the request goes to the hub connection, never to the
     * host in the URL, with the Host header taken from the wwwroot.
     *
     * @param string $url {issuer}/token.php or {issuer}/jwks.php, or the
     *                    same under {wwwroot}/local/nucleushub/oidc, exactly.
     * @return string {connect address}/local/nucleushub/oidc/{endpoint}.
     * @throws \moodle_exception For any other URL.
     */
    public function internal_url(string $url): string {
        if ($this->wwwroot !== '') {
            foreach (self::ENDPOINTS as $endpoint) {
                if ($url === $this->issuer() . '/' . $endpoint || $url === $this->endpoint($endpoint)) {
                    return $this->connecturl . self::OIDC_PATH . '/' . $endpoint;
                }
            }
        }
        throw new \moodle_exception('huberror', 'local_nucleuscommon', '', 'not a hub sign-in endpoint',
            'Refused a request to a URL that is not the hub issuer\'s token.php or jwks.php');
    }

    /**
     * POST an application/x-www-form-urlencoded body to the hub.
     *
     * Not retried: the request may not be safe to repeat (an OAuth code,
     * for example, is single use). HTTP error statuses are returned, not
     * thrown, so callers can read an error body.
     *
     * @param string $url {issuer}/token.php.
     * @param array $fields Form fields (name => scalar value).
     * @return array ['status' => int, 'body' => string, 'json' => array|null]
     * @throws \moodle_exception If the URL isn't allowed or the hub can't be reached.
     */
    public function post_form(string $url, array $fields): array {
        $body = http_build_query($fields, '', '&', PHP_QUERY_RFC1738);
        return $this->send('POST', $url, $body, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], 0);
    }

    /**
     * GET a hub endpoint that returns a JSON object.
     *
     * Retried once on a transport error.
     *
     * @param string $url {issuer}/jwks.php.
     * @return array The decoded JSON object.
     * @throws \moodle_exception If the URL isn't allowed, the hub can't be
     *                           reached, doesn't answer 200, or doesn't
     *                           return a JSON object.
     */
    public function get_json(string $url): array {
        $response = $this->send('GET', $url, null, ['Accept: application/json'], 1);
        if ($response['status'] !== 200) {
            throw new \moodle_exception('huberror', 'local_nucleuscommon', '', 'HTTP ' . $response['status'],
                'GET ' . $this->path_of($url) . ' returned HTTP ' . $response['status']);
        }
        if ($response['json'] === null) {
            throw new \moodle_exception('huberror', 'local_nucleuscommon', '', 'not JSON',
                'GET ' . $this->path_of($url) . ' did not return a JSON object');
        }
        return $response['json'];
    }

    /**
     * The curl options for a request to this connect URL.
     *
     * @param string $target The URL curl connects to.
     * @return array
     */
    public function curl_options(string $target): array {
        return array_merge([
            'CURLOPT_TIMEOUT' => $this->timeout,
            'CURLOPT_CONNECTTIMEOUT' => min(10, $this->timeout),
            'CURLOPT_FOLLOWLOCATION' => false,
        ], hub_client::tls_options($target));
    }

    /**
     * The Host header to send: the hub wwwroot's host, when connecting to
     * an internal address rather than the wwwroot itself. Always the
     * wwwroot's, never the issuer's, which is the hub's original address
     * once it has moved.
     *
     * @return string[] Nothing, or one 'Host: ...' header.
     */
    public function host_headers(): array {
        $hostheader = hub_client::host_header($this->wwwroot);
        if ($hostheader !== '' && $this->connecturl !== $this->wwwroot) {
            return ['Host: ' . $hostheader];
        }
        return [];
    }

    /**
     * Send one request, with the Host header when connecting internally.
     *
     * @param string $method 'GET' or 'POST'.
     * @param string $url Browser-facing hub URL.
     * @param string|null $body Raw request body for POST.
     * @param string[] $headers Extra request headers.
     * @param int $retries Retries on a transport error (never on an HTTP status).
     * @return array ['status' => int, 'body' => string, 'json' => array|null]
     * @throws \moodle_exception If the hub can't be reached after the retries.
     */
    private function send(string $method, string $url, ?string $body, array $headers, int $retries): array {
        $target = $this->internal_url($url);
        $headers = array_merge($headers, $this->host_headers());

        $attempt = 0;
        do {
            // Same reasoning as hub_client: the connect address is set by
            // Nucleus or the site admin, often a private address that
            // Moodle's curl security helper would block, and the URL is
            // always one of the two endpoints (checked above). TLS is
            // still verified whenever the target is https.
            $curl = new \curl(['ignoresecurity' => true]);
            $curl->setopt($this->curl_options($target));
            $curl->setHeader($headers);
            $response = $method === 'POST' ? $curl->post($target, (string) $body) : $curl->get($target);
            $info = $curl->get_info();
            $errno = (int) $curl->get_errno();

            if ($errno === 0 && !empty($info['http_code'])) {
                $decoded = json_decode((string) $response, true);
                return [
                    'status' => (int) $info['http_code'],
                    'body' => (string) $response,
                    'json' => is_array($decoded) ? $decoded : null,
                ];
            }
            $attempt++;
        } while ($attempt <= $retries);

        // Never include the request body: it can hold secrets.
        throw new \moodle_exception('huberror', 'local_nucleuscommon', '', 'connection failed',
            $method . ' ' . $this->path_of($url) . ' failed: curl_errno=' . $errno);
    }

    /**
     * The path of a hub URL, for error messages (no query string).
     *
     * @param string $url
     * @return string
     */
    private function path_of(string $url): string {
        return (string) (parse_url($url, PHP_URL_PATH) ?? '');
    }
}
