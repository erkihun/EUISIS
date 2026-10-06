<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Enums\Performance\AgreementStatus;
use App\Enums\Performance\KpiDataSource;
use App\Models\EmployeePerformanceItem;
use App\Models\EmployeeServiceFeedback;
use Illuminate\Support\Carbon;

/**
 * Client feedback → performance.
 *
 * A rating counts toward an agreement item when the item's KPI is measured
 * by the service feedback source and the rating was given to the same
 * employee, in the same organization and position (the assignment snapshot),
 * during the agreement period. When the item's plan item names a position
 * service, only ratings of that service count. Services not marked for
 * performance evaluation never count (SystemKpiSourceRegistry).
 *
 * Re-measurement replaces the month's system actual, so repeated feedback
 * never adds rows. Finalized and closed agreements are never touched.
 */
final class ServiceFeedbackPerformanceSync
{
    private const OPEN = [AgreementStatus::Agreed, AgreementStatus::Active, AgreementStatus::UnderReview];

    public function __construct(private readonly KpiActualService $actuals) {}

    /** @return int number of agreement items re-measured */
    public function sync(EmployeeServiceFeedback $feedback): int
    {
        if ($feedback->organization_id === null || $feedback->position_id === null) {
            return 0;
        }
        $date = Carbon::parse($feedback->created_at)->startOfDay();
        $items = EmployeePerformanceItem::query()->where('is_current', true)
            ->where('data_source_type', KpiDataSource::SystemTransaction->value)
            ->whereHas('kpi', fn ($kpi) => $kpi->where('system_source_key', SystemKpiSourceRegistry::SERVICE_FEEDBACK))
            ->whereHas('agreement', fn ($agreement) => $agreement->where('employee_id', $feedback->employee_id)
                ->where('organization_id', $feedback->organization_id)->where('position_id', $feedback->position_id)
                ->whereIn('status', array_map(fn (AgreementStatus $status) => $status->value, self::OPEN))
                ->whereDate('effective_from', '<=', $date)->whereDate('effective_to', '>=', $date))
            ->where(fn ($item) => $item->whereNull('objective_id')
                ->orWhereHas('objective', fn ($objective) => $objective->whereNull('position_service_id')
                    ->orWhere('position_service_id', $feedback->position_service_id)))
            ->with(['agreement', 'kpi', 'objective'])->get();

        foreach ($items as $item) {
            $this->actuals->syncSystem($item, $date->copy()->startOfMonth(), $date->copy()->endOfMonth());
        }

        return $items->count();
    }
}
