<?php

declare(strict_types=1);

namespace App\Enums\Transport;

/**
 *   draft ─approve→ active ─new version approved→ superseded
 *     │                └─end→ ended
 *     └─cancel→ cancelled
 *
 * Only ACTIVE (and, for historic periods, SUPERSEDED/ENDED) contracts bind a
 * settlement; a statement snapshots the version it used.
 */
enum TransportContractStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Superseded = 'superseded';
    case Ended = 'ended';
    case Cancelled = 'cancelled';

    /** @return array<int, self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Cancelled],
            self::Active => [self::Superseded, self::Ended],
            self::Superseded, self::Ended, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** Versions whose terms may be applied to a period they cover. */
    public function isBinding(): bool
    {
        return in_array($this, [self::Active, self::Superseded, self::Ended], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
