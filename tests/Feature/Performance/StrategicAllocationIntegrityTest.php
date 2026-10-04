<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\PerformanceCycle;
use App\Models\PerformanceObjective;
use App\Models\PerformancePlan;
use App\Models\StrategicGoalAllocation;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\Performance\StrategicPlanningService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $type = OrganizationType::query()->create(['code' => 'AI-T', 'name_en' => 'Bureau']);
    $this->organization = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'AI-ORG', 'name_en' => 'Bureau', 'status' => 'active']);
    $this->otherOrganization = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'AI-OTHER', 'name_en' => 'Other', 'status' => 'active']);
    $this->units = collect(range(1, 3))->map(fn ($number) => OrganizationUnit::query()->create(['organization_id' => $this->organization->id, 'code' => "AI-U{$number}", 'name_en' => "Unit {$number}", 'unit_type' => 'directorate', 'status' => 'active']));
    $role = Role::findOrCreate('Allocation Integrity Planner', 'web');
    foreach (array_column(require database_path('seeders/data/performance-permissions.php'), 'name') as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->planner = User::factory()->create(['status' => 'active'])->assignRole($role);
    UserOrganizationScope::query()->create(['user_id' => $this->planner->id, 'organization_id' => $this->organization->id, 'scope_type' => 'self', 'is_active' => true]);
    $this->cycle = PerformanceCycle::query()->create(['code' => 'AI-FY', 'name_en' => 'FY', 'organization_id' => $this->organization->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    $this->service = app(StrategicPlanningService::class);
    $this->goal = $this->service->createGoal(['cycle_id' => $this->cycle->id, 'organization_id' => $this->organization->id, 'code' => 'AI-G', 'name_en' => 'Shared goal', 'name_am' => 'Shared goal', 'weight_percent' => '30.0000', 'is_shared' => true], $this->planner);
});

function integrityAllocation($test, int $unit, string $amount): StrategicGoalAllocation
{
    return $test->service->addAllocation($test->goal, ['organization_unit_id' => $test->units[$unit]->id, 'organization_contribution_percent' => $amount, 'allocation_type' => 'SHARED', 'is_lead' => $unit === 0], $test->planner);
}

it('uses absolute percentage points for shared allocations and permits incomplete drafts', function (): void {
    integrityAllocation($this, 0, '15');
    expect($this->service->readiness($this->goal))->toMatchArray(['allocation_total' => '15.0000', 'remaining' => '15.0000', 'ready' => false]);
    integrityAllocation($this, 1, '10');
    integrityAllocation($this, 2, '5');
    expect($this->service->readiness($this->goal))->toMatchArray(['allocation_total' => '30.0000', 'remaining' => '0.0000']);
});

it('rejects excess allocations and invalid domain amounts without partial writes', function (): void {
    integrityAllocation($this, 0, '25');
    foreach (['6', '0', '-1', '101'] as $amount) {
        expect(fn () => integrityAllocation($this, 1, $amount))->toThrow(ValidationException::class);
    }
    expect($this->goal->allocations()->count())->toBe(1);
});

it('excludes the edited allocation from its total and protects the goal weight', function (): void {
    $first = integrityAllocation($this, 0, '15');
    integrityAllocation($this, 1, '10');
    $this->service->updateAllocation($first, ['organization_contribution_percent' => '20'], $this->planner);
    expect($first->fresh()->organization_contribution_percent)->toBe('20.0000');
    expect(fn () => $this->service->updateAllocation($first, ['organization_contribution_percent' => '21'], $this->planner))->toThrow(ValidationException::class);
    expect(fn () => $this->service->updateGoal($this->goal, ['weight_percent' => '29.9999'], $this->planner))->toThrow(ValidationException::class);
    expect($this->goal->fresh()->weight_percent)->toBe('30.0000');
});

it('rereads draft status under the allocation lock', function (): void {
    $first = integrityAllocation($this, 0, '15');
    $this->goal->fresh()->forceFill(['status' => 'PUBLISHED'])->save();
    expect(fn () => integrityAllocation($this, 1, '5'))->toThrow(ValidationException::class);
    expect(fn () => $this->service->updateAllocation($first, ['organization_contribution_percent' => '10'], $this->planner))->toThrow(ValidationException::class);
});

it('counts the latest organization objective ledger once without descendants', function (): void {
    $lineage = (string) Str::uuid();
    foreach ([[1, 'ORGANIZATION', '10'], [2, 'ORGANIZATION', '30'], [1, 'UNIT', '30'], [1, 'POSITION', '30']] as $index => [$version, $type, $absolute]) {
        $plan = new PerformancePlan(['cycle_id' => $this->cycle->id, 'organization_id' => $this->organization->id, 'plan_type' => $type, 'title' => "Plan {$index}"]);
        $plan->forceFill(['lineage_key' => $type === 'ORGANIZATION' ? $lineage : (string) Str::uuid(), 'version_no' => $version, 'status' => 'DRAFT'])->save();
        PerformanceObjective::query()->create(['performance_plan_id' => $plan->id, 'strategic_goal_id' => $this->goal->id, 'code' => "OBJ-{$index}", 'title_en' => 'Deliver', 'objective_type' => 'LOCAL', 'weight' => 100, 'absolute_weight_percent' => $absolute]);
    }
    expect($this->service->readiness($this->goal)['objective_total'])->toBe('30.0000');
});

it('rejects unrelated units and unauthorized cross organization allocation management', function (): void {
    $otherUnit = OrganizationUnit::query()->create(['organization_id' => $this->otherOrganization->id, 'code' => 'AI-OU', 'name_en' => 'Other unit', 'unit_type' => 'directorate', 'status' => 'active']);
    expect(fn () => $this->service->addAllocation($this->goal, ['organization_unit_id' => $otherUnit->id, 'organization_contribution_percent' => '15', 'allocation_type' => 'SHARED'], $this->planner))->toThrow(ValidationException::class);
    $this->planner = User::factory()->create(['status' => 'active'])->assignRole('Allocation Integrity Planner');
    UserOrganizationScope::query()->create(['user_id' => $this->planner->id, 'organization_id' => $this->otherOrganization->id, 'scope_type' => 'self', 'is_active' => true]);
    expect(fn () => integrityAllocation($this, 0, '15'))->toThrow(AuthorizationException::class);
});
