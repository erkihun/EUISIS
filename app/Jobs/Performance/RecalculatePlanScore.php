<?php

declare(strict_types=1);

namespace App\Jobs\Performance;

use App\Models\PerformancePlan;
use App\Services\Performance\PerformanceAggregationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Recompute a plan's roll-up (and, through lineage, its contributors) as of a
 * date, and cache it in performance_plan_scores for dashboards. Queued and
 * unique per plan+date so repeated clicks do not stack work.
 */
class RecalculatePlanScore implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public string $planId, public string $asOf) {}

    public function uniqueId(): string
    {
        return $this->planId.':'.$this->asOf;
    }

    public function handle(PerformanceAggregationService $aggregation): void
    {
        $plan = PerformancePlan::query()->find($this->planId);
        if ($plan !== null) {
            $aggregation->planScore($plan, Carbon::parse($this->asOf));
        }
    }
}
