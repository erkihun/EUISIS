<?php

declare(strict_types=1);

namespace Modules\DailyWork\Application\Services;

use Illuminate\Support\Carbon;
use Modules\DailyWork\Contracts\PerformanceItemSource;

/** No performance items to cite: the binding until the Performance module provides its own. */
final class NoPerformanceItems implements PerformanceItemSource
{
    public function agreedItems(string $employeeId, Carbon $date): array
    {
        return [];
    }

    public function citableItems(array $itemIds, string $employeeId, Carbon $date): array
    {
        return [];
    }

    public function traceOf(string $itemId): array
    {
        return [];
    }
}
