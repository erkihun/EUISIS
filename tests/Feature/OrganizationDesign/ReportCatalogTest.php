<?php

declare(strict_types=1);

use App\Enums\OrganizationDesignStatus;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\Competency;
use App\Models\CompetencyFramework;
use App\Models\CompetencyLevel;
use App\Models\InstitutionMandate;
use App\Models\LegalInstrumentType;
use App\Models\ManpowerPlan;
use App\Models\ManpowerPlanLine;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationFunction;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\PositionRequirement;
use App\Models\PositionServiceTaskStandard;
use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use App\Models\ResponsibilityType;
use App\Models\Service;
use App\Models\UnitResponsibility;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Models\WorkCompetencyRequirement;
use App\Models\WorkforcePlanningMethodology;
use App\Models\WorkOccupationRequirement;
use App\Services\OrganizationDesign\JobDescriptionService;
use App\Services\OrganizationDesign\OrganizationDesignReportService;
use App\Services\OrganizationDesign\PositionProfileService;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * The production report catalog (brief §44): every report runs inside the
 * organization scope, declares its columns, and reads the design as it is.
 * Test 91.
 */

beforeEach(function (): void {
    foreach (['organization-design-reports.view', 'organization-design-reports.export'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web');
    Role::findOrCreate('Reporter', 'web')->syncPermissions(['organization-design-reports.view']);
    CompetencyLevel::query()->firstOrCreate(['code' => 'PROFICIENT'], ['name_en' => 'Proficient', 'rank' => 3, 'is_active' => true]);
    LegalInstrumentType::query()->firstOrCreate(['code' => 'PROCLAMATION'], ['name_en' => 'Proclamation', 'sort_order' => 10]);
});

test('every catalog report runs inside the scope with its declared columns and reads the design as it is', function (): void {
    $type = OrganizationType::query()->firstOrCreate(['code' => 'RC-BUREAU'], ['name_en' => 'RC Bureau', 'is_demo' => false]);
    $organization = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'RC-'.uniqid(), 'name_en' => 'RC Org', 'status' => OrganizationStatus::Active, 'effective_from' => now()->subYear()->toDateString(), 'is_demo' => false]);
    $reporter = User::factory()->create();
    $reporter->assignRole('Reporter');
    UserOrganizationScope::query()->create(['user_id' => $reporter->id, 'organization_id' => $organization->id, 'scope_type' => OrganizationScopeType::Self, 'is_active' => true, 'effective_from' => now()->subDay()->toDateString()]);
    $designer = User::factory()->create();
    $approver = User::factory()->create();
    $unitType = OrganizationUnitType::query()->firstOrCreate(['code' => 'RC-DIR'], ['name_en' => 'Directorate', 'is_active' => true]);
    $unit = OrganizationUnit::query()->create(['organization_id' => $organization->id, 'organization_unit_type_id' => $unitType->id, 'code' => 'U-1', 'name_en' => 'Unit', 'unit_type' => 'directorate', 'status' => 'active']);
    $mandate = InstitutionMandate::query()->create(['organization_id' => $organization->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'MND-1', 'title_en' => 'Mandate', 'legal_instrument_type_id' => LegalInstrumentType::query()->value('id'), 'legal_instrument_number' => '1/2020', 'effective_from' => '2020-01-01', 'status' => OrganizationDesignStatus::Published]);
    $function = OrganizationFunction::query()->create(['organization_id' => $organization->id, 'mandate_id' => $mandate->id, 'code' => 'F-1', 'title_en' => 'Function', 'path' => '', 'depth' => 0]);
    $function->forceFill(['path' => $function->id])->save();
    $service = Service::query()->create(['organization_id' => $organization->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'SRV-1', 'name_en' => 'Service', 'owner_organization_unit_id' => $unit->id, 'beneficiary_type' => 'citizen', 'effective_from' => '2021-01-01', 'status' => OrganizationDesignStatus::Published]);
    $service->functions()->attach($function->id, ['id' => (string) Str::uuid(), 'organization_id' => $organization->id]);
    $process = Process::query()->create(['organization_id' => $organization->id, 'service_id' => $service->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'PRC-1', 'name_en' => 'Process', 'process_type' => 'core', 'effective_from' => '2022-01-01', 'status' => OrganizationDesignStatus::Published]);
    $activity = ProcessActivity::query()->create(['process_id' => $process->id, 'organization_id' => $organization->id, 'code' => 'A-1', 'name_en' => 'Activity', 'path' => '', 'depth' => 0]);
    $activity->forceFill(['path' => $activity->id])->save();
    $task = ProcessTask::query()->create(['activity_id' => $activity->id, 'process_id' => $process->id, 'organization_id' => $organization->id, 'code' => 'T-1', 'name_en' => 'Task']);
    PositionServiceTaskStandard::query()->create(['master_task_id' => $task->id, 'organization_id' => $organization->id, 'version_no' => 1, 'status' => 'approved', 'planned_quantity' => '40', 'quantity_unit' => 'files', 'effective_from' => '2024-01-01']);
    $occupation = Occupation::query()->create(['code' => 'RC-2411', 'isco_code' => '2411', 'name_en' => 'Accountant', 'is_active' => true]);
    WorkOccupationRequirement::query()->create(['organization_id' => $organization->id, 'task_id' => $task->id, 'occupation_id' => $occupation->id, 'is_primary' => true]);
    $framework = CompetencyFramework::query()->firstOrCreate(['code' => 'RC-FW'], ['name_en' => 'RC framework', 'is_active' => true]);
    $competency = Competency::query()->create(['framework_id' => $framework->id, 'code' => 'NUM', 'name_en' => 'Numeracy', 'is_active' => true]);
    WorkCompetencyRequirement::query()->create(['organization_id' => $organization->id, 'task_id' => $task->id, 'competency_id' => $competency->id, 'minimum_level_id' => CompetencyLevel::query()->where('code', 'PROFICIENT')->value('id'), 'is_mandatory' => true]);
    $responsible = ResponsibilityType::query()->firstOrCreate(['code' => 'RESPONSIBLE'], ['name_en' => 'Responsible', 'is_exclusive' => false, 'covers_object' => true]);
    UnitResponsibility::query()->create(['organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'object_type' => 'service', 'object_id' => $service->id, 'responsibility_type_id' => $responsible->id]);
    $position = Position::query()->create(['organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'occupation_id' => $occupation->id, 'job_position_code' => 'P-1', 'title_en' => 'Senior Accountant', 'grade_level' => 'IX', 'is_active' => true]);
    PositionEstablishment::query()->create(['establishment_number' => 'EST-1', 'organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'position_id' => $position->id, 'approved_slots' => 2, 'status' => 'approved', 'effective_from' => '2025-01-01']);
    $profiles = app(PositionProfileService::class);
    $profiles->syncWorkLinks($designer, $position, [['object_type' => 'task', 'object_id' => $task->id, 'is_primary' => true]]);
    $profiles->deriveRequirements($designer, $position);
    $profiles->publish($approver, $profiles->createDraft($designer, $position, ['purpose_en' => 'Purpose', 'effective_from' => '2025-01-01']));
    $jds = app(JobDescriptionService::class);
    $jd = $jds->createDraft($designer, $position, ['effective_from' => '2025-01-01']);
    $jds->submit($designer, $jd);
    $jds->approve($approver, $jd);
    $jds->publish($approver, $jd);
    $methodology = WorkforcePlanningMethodology::query()->create(['code' => 'RC-FM', 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'name_en' => 'Fixed', 'calculation_type' => 'FIXED_MINIMUM', 'parameters' => ['minimum_positions' => '2'], 'rounding_rule' => 'ceil', 'status' => OrganizationDesignStatus::Published, 'effective_from' => '2024-01-01']);
    $plan = ManpowerPlan::query()->create(['reference_number' => 'MPP-RC-1', 'organization_id' => $organization->id, 'methodology_id' => $methodology->id, 'title_en' => 'Plan', 'planning_period_start' => '2027-01-01', 'planning_period_end' => '2027-12-31', 'status' => OrganizationDesignStatus::Approved]);
    ManpowerPlanLine::query()->create(['plan_id' => $plan->id, 'organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'occupation_id' => $occupation->id, 'inputs' => [], 'calculated_fte' => '2.0000', 'recommended_headcount' => 2, 'approved_headcount' => 3, 'adjustment_reason' => 'Backlog', 'calculated_at' => now(), 'workload_source_type' => 'approved_forecast', 'sequence' => 1]);
    PositionRequirement::query()->create(['plan_id' => $plan->id, 'organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'occupation_id' => $occupation->id, 'position_title_en' => 'Accountant', 'required_fte' => '2.0000', 'approved_headcount' => 3, 'status' => 'implemented', 'position_id' => $position->id, 'sequence' => 1]);

    $reports = app(OrganizationDesignReportService::class);
    $expected = ['service_catalog', 'function_service_matrix', 'service_process_matrix', 'process_activity_matrix', 'activity_standards', 'occupation_demand', 'competency_demand', 'responsibility_matrix', 'fte_vs_headcount', 'position_requirements', 'vacancy', 'jd_register'];
    foreach ($expected as $name) {
        expect(OrganizationDesignReportService::REPORTS)->toContain($name);
        $result = $reports->run($name, $reporter, ['organization_id' => $organization->id]);
        expect($result['columns'])->toBe(OrganizationDesignReportService::COLUMNS[$name])
            ->and($result['rows'])->not->toBeEmpty("{$name} has no rows")
            ->and(array_keys($result['rows'][0]))->toContain(...$result['columns']);
        foreach ($result['columns'] as $column) {
            expect(__("organization-design.reports.columns.{$column}"))->not->toBe("organization-design.reports.columns.{$column}", "column {$column} has no label");
        }
        expect(__("organization-design.reports.names.{$name}"))->not->toBe("organization-design.reports.names.{$name}");
    }
    $row = fn (string $name) => $reports->run($name, $reporter, ['organization_id' => $organization->id])['rows'][0];
    expect($row('service_catalog'))->toMatchArray(['service_code' => 'SRV-1', 'functions' => 'F-1', 'owner_unit_code' => 'U-1', 'processes' => 1, 'beneficiary_type' => 'citizen'])
        ->and($row('function_service_matrix'))->toMatchArray(['mandate_code' => 'MND-1', 'function_code' => 'F-1', 'service_code' => 'SRV-1'])
        ->and($row('service_process_matrix'))->toMatchArray(['service_code' => 'SRV-1', 'process_code' => 'PRC-1', 'activities' => 1, 'tasks' => 1])
        ->and($row('process_activity_matrix'))->toMatchArray(['activity_code' => 'A-1', 'tasks' => 1, 'standards_in_force' => 1, 'occupations_required' => 1, 'competencies_required' => 1])
        ->and($row('activity_standards'))->toMatchArray(['subject_code' => 'T-1', 'planned_quantity' => '40.0000', 'quantity_unit' => 'files'])
        ->and($row('occupation_demand'))->toMatchArray(['occupation_code' => '2411', 'tasks_requiring' => 1, 'positions' => 1, 'approved_headcount' => 3, 'current_employees' => 0])
        ->and($row('competency_demand'))->toMatchArray(['competency_code' => 'NUM', 'tasks_requiring' => 1, 'positions_requiring' => 1, 'mandatory_positions' => 1])
        ->and($row('responsibility_matrix'))->toMatchArray(['unit_code' => 'U-1', 'object_type' => 'service', 'object_code' => 'SRV-1', 'responsibility_type' => 'RESPONSIBLE'])
        ->and($row('fte_vs_headcount'))->toMatchArray(['reference_number' => 'MPP-RC-1', 'calculated_fte' => '2.0000', 'recommended_headcount' => 2, 'approved_headcount' => 3, 'current_employees' => 0])
        ->and($row('position_requirements'))->toMatchArray(['reference_number' => 'MPP-RC-1', 'status' => 'implemented', 'position_code' => 'P-1'])
        ->and($row('vacancy'))->toMatchArray(['position_code' => 'P-1', 'approved_slots' => 2, 'current_employees' => 0, 'vacancies' => 2])
        ->and($row('jd_register'))->toMatchArray(['position_code' => 'P-1', 'version_no' => 1, 'status' => 'published']);

    $other = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'RC2-'.uniqid(), 'name_en' => 'RC Other', 'status' => OrganizationStatus::Active, 'effective_from' => now()->subYear()->toDateString(), 'is_demo' => false]);
    expect($reports->run('vacancy', $reporter, ['organization_id' => $other->id])['rows'])->toBe([]);
    $this->actingAs($reporter)->get(route('organization-design.reports.index', ['report' => 'vacancy', 'organization_id' => $organization->id]))
        ->assertInertia(fn ($page) => $page->where('report', 'vacancy')->where('result.rows.0.vacancies', 2));
});
