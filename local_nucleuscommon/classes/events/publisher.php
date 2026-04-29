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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * Publisher for the federation event stream.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleuscommon\events;

defined('MOODLE_INTERNAL') || die();

/**
 * Wraps Redis XADD with a stable envelope shape so every event on the
 * shared stream has the same outer structure:
 *
 *     {
 *       "type":       "<domain>.<version>",  e.g. "completion.v1"
 *       "id":         "<uuid-ish hex>",
 *       "source":     "hub" | "spoke:<id>",
 *       "destination":"hub" | "spoke:<id>" | "broadcast",
 *       "timestamp":  <unix seconds>,
 *       "payload":    { ... event-specific ... }
 *     }
 *
 * Keeping the envelope field names stable is what lets consumers on both
 * sides of the federation parse events without knowing every producer.
 */
class publisher {

    /**
     * Append an event to the configured stream. Returns both identifiers
     * the caller may need: the envelope id we minted (stable across stream
     * retention policies, usable as an idempotency key) and the Redis
     * stream id (monotonic within a stream, used for XACK).
     *
     * @param string $type Envelope type, e.g. 'completion.v1'.
     * @param string $source Logical sender — 'hub' or 'spoke:<spokeid>'.
     * @param string $destination 'hub', 'spoke:<spokeid>', or 'broadcast'.
     * @param array $payload Event-specific body.
     * @return array{envelope_id: string, stream_id: string}
     * @throws \moodle_exception If the publish fails.
     */
    public static function publish(string $type, string $source, string $destination, array $payload): array {
        $envelope = [
            'type' => $type,
            'id' => bin2hex(random_bytes(12)),
            'source' => $source,
            'destination' => $destination,
            'timestamp' => time(),
            'payload' => $payload,
        ];

        $redis = redis_connection::open();
        try {
            $streamid = $redis->xAdd(redis_connection::stream_key(), '*', [
                'data' => json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            if (!is_string($streamid) || $streamid === '') {
                throw new \moodle_exception('redispublish', 'local_nucleuscommon', '', null,
                    "XADD returned unexpected result for type={$type}");
            }
            return [
                'envelope_id' => $envelope['id'],
                'stream_id'   => $streamid,
            ];
        } finally {
            $redis->close();
        }
    }
}
