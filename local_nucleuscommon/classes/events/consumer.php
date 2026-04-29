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
 * Consumer for the federation event stream.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleuscommon\events;

defined('MOODLE_INTERNAL') || die();

/**
 * Reads envelopes written by {@see publisher} via a Redis consumer group.
 *
 * The consumer-group pattern gives us at-least-once delivery for free:
 * unacked entries stay in the group's pending list and are redelivered on
 * the next read. Callers must ack() after successfully applying each event.
 *
 * Phase 0 does not persist a "processed event ids" table — we rely on Redis
 * pending-entries semantics. Phase 1 will add a Moodle-side idempotency
 * table keyed on envelope `id` so that consumer restarts don't double-apply.
 */
class consumer {

    /** @var string Consumer group name (per-subscriber). */
    private string $group;

    /** @var string Consumer name inside the group. */
    private string $consumer;

    /**
     * @param string $group Group name, e.g. 'nucleus:spoke:default'.
     * @param string|null $consumer Consumer name; defaults to hostname+pid for uniqueness.
     */
    public function __construct(string $group, ?string $consumer = null) {
        $this->group = $group;
        $this->consumer = $consumer ?? gethostname() . ':' . getmypid();
    }

    /**
     * Read the next batch of events for this consumer group.
     *
     * Creates the group lazily on first read, starting at `$` (only
     * new entries — matches Phase 0 spike behaviour where we assume both
     * sides come up before any events are produced).
     *
     * @param int $count Max entries to return.
     * @param int $blockms Milliseconds to block waiting for new entries (0 = non-blocking).
     * @return array List of ['stream_id' => string, 'envelope' => array].
     */
    public function read(int $count = 10, int $blockms = 0): array {
        $redis = redis_connection::open();
        try {
            $stream = redis_connection::stream_key();
            self::ensure_group($redis, $stream, $this->group);

            $raw = $redis->xReadGroup(
                $this->group,
                $this->consumer,
                [$stream => '>'],
                $count,
                $blockms
            );

            $events = [];
            if (!is_array($raw) || !isset($raw[$stream])) {
                return $events;
            }
            foreach ($raw[$stream] as $streamid => $fields) {
                $envelope = isset($fields['data']) ? json_decode($fields['data'], true) : null;
                if (!is_array($envelope)) {
                    // Malformed — ack and skip so we don't loop on it.
                    $redis->xAck($stream, $this->group, [$streamid]);
                    continue;
                }
                $events[] = ['stream_id' => $streamid, 'envelope' => $envelope];
            }
            return $events;
        } finally {
            $redis->close();
        }
    }

    /**
     * Acknowledge successful handling of an event.
     *
     * @param string $streamid Redis stream id as returned in read().
     * @return void
     */
    public function ack(string $streamid): void {
        $redis = redis_connection::open();
        try {
            $redis->xAck(redis_connection::stream_key(), $this->group, [$streamid]);
        } finally {
            $redis->close();
        }
    }

    /**
     * Create the consumer group if it doesn't already exist. MKSTREAM covers
     * the case where nothing has been published yet.
     *
     * @param \Redis $redis Open connection.
     * @param string $stream Stream key.
     * @param string $group Group name.
     */
    private static function ensure_group(\Redis $redis, string $stream, string $group): void {
        try {
            $redis->xGroup('CREATE', $stream, $group, '$', true);
        } catch (\RedisException $e) {
            if (strpos($e->getMessage(), 'BUSYGROUP') === false) {
                throw $e;
            }
            // Group already exists — expected, ignore.
        }
    }
}
