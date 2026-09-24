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

use Firebase\JWT\JWK;
use Firebase\JWT\Key;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/authlib.php');

/**
 * The hub's public signing keys, cached for an hour.
 *
 * Only RSA signing keys are used, and each is bound to RS256 whatever
 * the key set says, so a token can't pick a weaker algorithm.
 *
 * @package    auth_nucleus
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class jwks {
    /** @var int Seconds a fetched key set is used for. */
    public const TTL = 3600;

    /** @var int Smallest RSA key accepted, in bits. */
    public const MIN_BITS = 2048;

    /** @var string The issuer the keys belong to (the cache key). */
    private string $issuer;

    /** @var callable Returns the JWKS document as an array. */
    private $fetcher;

    /**
     * Constructor.
     *
     * @param string $issuer The issuer the keys belong to.
     * @param callable|null $fetcher Returns the JWKS document; defaults to
     *                               fetching it from the hub. For tests.
     */
    public function __construct(string $issuer, ?callable $fetcher = null) {
        $this->issuer = $issuer;
        $this->fetcher = $fetcher ?? [hub::class, 'fetch_jwks'];
    }

    /**
     * The usable keys, by kid.
     *
     * @param bool $refresh Fetch afresh even if the cached set is current.
     * @return Key[] kid => Key (RS256).
     * @throws signin_exception If the keys can't be fetched.
     */
    public function keys(bool $refresh = false): array {
        $cache = \cache::make('auth_nucleus', 'jwks');
        $cachekey = sha1($this->issuer);

        $entry = $refresh ? false : $cache->get($cachekey);
        if (
            !is_array($entry)
            || !isset($entry['fetched'], $entry['jwks'])
            || !is_array($entry['jwks'])
            || time() - (int) $entry['fetched'] >= self::TTL
        ) {
            $document = ($this->fetcher)();
            if (!is_array($document)) {
                throw new signin_exception('hubunreachable', AUTH_LOGIN_FAILED, 'jwks endpoint returned no key set');
            }
            $entry = ['fetched' => time(), 'jwks' => $document];
            $cache->set($cachekey, $entry);
        }
        return self::parse($entry['jwks']);
    }

    /**
     * Turn a JWKS document into RS256 keys by kid, skipping anything else.
     *
     * @param array $document The JWKS document ({keys: [...]}).
     * @return Key[] kid => Key.
     */
    public static function parse(array $document): array {
        $keys = [];
        $list = $document['keys'] ?? [];
        if (!is_array($list)) {
            return [];
        }
        foreach ($list as $jwk) {
            if (!is_array($jwk) || ($jwk['kty'] ?? '') !== 'RSA') {
                continue;
            }
            if (isset($jwk['use']) && $jwk['use'] !== 'sig') {
                continue;
            }
            if (isset($jwk['alg']) && $jwk['alg'] !== 'RS256') {
                continue;
            }
            $kid = $jwk['kid'] ?? null;
            if (!is_string($kid) || $kid === '' || isset($keys[$kid])) {
                continue;
            }
            if (!is_string($jwk['n'] ?? null) || !is_string($jwk['e'] ?? null) || isset($jwk['d'])) {
                continue;
            }
            try {
                // Public parts only, bound to RS256.
                $key = JWK::parseKey(['kty' => 'RSA', 'n' => $jwk['n'], 'e' => $jwk['e'], 'alg' => 'RS256'], 'RS256');
            } catch (\Throwable $e) {
                continue;
            }
            if ($key === null || !self::strong_enough($key)) {
                continue;
            }
            $keys[$kid] = $key;
        }
        return $keys;
    }

    /**
     * Is this RSA key at least MIN_BITS long?
     *
     * @param Key $key
     * @return bool
     */
    private static function strong_enough(Key $key): bool {
        $material = $key->getKeyMaterial();
        $public = is_string($material) ? openssl_pkey_get_public($material) : $material;
        if ($public === false) {
            return false;
        }
        $details = openssl_pkey_get_details($public);
        return is_array($details)
            && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA
            && (int) ($details['bits'] ?? 0) >= self::MIN_BITS;
    }
}
