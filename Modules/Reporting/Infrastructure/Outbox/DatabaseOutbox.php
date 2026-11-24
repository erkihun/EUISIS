<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Outbox;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Reporting\Application\Ports\EventOutbox;
use Modules\Reporting\Contracts\Events\ReportApprovedV1;
use Modules\Reporting\Contracts\Events\ReportingDeadlineMissedV1;
use Modules\Reporting\Contracts\Events\ReportSubmittedV1;
use Modules\Reporting\Infrastructure\Jobs\RelayOutbox;
use Modules\Reporting\Infrastructure\Persistence\Models\OutboxMessage;
use Throwable;

/**
 * Transactional outbox.
 *
 * store() writes the event into reporting_outbox inside the caller's
 * transaction: the fact and its event commit together or not at all. A relay
 * job is dispatched after the commit; if the process dies before it runs, or
 * the queue loses it, the scheduled relay (institutional-reporting:run outbox) finds the
 * row and dispatches it. So delivery is AT LEAST once, never "exactly once":
 * a crash after dispatch and before the row is marked sends the event again,
 * which is why listeners must be idempotent on the event id (InboxGuard).
 *
 * A row that keeps failing is retried with a growing delay up to MAX_ATTEMPTS
 * and then left as "failed" for a person to look at (institutional-reporting:health).
 */
final readonly class DatabaseOutbox implements EventOutbox
{
    public const MAX_ATTEMPTS = 8;

    /** Public name => class. The stored name, not the class, is the contract with the stored rows. */
    private const EVENTS = [
        ReportSubmittedV1::NAME => ReportSubmittedV1::class,
        ReportApprovedV1::NAME => ReportApprovedV1::class,
        ReportingDeadlineMissedV1::NAME => ReportingDeadlineMissedV1::class,
    ];

    public function __construct(private Dispatcher $events) {}

    public function store(object $event): void
    {
        $name = array_search($event::class, self::EVENTS, true);
        if ($name === false) {
            throw new InvalidArgumentException($event::class.' is not a Reporting integration event.');
        }
        $payload = get_object_vars($event);
        $now = CarbonImmutable::now('UTC');
        OutboxMessage::query()->create([
            'event_id' => $payload['eventId'], 'event_type' => $name, 'event_version' => $event::VERSION, 'payload' => $payload,
            'occurred_at' => CarbonImmutable::parse($payload['occurredAt'])->utc(), 'status' => 'pending', 'available_at' => $now,
        ]);
        DB::afterCommit(static function (): void {
            try {
                RelayOutbox::dispatch();
            } catch (Throwable $e) {
                // The row is stored; the scheduled relay will deliver it.
                Log::warning('reporting: outbox relay could not be queued', ['error' => $e->getMessage()]);
            }
        });
    }

    /**
     * Dispatches the pending events that are due, oldest first.
     *
     * @return array{dispatched: int, failed: int}
     */
    public function relay(int $limit = 200): array
    {
        $stats = ['dispatched' => 0, 'failed' => 0];
        $ids = OutboxMessage::query()->where('status', 'pending')->where('available_at', '<=', CarbonImmutable::now('UTC'))
            ->orderBy('occurred_at')->orderBy('id')->limit($limit)->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$stats): void {
                // Claimed under a row lock, so two relays never dispatch the same row together.
                $message = OutboxMessage::query()->whereKey($id)->where('status', 'pending')->lockForUpdate()->first();
                if ($message === null) {
                    return;
                }
                try {
                    $class = self::EVENTS[$message->event_type] ?? throw new InvalidArgumentException("Unknown event {$message->event_type}");
                    $this->events->dispatch(new $class(...$message->payload));
                    $message->forceFill(['status' => 'dispatched', 'dispatched_at' => CarbonImmutable::now('UTC'), 'attempts' => $message->attempts + 1, 'last_error' => null])->save();
                    $stats['dispatched']++;
                } catch (Throwable $e) {
                    $attempts = $message->attempts + 1;
                    $message->forceFill([
                        'attempts' => $attempts, 'last_error' => mb_substr($e->getMessage(), 0, 2000),
                        'status' => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
                        'available_at' => CarbonImmutable::now('UTC')->addMinutes(min(60, 2 ** $attempts)),
                    ])->save();
                    $stats['failed']++;
                }
            });
        }

        return $stats;
    }
}
