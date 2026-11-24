<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Outbox;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Reporting\Infrastructure\Persistence\Models\InboxMessage;

/**
 * Makes a consumer idempotent: the handler runs once per (consumer, event id).
 * The marker row and the handler's own writes share one transaction, so a
 * handler that fails leaves no marker and is retried; one that succeeded is
 * skipped when the event arrives again.
 */
final class InboxGuard
{
    /** @return bool whether the handler ran */
    public function once(string $consumer, string $eventId, Closure $handler): bool
    {
        try {
            DB::transaction(function () use ($consumer, $eventId, $handler): void {
                InboxMessage::query()->create(['consumer' => $consumer, 'event_id' => $eventId, 'processed_at' => CarbonImmutable::now('UTC')]);
                $handler();
            });

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
