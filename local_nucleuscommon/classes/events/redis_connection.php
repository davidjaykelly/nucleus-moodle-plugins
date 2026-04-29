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
 * Shared Redis connection + config access for the event transport.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_nucleuscommon\events;

defined('MOODLE_INTERNAL') || die();

/**
 * Factory + config helper for ext-redis connections used by the event layer.
 *
 * Intentionally stateless: callers open short-lived connections for CLI
 * consumers and web-request publishers alike. Long-lived pooling is a
 * Phase 1 concern.
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
        //   1. MOODLE_REDIS_HOST env var (Phase 1: set by the k8s
        //      Deployment alongside $CFG->session_redis_host).
        //   2. local_nucleuscommon/redishost plugin config (Phase 0:
        //      settings.php default).
        //   3. 'redis' — docker-compose service name (Phase 0 local).
        //
        // Env wins because it's the deployment-time signal; the plugin
        // config's default can't possibly know where Redis lives in a
        // given environment.
        $host = getenv('MOODLE_REDIS_HOST')
             ?: get_config('local_nucleuscommon', 'redishost')
             ?: 'redis';
        $port = (int)(getenv('MOODLE_REDIS_PORT')
                   ?: get_config('local_nucleuscommon', 'redisport')
                   ?: 6379);
        $redis = new \Redis();
        if (!$redis->connect($host, $port, 2.0)) {
            throw new \moodle_exception('redisconnect', 'local_nucleuscommon', '', null,
                "unable to connect to redis at {$host}:{$port}");
        }
        return $redis;
    }

    /**
     * Name of the Redis stream key used for federation events.
     *
     * @return string
     */
    public static function stream_key(): string {
        $key = get_config('local_nucleuscommon', 'eventstream');
        return is_string($key) && $key !== '' ? $key : 'nucleus:events';
    }
}
