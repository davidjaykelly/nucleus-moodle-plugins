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

/**
 * A checked authorisation request.
 *
 * Only made once the client and its exact redirect URI are known good,
 * so anything in here may be sent back to that URI. If any other
 * parameter was wrong, `error` says how, and the request must go no
 * further than an error redirect.
 *
 * @package    local_nucleushub
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class authorize_request {
    /** @var \stdClass The active client, from {@see client_registry::find_active()}. */
    public readonly \stdClass $client;

    /** @var string The registered redirect URI, which the request matched exactly. */
    public readonly string $redirecturi;

    /** @var string State to send back, or '' if there was none (or it was unusable). */
    public readonly string $state;

    /** @var string Nonce for the ID token. */
    public readonly string $nonce;

    /** @var string PKCE S256 challenge. */
    public readonly string $codechallenge;

    /** @var string|null OAuth error code, or null if the request is good. */
    public readonly ?string $error;

    /** @var string Fixed description for the error. */
    public readonly string $errordescription;

    /**
     * Constructor.
     *
     * @param \stdClass $client
     * @param string $redirecturi
     * @param string $state
     * @param string $nonce
     * @param string $codechallenge
     * @param string|null $error
     * @param string $errordescription
     */
    public function __construct(
        \stdClass $client,
        string $redirecturi,
        string $state,
        string $nonce,
        string $codechallenge,
        ?string $error = null,
        string $errordescription = ''
    ) {
        $this->client = $client;
        $this->redirecturi = $redirecturi;
        $this->state = $state;
        $this->nonce = $nonce;
        $this->codechallenge = $codechallenge;
        $this->error = $error;
        $this->errordescription = $errordescription;
    }
}
