<?php

declare(strict_types=1);

namespace App\Services\Communication;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Realtime is an improvement, never the source of truth (docs/communication-module.md
 * §Realtime). A message is committed before anything is broadcast, and a
 * broadcast that fails (Reverb down, queue full) is counted and logged without
 * content — the sender's request still succeeds and clients catch up over HTTP.
 */
final class CommunicationBroadcaster
{
    public const FAILURE_METRIC = 'communication:metrics:broadcast_failures';

    public function send(object $event): void
    {
        // `log` counts as realtime off everywhere else (the pages poll); queuing jobs that only
        // write to the log would add a queue job per message for nothing.
        if (in_array(config('broadcasting.default'), [null, 'null', 'log'], true)) {
            return;
        }
        try {
            broadcast($event);
        } catch (Throwable $e) {
            Cache::increment(self::FAILURE_METRIC);
            Log::channel(config('logging.default'))->warning('communication.broadcast_failed', [
                'event' => class_basename($event),
                'error' => class_basename($e),
            ]);
        }
    }
}
