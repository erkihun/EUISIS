<?php

declare(strict_types=1);

namespace Modules\DailyWork\Contracts;

use Illuminate\Support\Carbon;

/**
 * Quantities recorded in daily work against performance items (ADR 0017),
 * for the KPI actuals Performance derives from them.
 */
interface DailyWorkOutputs
{
    /**
     * The final (approved where the organization reviews, submitted where it
     * does not) daily items of the employee's assignment that cite one of the
     * performance items, in the period: how many, and their summed quantity.
     *
     * @param  list<string>  $performanceItemIds
     * @return array{count: int, quantity: string}
     */
    public function finalQuantity(array $performanceItemIds, string $employeeId, string $assignmentId, string $organizationId, Carbon $from, Carbon $to): array;
}
