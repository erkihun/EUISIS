<?php

declare(strict_types=1);

namespace App\Enums\Transport;

/**
 * The statement the city computes from evidence (docs/transport/reconciliation.md).
 *
 *   draft ─submit for review→ under_review ─approve (different person)→ approved ─payment reference→ paid
 *     │                           ├─reject→ rejected
 *     └─cancel───────────────────┴─────────→ cancelled
 *
 * A draft may be recomputed; nothing after review is recomputed, only
 * adjusted through approved adjustments.
 */
enum TransportSettlementStatus: string
{
    case Draft = 'draft';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** @return array<int, self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::UnderReview, self::Cancelled],
            self::UnderReview => [self::Approved, self::Rejected, self::Draft, self::Cancelled],
            self::Approved => [self::Paid],
            self::Paid, self::Rejected, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Approved, self::Paid, self::Rejected, self::Cancelled], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
