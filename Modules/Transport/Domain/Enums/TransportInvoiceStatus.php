<?php

declare(strict_types=1);

namespace Modules\Transport\Domain\Enums;

/**
 * The provider's claim (docs/transport/settlement.md).
 *
 *   draft ─submit→ submitted ─statement computed→ under_review ─approve→ approved
 *     │               │                               ├─return→ returned ─resubmit→ submitted
 *     │               │                               └─dispute raised→ disputed ─resolved→ under_review
 *     └─withdraw──────┴─────────────────────────────────────────────────────────────→ withdrawn
 */
enum TransportInvoiceStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Returned = 'returned';
    case Disputed = 'disputed';
    case Approved = 'approved';
    case Withdrawn = 'withdrawn';

    /** @return array<int, self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Withdrawn],
            self::Submitted => [self::UnderReview, self::Returned, self::Withdrawn],
            self::UnderReview => [self::Approved, self::Returned, self::Disputed],
            self::Returned => [self::Submitted, self::Withdrawn],
            self::Disputed => [self::UnderReview, self::Approved, self::Returned],
            self::Approved, self::Withdrawn => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** The provider may still change the claim. */
    public function isProviderEditable(): bool
    {
        return in_array($this, [self::Draft, self::Returned], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
