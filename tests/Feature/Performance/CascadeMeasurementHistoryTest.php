<?php

declare(strict_types=1);

use App\Models\DailyActivityItem;
use App\Models\DailyActivityLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeePerformanceAgreement;
use App\Models\Kpi;
use App\Models\KpiActual;
use App\Models\KpiTarget;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\PerformanceCycle;
use App\Models\PerformancePlan;
use App\Models\Position;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\Performance\EmployeeScoreCalculator;
use App\Services\Performance\KpiActualService;
use App\Services\Performance\PerformanceAggregationService;
use App\Services\Performance\PerformanceEvidenceService;
use App\Services\Performance\TargetAmendmentService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $type = OrganizationType::query()->create(['code' => 'MEAS-T', 'name_en' => 'Measurement test']);
    $this->organization = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'MEAS-A', 'name_en' => 'Measurement organization', 'status' => 'active']);
    $this->unit = OrganizationUnit::query()->create(['organization_id' => $this->organization->id, 'code' => 'MEAS-U', 'name_en' => 'Unit', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->position = Position::query()->create(['organization_id' => $this->organization->id, 'organization_unit_id' => $this->unit->id, 'job_position_code' => 'MEAS-P', 'title_en' => 'Officer', 'is_active' => true]);
    $this->employee = Employee::query()->create(['employee_number' => 'MEAS-E', 'first_name' => 'Measurement', 'last_name' => 'Employee', 'full_name' => 'Measurement Employee', 'status' => 'active']);
    $this->assignment = EmployeeAssignment::query()->create(['employee_id' => $this->employee->id, 'organization_id' => $this->organization->id, 'organization_unit_id' => $this->unit->id, 'position_id' => $this->position->id, 'assignment_status' => 'active', 'effective_from' => '2026-02-01', 'is_current' => true]);
    $this->employee->update(['current_assignment_id' => $this->assignment->id]);
    $this->cycle = PerformanceCycle::query()->create(['code' => 'MEAS-2026', 'name_en' => 'Measurement cycle', 'organization_id' => $this->organization->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    $this->plan = new PerformancePlan(['cycle_id' => $this->cycle->id, 'organization_id' => $this->organization->id, 'organization_unit_id' => $this->unit->id, 'position_id' => $this->position->id, 'plan_type' => 'POSITION', 'title' => 'Position plan']);
    $this->plan->forceFill(['status' => 'PUBLISHED', 'lineage_key' => 'measurement-position-plan'])->save();
    $this->objective = $this->plan->objectives()->create(['code' => 'MEAS-O', 'title_en' => 'Complete requests', 'objective_type' => 'LOCAL', 'weight' => 100]);
    $this->kpi = Kpi::query()->create(['code' => 'MEAS-K', 'name_en' => 'Requests', 'measurement_type' => 'COUNT', 'direction' => 'HIGHER_IS_BETTER', 'aggregation_method' => 'SUM', 'data_source_type' => 'DAILY_ACTIVITY', 'frequency' => 'ANNUAL']);
    $this->target = $this->objective->targets()->create(['performance_plan_id' => $this->plan->id, 'kpi_id' => $this->kpi->id, 'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'target_value' => 100, 'weight' => 100]);
    $this->agreement = EmployeePerformanceAgreement::query()->create(['cycle_id' => $this->cycle->id, 'employee_id' => $this->employee->id, 'employee_assignment_id' => $this->assignment->id, 'performance_plan_id' => $this->plan->id, 'organization_id' => $this->organization->id, 'organization_unit_id' => $this->unit->id, 'position_id' => $this->position->id, 'effective_from' => '2026-02-01', 'effective_to' => '2026-02-28']);
    $this->agreement->forceFill(['status' => 'ACTIVE'])->save();
    $this->item = $this->agreement->items()->create(['objective_id' => $this->objective->id, 'kpi_id' => $this->kpi->id, 'position_target_id' => $this->target->id, 'expected_output' => 'Complete requests', 'target_value' => 100, 'weight' => 100, 'data_source_type' => 'DAILY_ACTIVITY']);
    $this->actors = collect([User::factory()->create(), User::factory()->create()]);
    foreach ($this->actors as $actor) {
        foreach (['kpi_targets.manage', 'performance_plans.approve', 'employee_performance_agreements.manage'] as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        UserOrganizationScope::query()->create(['user_id' => $actor->id, 'organization_id' => $this->organization->id, 'scope_type' => 'self', 'is_active' => true]);
    }
});

function measurementDaily(string $date, ?int $quantity, array $logOverrides = [], ?string $itemId = null): DailyActivityItem
{
    $t = test();
    $log = (new DailyActivityLog)->forceFill(['employee_id' => $t->employee->id, 'employee_assignment_id' => $t->assignment->id, 'organization_id' => $t->organization->id, 'organization_unit_id' => $t->unit->id, 'activity_date' => $date, 'status' => 'approved', ...$logOverrides]);
    $log->save();
    $task = (new DailyActivityItem)->forceFill(['daily_activity_log_id' => $log->id, 'title' => 'Completed requests', 'progress_status' => 'completed', 'quantity' => $quantity, 'employee_performance_item_id' => $itemId ?? $t->item->id]);
    $task->save();

    return $task;
}

test('daily amendment resynchronization preserves actual records but counts the combined period once', function (): void {
    measurementDaily('2026-02-02', 3);
    measurementDaily('2026-02-03', 4);
    $actuals = app(KpiActualService::class);
    $from = Carbon::parse('2026-02-01');
    $to = Carbon::parse('2026-02-28');
    $old = $actuals->syncDailyActivity($this->item, $from, $to);
    $amendments = app(TargetAmendmentService::class);
    $amendment = $amendments->request($this->item, ['target_value' => 120], 'Controlled target adjustment', '2026-02-15', $this->actors[0]);
    $amendments->decide($amendment, true, $this->actors[1]);
    $current = $this->agreement->items()->firstOrFail();
    $new = $actuals->syncDailyActivity($current, $from, $to);

    expect($old->fresh()->actual_value)->toBe('7.0000')
        ->and($new->actual_value)->toBe('7.0000')
        ->and(KpiActual::query()->where('agreement_id', $this->agreement->id)->count())->toBe(2)
        ->and(app(EmployeeScoreCalculator::class)->trace($this->agreement)['items'][0]['actual'])->toBe('7.0000')
        ->and(app(PerformanceAggregationService::class)->measureTarget($this->target, Carbon::parse('2026-12-31'))[0]->value)->toBe('7.0000');
});

test('daily measurement distinguishes missing data and explicit zero and excludes wrong assignments', function (): void {
    $service = app(KpiActualService::class);
    $from = Carbon::parse('2026-02-01');
    $to = Carbon::parse('2026-02-28');
    expect($service->syncDailyActivity($this->item, $from, $to))->toBeNull();
    measurementDaily('2026-02-02', 50, ['employee_assignment_id' => null]);
    measurementDaily('2026-02-03', null);
    expect($service->syncDailyActivity($this->item, $from, $to))->toBeNull();
    measurementDaily('2026-02-04', 0);
    expect($service->syncDailyActivity($this->item, $from, $to)->actual_value)->toBe('0.0000');
    expect(fn () => $service->syncDailyActivity($this->item, Carbon::parse('2026-01-01'), $to))->toThrow(ValidationException::class);
});

test('daily evidence refuses another employee assignment period or agreement item', function (string $case): void {
    $overrides = [];
    $date = '2026-02-02';
    $itemId = null;
    if ($case === 'employee') {
        $other = Employee::query()->create(['employee_number' => 'MEAS-OTHER', 'first_name' => 'Other', 'last_name' => 'Employee', 'full_name' => 'Other Employee', 'status' => 'active']);
        $overrides['employee_id'] = $other->id;
    } elseif ($case === 'assignment') {
        $overrides['employee_assignment_id'] = null;
    } elseif ($case === 'period') {
        $date = '2026-01-31';
    } else {
        $other = $this->agreement->allItems()->create(['objective_id' => $this->objective->id, 'kpi_id' => $this->kpi->id, 'expected_output' => 'Other output', 'weight' => 0]);
        $itemId = $other->id;
    }
    $task = measurementDaily($date, 1, $overrides, $itemId);

    expect(fn () => app(PerformanceEvidenceService::class)->add($this->agreement, ['title' => 'Invalid context', 'employee_performance_item_id' => $this->item->id, 'daily_activity_item_id' => $task->id], null, $this->actors[0]))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('performance_evidence', 0);
})->with(['employee', 'assignment', 'period', 'item']);

test('an amended target inherits historical contributors while respecting agreement dates', function (): void {
    $this->item->update(['data_source_type' => 'MANUAL']);
    foreach ([['2026-01-01', '2026-01-31', 50], ['2026-02-01', '2026-02-28', 7], ['2026-03-01', '2026-03-31', 90]] as [$from, $to, $value]) {
        KpiActual::query()->create(['kpi_id' => $this->kpi->id, 'employee_performance_item_id' => $this->item->id, 'subject_key' => 'item:'.$this->item->id, 'agreement_id' => $this->agreement->id, 'organization_id' => $this->organization->id, 'period_start' => $from, 'period_end' => $to, 'actual_value' => $value, 'source_type' => 'MANUAL', 'source_key' => 'manual']);
    }
    $service = app(TargetAmendmentService::class);
    $amendment = $service->request($this->target, ['target_value' => 120], 'Revised workload', '2026-02-15', $this->actors[0]);
    $service->decide($amendment, true, $this->actors[1]);
    $next = KpiTarget::query()->findOrFail($amendment->fresh()->new_subject_id);

    expect($this->item->fresh()->position_target_id)->toBe($this->target->id)
        ->and(app(PerformanceAggregationService::class)->measureTarget($next, Carbon::parse('2026-12-31'))[0]->value)->toBe('7.0000')
        ->and(app(EmployeeScoreCalculator::class)->trace($this->agreement)['items'][0]['actual'])->toBe('7.0000');
});
