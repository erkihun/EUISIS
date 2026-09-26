<?php

declare(strict_types=1);

namespace App\Services\Cafeteria\Policy;

use Illuminate\Support\Carbon;

/**
 * What one scan may consume. `entitlements` are the exact ledger rows to
 * claim — each {date, slot, usage_type} is one unit of the employee's
 * organization entitlement, valid at every cafeteria in the network.
 */
final class CafeteriaEntitlementDecision
{
    public const MODE_ENTITLED = 'entitled';

    public const MODE_EMPLOYEE_PAID = 'employee_paid';

    /**
     * @param  list<array{date: string, slot: int, usage_type: string}>  $entitlements
     * @param  list<string>  $availableDates
     */
    public function __construct(
        public readonly bool $eligible,
        public readonly ?string $reason,
        public readonly ?string $message,
        public readonly string $mode,
        public readonly array $entitlements,
        public readonly array $availableDates,
        public readonly bool $isEntitlementDay,
        public readonly bool $isHoliday,
        public readonly bool $isExtraScan,
        public readonly int $scanSequence,
        public readonly ?Carbon $weekStart,
        public readonly ?Carbon $weekEnd,
    ) {}

    public static function deny(string $reason, ?string $message = null, ?Carbon $weekStart = null, ?Carbon $weekEnd = null): self
    {
        return new self(false, $reason, $message, self::MODE_ENTITLED, [], [], false, false, false, 0, $weekStart, $weekEnd);
    }

    public function isEmployeePaid(): bool
    {
        return $this->mode === self::MODE_EMPLOYEE_PAID;
    }

    /** @return list<string> */
    public function consumedDates(): array
    {
        return array_values(array_unique(array_column($this->entitlements, 'date')));
    }
}
