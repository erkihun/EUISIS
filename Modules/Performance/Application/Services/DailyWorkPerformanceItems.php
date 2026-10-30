<?php

declare(strict_types=1);

namespace Modules\Performance\Application\Services;

use Illuminate\Support\Carbon;
use Modules\DailyWork\Contracts\PerformanceItemSource;
use Modules\Performance\Contracts\Data\AgreementStatus;
use Modules\Performance\Domain\Models\EmployeePerformanceItem;

/**
 * The performance items daily work may cite (moved here from Daily work's
 * service, form and presenter): current items of an agreement in force.
 */
final class DailyWorkPerformanceItems implements PerformanceItemSource
{
    private const IN_FORCE = [AgreementStatus::Agreed, AgreementStatus::Active, AgreementStatus::UnderReview];

    public function __construct(private readonly PerformanceCascadeService $cascade) {}

    public function agreedItems(string $employeeId, Carbon $date): array
    {
        return $this->inForce($employeeId, $date)
            ->with('kpi:id,code,name_en,name_am,unit_of_measure')->get()
            ->map(fn (EmployeePerformanceItem $item): array => [
                'id' => (string) $item->id,
                'name_en' => $item->kpi->code.' — '.$item->kpi->name_en,
                'name_am' => $item->kpi->code.' — '.($item->kpi->name_am ?? $item->kpi->name_en),
                'unit' => $item->kpi->unit_of_measure,
            ])->values()->all();
    }

    public function citableItems(array $itemIds, string $employeeId, Carbon $date): array
    {
        if ($itemIds === []) {
            return [];
        }

        return $this->inForce($employeeId, $date)->whereIn('id', $itemIds)->get(['id', 'kpi_id', 'objective_id'])
            ->mapWithKeys(fn (EmployeePerformanceItem $i): array => [(string) $i->id => ['id' => (string) $i->id, 'kpi_id' => $i->kpi_id, 'objective_id' => $i->objective_id]])
            ->all();
    }

    public function traceOf(string $itemId): array
    {
        $objective = EmployeePerformanceItem::query()->with('objective')->find($itemId)?->objective;

        return $objective === null ? [] : $this->cascade->trace($objective);
    }

    private function inForce(string $employeeId, Carbon $date)
    {
        return EmployeePerformanceItem::query()->where('is_current', true)
            ->whereHas('agreement', fn ($q) => $q->where('employee_id', $employeeId)
                ->whereIn('status', array_map(fn (AgreementStatus $s): string => $s->value, self::IN_FORCE))
                ->where('effective_from', '<=', $date->toDateString())->where('effective_to', '>=', $date->toDateString()));
    }
}
