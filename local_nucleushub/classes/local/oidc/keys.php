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

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * The hub's ID token signing keys (ADR-023 section 3).
 *
 * Stored in the plugin config `oidc_keys` as a JSON list, current key
 * first. Each entry is `{kid, publicpem, privatepemenc, created}`, where
 * `privatepemenc` is the private key PEM encrypted with
 * `\core\encryption` (the site key in dataroot), so the database alone
 * never holds a usable private key.
 *
 * An RSA-2048 key is made the first time one is needed. Rotation
 * (`cli/oidc_rotate_key.php`) puts a new key first and keeps only the
 * one before it, so ID tokens signed just before a rotation still
 * verify against the JWKS.
 *
 * Only the current key's private half is ever decrypted, so a lost site
 * encryption key is fixed by rotating: the previous entry is then only
 * used for its public half.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class keys {
    /** @var string Plugin config name holding the key list. */
    public const CONFIG = 'oidc_keys';

    /** @var string The only signing algorithm. */
    public const ALG = 'RS256';

    /** @var int RSA modulus size. */
    public const BITS = 2048;

    /** @var int How many keys are kept: the current one and the one before it. */
    public const KEEP = 2;

    /** @var string Lock resource guarding key creation and rotation. */
    private const LOCK = 'oidc_keys';

    /**
     * Every stored key, current first. Never makes one.
     *
     * Read from the database rather than the config cache, so every web
     * node sees a rotation at once.
     *
     * @return array[] Entries `{kid, publicpem, privatepemenc, created}`.
     * @throws \moodle_exception If the stored list is damaged.
     */
    public static function all(): array {
        global $DB;

        $raw = $DB->get_field('config_plugins', 'value', ['plugin' => 'local_nucleushub', 'name' => self::CONFIG]);
        if ($raw === false || $raw === null || trim((string) $raw) === '') {
            return [];
        }
        $list = json_decode((string) $raw, true);
        if (!is_array($list) || !array_is_list($list)) {
            throw new \moodle_exception('oidc_keysdamaged', 'local_nucleushub');
        }
        foreach ($list as $entry) {
            if (
                !is_array($entry)
                || !isset($entry['kid'], $entry['publicpem'], $entry['privatepemenc'], $entry['created'])
                || !is_string($entry['kid']) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $entry['kid'])
                || !is_string($entry['publicpem']) || !is_string($entry['privatepemenc'])
            ) {
                throw new \moodle_exception('oidc_keysdamaged', 'local_nucleushub');
            }
        }
        return $list;
    }

    /**
     * The current signing key, made if there isn't one yet.
     *
     * @return array Entry `{kid, publicpem, privatepemenc, created}`.
     */
    public static function current(): array {
        $list = self::all();
        if ($list) {
            return $list[0];
        }

        $lock = self::lock();
        try {
            // Another request may have made it while this one waited.
            $list = self::all();
            if (!$list) {
                $list = [self::generate()];
                self::save($list);
            }
        } finally {
            $lock->release();
        }
        return $list[0];
    }

    /**
     * Make a new current key and keep only the one before it.
     *
     * @param bool $reset Drop every stored key instead of keeping the
     *      previous one. For a damaged key list; ID tokens signed with
     *      the dropped keys stop verifying.
     * @return array The new entry.
     */
    public static function rotate(bool $reset = false): array {
        $lock = self::lock();
        try {
            $list = $reset ? [] : self::all();
            $new = self::generate();
            array_unshift($list, $new);
            self::save(array_slice($list, 0, self::KEEP));
        } finally {
            $lock->release();
        }
        return $new;
    }

    /**
     * The public JSON Web Key Set: every kept key.
     *
     * @return array `{keys: [{kty, use, alg, kid, n, e}]}`
     */
    public static function jwks(): array {
        self::current();
        $keys = [];
        foreach (self::all() as $entry) {
            $keys[] = self::jwk($entry['kid'], $entry['publicpem']);
        }
        return ['keys' => $keys];
    }

    /**
     * Public keys by kid, for verifying the hub's own tokens.
     *
     * @return Key[] kid => key
     */
    public static function verification_keys(): array {
        $keys = [];
        foreach (self::all() as $entry) {
            $keys[$entry['kid']] = new Key($entry['publicpem'], self::ALG);
        }
        return $keys;
    }

    /**
     * Sign claims as a JWT with the current key.
     *
     * @param array $claims
     * @return string Compact JWS with `alg` RS256 and the key's `kid`.
     */
    public static function sign(array $claims): string {
        $entry = self::current();
        $pem = \core\encryption::decrypt($entry['privatepemenc']);
        $private = openssl_pkey_get_private($pem);
        if ($private === false) {
            throw new \moodle_exception('oidc_keysdamaged', 'local_nucleushub');
        }
        return JWT::encode($claims, $private, self::ALG, $entry['kid']);
    }

    /**
     * Make a new RSA key pair entry, private half encrypted.
     *
     * @return array Entry `{kid, publicpem, privatepemenc, created}`.
     * @throws \moodle_exception If OpenSSL can't make a key.
     */
    public static function generate(): array {
        $pair = self::generate_pair();
        return [
            'kid' => bin2hex(random_bytes(12)),
            'publicpem' => $pair['publicpem'],
            'privatepemenc' => \core\encryption::encrypt($pair['privatepem']),
            'created' => time(),
        ];
    }

    /**
     * Make a raw RSA-2048 key pair.
     *
     * @return array{publicpem: string, privatepem: string}
     * @throws \moodle_exception If OpenSSL can't make a key.
     */
    public static function generate_pair(): array {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => self::BITS,
            'digest_alg' => 'sha256',
        ]);
        if ($key === false) {
            throw new \moodle_exception('oidc_keyfailed', 'local_nucleushub');
        }
        $privatepem = '';
        if (!openssl_pkey_export($key, $privatepem)) {
            throw new \moodle_exception('oidc_keyfailed', 'local_nucleushub');
        }
        $details = openssl_pkey_get_details($key);
        if (!$details || empty($details['key']) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new \moodle_exception('oidc_keyfailed', 'local_nucleushub');
        }
        return ['publicpem' => $details['key'], 'privatepem' => $privatepem];
    }

    /**
     * The JWK for a public key.
     *
     * @param string $kid
     * @param string $publicpem
     * @return array `{kty, use, alg, kid, n, e}`
     * @throws \moodle_exception If the PEM isn't an RSA public key.
     */
    public static function jwk(string $kid, string $publicpem): array {
        $public = openssl_pkey_get_public($publicpem);
        $details = $public ? openssl_pkey_get_details($public) : false;
        if (!$details || empty($details['rsa']['n']) || empty($details['rsa']['e'])) {
            throw new \moodle_exception('oidc_keysdamaged', 'local_nucleushub');
        }
        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => self::ALG,
            'kid' => $kid,
            'n' => JWT::urlsafeB64Encode($details['rsa']['n']),
            'e' => JWT::urlsafeB64Encode($details['rsa']['e']),
        ];
    }

    /**
     * Save the key list.
     *
     * @param array[] $list
     * @return void
     */
    private static function save(array $list): void {
        set_config(self::CONFIG, json_encode(array_values($list), JSON_UNESCAPED_SLASHES), 'local_nucleushub');
    }

    /**
     * Take the lock that guards key creation and rotation.
     *
     * @return \core\lock\lock
     * @throws \moodle_exception If it can't be had within 30 seconds.
     */
    private static function lock(): \core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory('local_nucleushub');
        $lock = $factory->get_lock(self::LOCK, 30);
        if (!$lock) {
            throw new \moodle_exception('oidc_keyfailed', 'local_nucleushub');
        }
        return $lock;
    }
}
