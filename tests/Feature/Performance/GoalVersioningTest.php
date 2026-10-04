<?php

declare(strict_types=1);

use App\Enums\Performance\StrategicGoalStatus;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\PerformanceCycle;
use App\Models\PerformanceObjective;
use App\Models\PerformancePlan;
use App\Models\StrategicGoal;
use App\Models\StrategicGoalAllocation;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\Performance\StrategicPlanningService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $type = OrganizationType::query()->create(['code' => 'GV-T', 'name_en' => 'Bureau']);
    $this->org = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'GV-ORG', 'name_en' => 'Bureau', 'status' => 'active']);
    $this->unit = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'code' => 'GV-U', 'name_en' => 'HR', 'unit_type' => 'directorate', 'status' => 'active']);
    $role = Role::findOrCreate('Goal Version Planner', 'web');
    foreach (array_column(require database_path('seeders/data/performance-permissions.php'), 'name') as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->planner = User::factory()->create(['status' => 'active'])->assignRole($role);
    UserOrganizationScope::query()->create(['user_id' => $this->planner->id, 'organization_id' => $this->org->id, 'scope_type' => 'self', 'is_active' => true]);
    $this->cycle = PerformanceCycle::query()->create(['code' => 'GV-FY', 'name_en' => 'FY', 'organization_id' => $this->org->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    $this->goal = StrategicGoal::query()->create(['cycle_id' => $this->cycle->id, 'organization_id' => $this->org->id, 'code' => 'GV-G', 'name_en' => 'Service quality', 'name_am' => 'Service quality', 'weight_percent' => '100']);
    $this->goal->forceFill(['status' => 'PUBLISHED', 'published_at' => now()])->save();
    $this->allocation = StrategicGoalAllocation::query()->create(['strategic_goal_id' => $this->goal->id, 'organization_unit_id' => $this->unit->id, 'organization_contribution_percent' => '100', 'allocation_type' => 'PRIMARY', 'is_lead' => true]);
    $this->oldPlan = versionGoalPlan($this, $this->goal, 1, 'PUBLISHED');
    $this->service = app(StrategicPlanningService::class);
});

function versionGoalPlan($test, StrategicGoal $goal, int $version, string $status = 'DRAFT', string $type = 'ORGANIZATION'): PerformancePlan
{
    $plan = new PerformancePlan(['cycle_id' => $test->cycle->id, 'organization_id' => $test->org->id, 'plan_type' => $type, 'title' => "Version {$version}", 'organization_unit_id' => $type === 'UNIT' ? $test->unit->id : null]);
    $plan->forceFill(['lineage_key' => isset($test->oldPlan) ? $test->oldPlan->lineage_key : (string) Str::uuid(), 'version_no' => $version, 'status' => $status])->save();
    PerformanceObjective::query()->create(['performance_plan_id' => $plan->id, 'strategic_goal_id' => $goal->id, 'code' => 'GV-OBJ', 'title_en' => 'Deliver services', 'objective_type' => 'STRATEGIC', 'weight' => 100, 'absolute_weight_percent' => 100]);

    return $plan;
}

it('copies allocations to an amendment without changing published history', function (): void {
    $next = $this->service->newVersion($this->goal, 'Improve wording', $this->planner);
    expect($next->version_no)->toBe(2)->and($next->supersedes_goal_id)->toBe($this->goal->id)
        ->and($next->status)->toBe(StrategicGoalStatus::Draft)->and($this->goal->fresh()->status)->toBe(StrategicGoalStatus::Published)
        ->and($next->allocations()->first()->id)->not->toBe($this->allocation->id)
        ->and($next->allocations()->first()->organization_unit_id)->toBe($this->unit->id)
        ->and($next->allocations()->first()->organization_contribution_percent)->toBe('100.0000');
    $this->service->updateGoal($next, ['code' => 'GV-G', 'name_en' => 'Amended wording'], $this->planner);
    expect($next->fresh()->name_en)->toBe('Amended wording')->and($this->goal->fresh()->name_en)->toBe('Service quality')
        ->and($this->oldPlan->objectives()->first()->strategic_goal_id)->toBe($this->goal->id);
});

it('rejects a duplicate pending amendment and modifications to the old goal', function (): void {
    $this->service->newVersion($this->goal, 'Amend', $this->planner);
    expect(fn () => $this->service->newVersion($this->goal, 'Duplicate', $this->planner))->toThrow(ValidationException::class);
    expect(fn () => $this->service->updateGoal($this->goal, ['name_en' => 'Rewritten'], $this->planner))->toThrow(ValidationException::class);
    expect(fn () => $this->service->updateAllocation($this->allocation, ['organization_contribution_percent' => 90], $this->planner))->toThrow(ValidationException::class);
    expect(StrategicGoal::query()->count())->toBe(2)->and($this->allocation->fresh()->organization_contribution_percent)->toBe('100.0000');
});

it('validates the old plan against its own goals while a successor is incomplete', function (): void {
    $next = $this->service->newVersion($this->goal, 'Amend', $this->planner);
    expect($this->service->organizationReadiness($this->cycle->id, $this->org->id)['ready'])->toBeFalse()
        ->and($this->service->organizationReadiness($this->cycle->id, $this->org->id, $this->oldPlan))->toMatchArray(['total' => '100.0000', 'ready' => true]);
    $nextPlan = versionGoalPlan($this, $next, 2);
    expect($this->service->organizationReadiness($this->cycle->id, $this->org->id, $nextPlan))->toMatchArray(['total' => '100.0000', 'ready' => true]);
});

it('publishes a complete strategic amendment without double counting unit objectives or old versions', function (): void {
    $next = $this->service->newVersion($this->goal, 'Amend', $this->planner);
    versionGoalPlan($this, $next, 2);
    versionGoalPlan($this, $next, 3, 'DRAFT', 'UNIT');
    expect($this->service->organizationReadiness($this->cycle->id, $this->org->id))->toMatchArray(['total' => '100.0000', 'ready' => true]);
    foreach ([StrategicGoalStatus::UnderReview, StrategicGoalStatus::Approved, StrategicGoalStatus::Published] as $status) {
        $this->service->transition($next->fresh(), $status, $this->planner);
    }
    expect($next->fresh()->status)->toBe(StrategicGoalStatus::Published)->and($this->goal->fresh()->status)->toBe(StrategicGoalStatus::Superseded)
        ->and($this->oldPlan->objectives()->first()->strategic_goal_id)->toBe($this->goal->id)
        ->and($this->service->organizationReadiness($this->cycle->id, $this->org->id, $this->oldPlan)['ready'])->toBeTrue();
});
