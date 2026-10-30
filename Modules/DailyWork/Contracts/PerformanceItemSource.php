<?php

declare(strict_types=1);

namespace Modules\DailyWork\Contracts;

use Illuminate\Support\Carbon;

/**
 * Extension point (ADR 0017): the performance items an employee's daily work
 * may cite as evidence. Daily work defines it and the Performance module
 * implements it, so Daily work never depends on Performance. Without
 * Performance, nothing can be cited.
 */
interface PerformanceItemSource
{
    /**
     * The employee's current items in an agreement in force on the date, for
     * the daily-work form's KPI picker.
     *
     * @return list<array{id: string, name_en: string, name_am: string, unit: ?string}>
     */
    public function agreedItems(string $employeeId, Carbon $date): array;

    /**
     * Of the given item ids, those the employee may cite on the date, with the
     * KPI and objective each stands for.
     *
     * @param  list<string>  $itemIds
     * @return array<string, array{id: string, kpi_id: ?string, objective_id: ?string}> by item id
     */
    public function citableItems(array $itemIds, string $employeeId, Carbon $date): array;

    /**
     * The cascade from an item's objective up to its strategic goal, for the
     * daily log's detail.
     *
     * @return list<array<string, mixed>>
     */
    public function traceOf(string $itemId): array;
}
