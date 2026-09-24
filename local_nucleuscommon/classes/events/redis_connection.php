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
 * Shared Redis connection + config access for the event transport.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleuscommon\events;

defined('MOODLE_INTERNAL') || die();

/**
 * Factory + config helper for ext-redis connections used by the event layer.
 *
 * Intentionally stateless: publishers open a short-lived connection per
 * event, on the request path. The control plane reads the stream.
 * Long-lived pooling is a Phase 1 concern.
 */
class redis_connection {

    /**
     * Open a connection using configured host/port. Short default timeout
     * because publishers live on the request path.
     *
     * @return \Redis
     * @throws \moodle_exception If ext-redis is unavailable or the connection fails.
     */
    public static function open(): \Redis {
        if (!extension_loaded('redis')) {
            throw new \moodle_exception('redismissing', 'local_nucleuscommon');
        }
        // Resolution order:
        //   1. MOODLE_EVENT_REDIS_HOST: the cluster's shared event Redis,
        //      set by the chart. Sessions live in a per-site Redis
        //      (MOODLE_REDIS_HOST), so the two must not be confused.
        //   2. MOODLE_REDIS_HOST (older charts and Kind dev, where one
        //      Redis did both).
        //   3. local_nucleuscommon/redishost plugin config (Phase 0:
        //      settings.php default).
        //   4. 'redis' — docker-compose service name (Phase 0 local).
        //
        // Env wins because it's the deployment-time signal; the plugin
        // config's default can't possibly know where Redis lives in a
        // given environment.
        $host = getenv('MOODLE_EVENT_REDIS_HOST')
             ?: getenv('MOODLE_REDIS_HOST')
             ?: get_config('local_nucleuscommon', 'redishost')
             ?: 'redis';
        $port = (int)(getenv('MOODLE_EVENT_REDIS_PORT')
                   ?: getenv('MOODLE_REDIS_PORT')
                   ?: get_config('local_nucleuscommon', 'redisport')
                   ?: 6379);
        $redis = new \Redis();
        if (!$redis->connect($host, $port, 2.0)) {
            throw new \moodle_exception('redisconnect', 'local_nucleuscommon', '', null,
                "unable to connect to redis at {$host}:{$port}");
        }
        // The site's own login on the shared event Redis. The control plane
        // creates it, allowed to do one thing: append to this site's stream
        // (stream_key()).
        $password = getenv('MOODLE_EVENT_REDIS_PASSWORD');
        if ($password) {
            $user = getenv('MOODLE_EVENT_REDIS_USER');
            if (!$redis->auth($user ? [$user, $password] : $password)) {
                throw new \moodle_exception('redisconnect', 'local_nucleuscommon', '', null,
                    "redis at {$host}:{$port} refused this site's credentials");
            }
        }
        return $redis;
    }

    /**
     * Name of the Redis stream key used for federation events.
     *
     * @return string
     */
    public static function stream_key(): string {
        // Each site has its own stream, named by the control plane
        // (nucleus:events:<namespace>:<release>). It is the only key the
        // site's Redis login may write to, and the control plane trusts
        // the stream, not the envelope, to say who sent an event.
        $key = getenv('MOODLE_EVENT_STREAM') ?: get_config('local_nucleuscommon', 'eventstream');
        return is_string($key) && $key !== '' ? $key : 'nucleus:events';
    }
}
