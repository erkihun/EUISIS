<?php

declare(strict_types=1);

use App\Enums\Performance\PlanType;
use App\Models\DailyActivityItem;
use App\Models\EmployeePerformanceAgreement;
use App\Models\EmployeePerformanceItem;
use App\Models\KpiActual;
use App\Models\PerformancePlan;
use App\Models\PositionService;
use App\Models\StrategicGoal;
use App\Services\Performance\PerformanceCascadeService;
use App\Services\Performance\PerformancePlanService;
use App\Support\Demo\DemoDataset;
use Database\Seeders\Demo\DemoPerformanceSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\Storage;

it('seeds an isolated coherent cascade and preserves published fixtures on rerun', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $this->seed(DemoDataSeeder::class);
    $org = DemoDataset::requireOrganization('ORG-5');
    $employee = DemoDataset::requireEmployee('E-5-5');
    $plans = PerformancePlan::query()->where('organization_id', $org->id)->get();
    expect($plans)->toHaveCount(3);
    foreach ($plans as $plan) {
        expect($plan->status->value)->toBe('PUBLISHED')
            ->and(app(PerformancePlanService::class)->validate($plan))->toBe([]);
    }
    $goal = StrategicGoal::query()->where('organization_id', $org->id)->where('code', 'DEMO-SG-1')->sole();
    expect($goal->weight_percent)->toBe('30.0000')
        ->and($goal->allocations()->pluck('organization_contribution_percent')->sort()->values()->all())->toBe(['5.0000', '10.0000', '15.0000']);
    $positionPlan = $plans->firstWhere('plan_type', PlanType::Position);
    expect($positionPlan->objectives)->toHaveCount(2)
        ->and($positionPlan->objectives->pluck('position_service_id')->unique())->toHaveCount(1)
        ->and($positionPlan->objectives->pluck('weight')->all())->toBe(['50.0000', '50.0000']);
    $agreement = EmployeePerformanceAgreement::query()->where('employee_id', $employee->id)->sole();
    expect($agreement->status->value)->toBe('DRAFT')
        ->and($agreement->employee_assignment_id)->toBe($employee->current_assignment_id)
        ->and($agreement->performance_plan_id)->toBe($positionPlan->id)
        ->and($agreement->items)->toHaveCount(2);
    $activity = DailyActivityItem::query()->whereIn('employee_performance_item_id', $agreement->items->pluck('id'))->sole();
    expect($activity->quantity)->toBe('1.00')->and($activity->log->status->value)->toBe('draft')
        ->and(array_column(app(PerformanceCascadeService::class)->activityTrace($activity), 'type'))->toBe(['GOAL', 'ALLOCATION', 'ORGANIZATION', 'UNIT', 'POSITION', 'SERVICE'])
        ->and(KpiActual::query()->count())->toBe(0);
    $view = app(\App\Services\DailyActivity\DailyActivityPresenter::class)->detail($activity->log);
    expect(array_column($view['items'][0]['cascade_trace'], 'type'))->toBe(['GOAL', 'ALLOCATION', 'ORGANIZATION', 'UNIT', 'POSITION', 'SERVICE']);
    $before = [$plans->pluck('id')->all(), PositionService::query()->count(), EmployeePerformanceItem::query()->count(), DailyActivityItem::query()->count()];
    $this->seed(DemoPerformanceSeeder::class);
    expect([PerformancePlan::query()->where('organization_id', $org->id)->pluck('id')->all(), PositionService::query()->count(), EmployeePerformanceItem::query()->count(), DailyActivityItem::query()->count()])->toBe($before);
});
