<?php

declare(strict_types=1);

namespace Modules\Leave\Contracts\Data;

/**
 * Where a leave request stands. Published so reports outside the module name
 * the values instead of repeating the strings.
 */
enum LeaveStatus: string
{
    case PendingSupervisor = 'pending_supervisor';
    case PendingHr = 'pending_hr';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** Statuses that hold the requested days: nothing else may be asked for the same dates. */
    public const HOLDING = [self::PendingSupervisor, self::PendingHr, self::Approved];

    public const PENDING = [self::PendingSupervisor, self::PendingHr];

    public function isPending(): bool
    {
        return in_array($this, self::PENDING, true);
    }

    /** @return list<string> */
    public static function values(array $cases): array
    {
        return array_map(static fn (self $status): string => $status->value, $cases);
    }
}
