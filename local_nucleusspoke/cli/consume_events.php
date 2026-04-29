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
 * Phase 0 one-shot federation event consumer.
 *
 * **DEPRECATED in production from Phase B1 Step 2 onwards.** The
 * primary path is now CP-mediated push: the control plane subscribes
 * to `completion.v1` on the shared stream and dispatches per-spoke
 * via `local_nucleusspoke_apply_completion`. That path comes with
 * BullMQ retry/DLQ, audit visibility, and avoids the consumer-group
 * `$` trap (group is owned by CP, not this CLI).
 *
 * This script is kept for emergency drains / dev poking only:
 *
 *   - `completion.v1`  → {@see completion_applier::apply()}
 *   - anything else    → logged and acked (so it doesn't wedge the group)
 *
 * Invocation:
 *
 *     php public/local/nucleusspoke/cli/consume_events.php
 *     php public/local/nucleusspoke/cli/consume_events.php --max=50 --wait=5
 *
 * Flags:
 *   --max=N    Max events to process in this invocation (default 100).
 *   --wait=S   Seconds to block waiting for new events if the initial
 *              non-blocking read returns empty (default 0 = no wait).
 *   --help
 *
 * @package    local_nucleusspoke
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognised) = cli_get_params(
    [
        'max'  => 100,
        'wait' => 0,
        'help' => false,
    ],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error('Unrecognised options: ' . implode(', ', $unrecognised));
}

if (!empty($options['help'])) {
    echo "Usage: php consume_events.php [--max=N] [--wait=SECONDS]\n";
    echo "  --max=N    Max events this run (default 100)\n";
    echo "  --wait=S   Seconds to block on empty stream (default 0)\n";
    exit(0);
}

$max = max(1, (int)$options['max']);
$waitms = max(0, (int)$options['wait']) * 1000;

$spokename = (string)(get_config('local_nucleusspoke', 'spokename') ?: 'default');
$groupname = 'nucleus:spoke:' . $spokename;

cli_writeln("consumer group: {$groupname}");

$consumer = new \local_nucleuscommon\events\consumer($groupname);

// Single read. Phase 0 is one-shot: drain up to $max events, exit. If more
// arrive after we read, the next invocation will see them. Avoids the
// phpredis/XREADGROUP trap where BLOCK 0 means "block forever" rather than
// "non-blocking" — we always pass a concrete wait or a small non-zero
// poll value.
$events = $consumer->read($max, $waitms);

$processed = 0;
$statuscounts = [];

foreach ($events as $e) {
    $type = (string)($e['envelope']['type'] ?? '');
    $id = (string)($e['envelope']['id'] ?? '');

    try {
        switch ($type) {
            case 'completion.v1':
                $status = \local_nucleusspoke\handler\completion_applier::apply($e['envelope']);
                break;
            default:
                debugging("Nucleus: ignoring unknown event type '{$type}'", DEBUG_DEVELOPER);
                $status = 'ignored_unknown_type';
                break;
        }
    } catch (\Throwable $err) {
        // Ack-and-log rather than wedge the consumer group.
        // Phase 1 adds a dead-letter stream.
        debugging('Nucleus: event handler threw for id=' . $id . ' type=' . $type .
            ': ' . $err->getMessage(), DEBUG_DEVELOPER);
        $status = 'handler_error';
    }

    $consumer->ack($e['stream_id']);
    $statuscounts[$status] = ($statuscounts[$status] ?? 0) + 1;
    cli_writeln("  stream_id={$e['stream_id']} type={$type} id={$id} → {$status}");
    $processed++;
}

cli_writeln("processed {$processed} events");
foreach ($statuscounts as $status => $count) {
    cli_writeln("  {$status}: {$count}");
}
