<?php

declare(strict_types=1);

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
use App\Services\Performance\TargetAmendmentService;
use Spatie\Permission\Models\Permission;

test('amending a target preserves published downstream lineage and copies explicit period targets', function (): void {
    $type = OrganizationType::query()->create(['code' => 'HIST', 'name_en' => 'History test']);
    $organization = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'HIST', 'name_en' => 'History test', 'status' => 'active']);
    $unit = OrganizationUnit::query()->create(['organization_id' => $organization->id, 'code' => 'HIST-U', 'name_en' => 'Unit', 'unit_type' => 'directorate', 'status' => 'active']);
    $position = Position::query()->create(['organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'job_position_code' => 'HIST-P', 'title_en' => 'Officer', 'is_active' => true]);
    $employee = Employee::query()->create(['employee_number' => 'HIST-E', 'first_name' => 'History', 'last_name' => 'Employee', 'full_name' => 'History Employee', 'status' => 'active']);
    $assignment = EmployeeAssignment::query()->create(['employee_id' => $employee->id, 'organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'position_id' => $position->id, 'assignment_status' => 'active', 'effective_from' => '2026-01-01', 'is_current' => true]);
    $cycle = PerformanceCycle::query()->create(['code' => 'HIST-2026', 'name_en' => 'History cycle', 'organization_id' => $organization->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    $plan = new PerformancePlan(['cycle_id' => $cycle->id, 'organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'position_id' => $position->id, 'plan_type' => 'POSITION', 'title' => 'Published position plan']);
    $plan->forceFill(['status' => 'PUBLISHED', 'lineage_key' => (string) \Illuminate\Support\Str::uuid()])->save();
    $objective = $plan->objectives()->create(['code' => 'HIST-O', 'title_en' => 'Complete requests', 'objective_type' => 'LOCAL', 'weight' => 100]);
    $kpi = Kpi::query()->create(['code' => 'HIST-K', 'name_en' => 'Requests', 'measurement_type' => 'COUNT', 'direction' => 'HIGHER_IS_BETTER', 'aggregation_method' => 'SUM', 'data_source_type' => 'MANUAL', 'frequency' => 'ANNUAL']);
    $target = $objective->targets()->create(['performance_plan_id' => $plan->id, 'kpi_id' => $kpi->id, 'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'target_value' => 100, 'weight' => 100]);
    $quarter = $target->periodTargets()->create(['period_type' => 'QUARTER', 'period_number' => 1, 'target_value' => '17.2500', 'is_cumulative' => false]);
    $month = $target->periodTargets()->create(['period_type' => 'MONTH', 'period_number' => 2, 'target_numerator' => 3, 'target_denominator' => 4, 'is_cumulative' => true]);
    $child = $target->replicate();
    $child->forceFill(['parent_target_id' => $target->id, 'target_value' => 50])->save();
    $agreement = EmployeePerformanceAgreement::query()->create(['cycle_id' => $cycle->id, 'employee_id' => $employee->id, 'employee_assignment_id' => $assignment->id, 'performance_plan_id' => $plan->id, 'organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'position_id' => $position->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);
    $agreement->forceFill(['status' => 'ACTIVE'])->save();
    $item = $agreement->items()->create(['objective_id' => $objective->id, 'kpi_id' => $kpi->id, 'position_target_id' => $target->id, 'expected_output' => 'Complete requests', 'target_value' => 100, 'weight' => 100]);
    $actual = KpiActual::query()->create(['kpi_id' => $kpi->id, 'target_id' => $target->id, 'subject_key' => 'target:'.$target->id, 'performance_plan_id' => $plan->id, 'organization_id' => $organization->id, 'period_start' => '2026-01-01', 'period_end' => '2026-03-31', 'actual_value' => 12, 'source_type' => 'MANUAL', 'source_key' => 'manual']);
    $itemActual = KpiActual::query()->create(['kpi_id' => $kpi->id, 'employee_performance_item_id' => $item->id, 'subject_key' => 'item:'.$item->id, 'agreement_id' => $agreement->id, 'organization_id' => $organization->id, 'period_start' => '2026-01-01', 'period_end' => '2026-03-31', 'actual_value' => 8, 'source_type' => 'MANUAL', 'source_key' => 'manual']);

    $actors = collect([User::factory()->create(), User::factory()->create()]);
    foreach ($actors as $actor) {
        foreach (['kpi_targets.manage', 'performance_plans.approve'] as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        UserOrganizationScope::query()->create(['user_id' => $actor->id, 'organization_id' => $organization->id, 'scope_type' => 'self', 'is_active' => true]);
    }
    $service = app(TargetAmendmentService::class);
    $amendment = $service->request($target, ['target_value' => 120], 'Revised future workload', '2026-07-01', $actors[0]);
    $service->decide($amendment, true, $actors[1]);
    $next = KpiTarget::query()->findOrFail($amendment->fresh()->new_subject_id);

    expect($child->fresh()->parent_target_id)->toBe($target->id)
        ->and($item->fresh()->position_target_id)->toBe($target->id)
        ->and($item->fresh()->target_value)->toBe('100.0000')
        ->and($actual->fresh()->target_id)->toBe($target->id)
        ->and($itemActual->fresh()->employee_performance_item_id)->toBe($item->id)
        ->and($agreement->fresh()->performance_plan_id)->toBe($plan->id)
        ->and($target->fresh()->target_value)->toBe('100.0000')
        ->and($target->fresh()->is_current)->toBeFalse()
        ->and($next->is_current)->toBeTrue()
        ->and($next->amended_from_id)->toBe($target->id)
        ->and($next->version_no)->toBe(2)
        ->and($next->target_value)->toBe('120.0000')
        ->and($next->effective_from->toDateString())->toBe('2026-07-01')
        ->and($target->periodTargets()->count())->toBe(2)
        ->and($next->periodTargets()->count())->toBe(2);

    foreach ([$quarter, $month] as $original) {
        $copy = $next->periodTargets()->where('period_type', $original->period_type)->where('period_number', $original->period_number)->firstOrFail();
        expect($copy->id)->not->toBe($original->id)
            ->and($copy->only(['period_type', 'period_number', 'target_value', 'target_numerator', 'target_denominator', 'is_cumulative']))
            ->toBe($original->only(['period_type', 'period_number', 'target_value', 'target_numerator', 'target_denominator', 'is_cumulative']))
            ->and($original->fresh()->kpi_target_id)->toBe($target->id);
    }
});
