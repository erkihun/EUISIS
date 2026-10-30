<?php

declare(strict_types=1);

namespace Modules\DailyWork\Application\Queries;

use Illuminate\Support\Carbon;
use Modules\DailyWork\Application\Services\DailyActivityReviewPolicyService;
use Modules\DailyWork\Contracts\DailyWorkOutputs;
use Modules\DailyWork\Domain\Models\DailyActivityItem;

/** Daily quantities against performance items (moved here from Performance's KPI actuals). */
final class DailyWorkOutputsQuery implements DailyWorkOutputs
{
    public function __construct(private readonly DailyActivityReviewPolicyService $reviewPolicy) {}

    public function finalQuantity(array $performanceItemIds, string $employeeId, string $assignmentId, string $organizationId, Carbon $from, Carbon $to): array
    {
        $query = DailyActivityItem::query()
            ->whereIn('employee_performance_item_id', $performanceItemIds)
            ->whereNotNull('quantity')
            ->whereHas('log', fn ($log) => $log->where('employee_id', $employeeId)
                ->where('employee_assignment_id', $assignmentId)
                // Approved where the organization reviews, submitted where it does not.
                ->where(fn ($final) => $this->reviewPolicy->constrainToFinal($final, $organizationId))
                ->whereBetween('activity_date', [$from->toDateString(), $to->toDateString()]));

        $count = (clone $query)->count();

        return ['count' => $count, 'quantity' => $count === 0 ? '0' : (string) (clone $query)->sum('quantity')];
    }
}
