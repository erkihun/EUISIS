<?php

declare(strict_types=1);

namespace App\Enums\Transport;

/**
 * Lifecycle of one trip (docs/transport/routes-stops-trips.md).
 *
 *   planned ─assign vehicle→ assigned ─start→ boarding ─depart→ in_progress ─complete→ completed
 *      │                        │                │                  │
 *      └─cancel (reason)────────┴────────────────┴──────────────────┴─────────────→ cancelled
 *                                                                   └─fail (reason)→ failed
 *
 * Boarding is accepted while the trip is BOARDING or IN_PROGRESS (employees
 * join at intermediate stops). A planned or assigned trip must be started
 * first, so every boarding sits inside an activated trip context.
 */
enum TransportTripStatus: string
{
    case Planned = 'planned';
    case Assigned = 'assigned';
    case Boarding = 'boarding';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    /** @return array<int, self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Planned => [self::Assigned, self::Boarding, self::Cancelled],
            self::Assigned => [self::Boarding, self::Planned, self::Cancelled],
            self::Boarding => [self::InProgress, self::Completed, self::Cancelled, self::Failed],
            self::InProgress => [self::Completed, self::Failed, self::Cancelled],
            self::Completed, self::Cancelled, self::Failed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function isBoardable(): bool
    {
        return in_array($this, [self::Boarding, self::InProgress], true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /** Counts as service delivered for reports and settlement bases. */
    public function isDelivered(): bool
    {
        return $this === self::Completed;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    /** @return list<string> */
    public static function boardable(): array
    {
        return [self::Boarding->value, self::InProgress->value];
    }
}
