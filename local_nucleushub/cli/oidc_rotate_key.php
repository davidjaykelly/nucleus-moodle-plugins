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
 * CLI: rotate the hub's ID token signing key (ADR-023 section 3).
 *
 * Makes a new RSA-2048 key, puts it first (every new ID token is signed
 * with it) and keeps only the previous key, which stays in the JWKS so
 * tokens signed just before the rotation still verify. Spokes fetch the
 * new key the first time they see its kid.
 *
 *     php public/local/nucleushub/cli/oidc_rotate_key.php [--reset]
 *
 * Also the fix when the current key can't be decrypted (for example
 * after the site's encryption key in dataroot was lost): only the
 * current key's private half is ever used, so the old one is then kept
 * for its public half alone.
 *
 * After a suspected key leak, use --reset. A plain rotation keeps the
 * leaked key in the JWKS as the previous key, so tokens forged with it
 * would still verify. Even after --reset, spokes keep their cached copy
 * of the JWKS for up to an hour, so a leaked key can still be accepted
 * for that long: turn sign in with the hub off for the federation (or
 * clear the spokes' caches) if that hour matters.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_nucleushub\local\oidc\keys;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(['help' => false, 'reset' => false], ['h' => 'help']);

if ($unrecognised) {
    cli_error('Unrecognised options: ' . implode(', ', array_keys($unrecognised)));
}

if ($options['help']) {
    cli_writeln(<<<USAGE
Rotate the signing key for sign in with the hub.

Makes a new current key and keeps only the previous one. ID tokens are
signed with the new key from now on; both keys stay in the JWKS.

Usage:
    php public/local/nucleushub/cli/oidc_rotate_key.php [--reset]

Options:
    --reset      Drop every stored key, including the previous one, and
                 publish only the new key. Use it after a suspected key
                 leak, or when the key list can't be read. ID tokens
                 signed before the reset stop verifying.
    -h, --help   Show this help and exit.

After a suspected key leak you must use --reset. A plain rotation keeps
the leaked key in the JWKS as the previous key, so tokens forged with it
still verify. Spokes also cache the JWKS for up to an hour, so even after
--reset they can accept the leaked key for up to an hour: turn sign in
with the hub off for the federation, or clear the spokes' caches, if
that matters.
USAGE);
    exit(0);
}

if (!$options['reset']) {
    try {
        keys::all();
    } catch (moodle_exception $e) {
        cli_error('The stored keys can\'t be read. Run again with --reset to replace them.', 2);
    }
}

$new = keys::rotate((bool) $options['reset']);
cli_writeln('New current key: ' . $new['kid']);
foreach (array_slice(keys::all(), 1) as $entry) {
    cli_writeln('Previous key kept: ' . $entry['kid']);
}
exit(0);
