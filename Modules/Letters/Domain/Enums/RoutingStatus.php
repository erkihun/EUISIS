<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Enums;

/** A routing step: forwarded with an instruction, acknowledged, completed with an outcome, or returned. */
enum RoutingStatus: string
{
    case Pending = 'pending';
    case Acknowledged = 'acknowledged';
    case Completed = 'completed';
    case Returned = 'returned';

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Acknowledged;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
