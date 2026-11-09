<?php

declare(strict_types=1);

use App\Enums\OrganizationDesignStatus;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\Competency;
use App\Models\CompetencyFramework;
use App\Models\CompetencyLevel;
use App\Models\EducationLevel;
use App\Models\EmployeeAssignment;
use App\Models\InstitutionMandate;
use App\Models\JobDescription;
use App\Models\LegalInstrumentType;
use App\Models\ManpowerPlan;
use App\Models\ManpowerPlanLine;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationDesignStudy;
use App\Models\OrganizationFunction;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\PositionProfile;
use App\Models\PositionRequirement;
use App\Models\PositionServiceTaskStandard;
use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use App\Models\ResponsibilityType;
use App\Models\Service;
use App\Models\UnitDesignProposal;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Models\WorkforcePlanningMethodology;
use App\Services\OrganizationDesign\OrganizationDesignGapService;
use App\Services\OrganizationDesign\OrganizationDesignReportService;
use App\Services\OrganizationDesign\OrganizationDesignValidationService;
use App\Services\OrganizationDesign\TraceabilityService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * The golden path (brief §50–51): one organization designed end to end
 * through the HTTP routes by a designer, a catalog steward and a separate
 * approver — study, mandate, functions, services, process, activities,
 * tasks, standards, occupation and competency requirements, unit design,
 * responsibilities, manpower plan, position requirements, positions,
 * profile, job description, validation, traceability — and never an
 * employee assignment. Test 92.
 */

beforeEach(function (): void {
    $names = array_column(require base_path('database/seeders/data/organization-design-permissions.php'), 'name');
    $extra = ['positions.view', 'positions.viewAny', 'organizations.view', 'organizations.viewAny', 'organization-units.viewAny', 'organization-units.view', 'work_standards.manage', 'work_standards.approve',
        'organization-units.request_create', 'organization-units.request_update', 'organization-units.request_move', 'organization-units.request_deactivate'];
    foreach ([...$names, ...$extra] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web');
    $approving = fn (string $n) => str_ends_with($n, '.approve') || str_ends_with($n, '.publish') || str_ends_with($n, '.review') || str_ends_with($n, '.implement');
    Role::findOrCreate('GP Designer', 'web')->syncPermissions([...array_filter($names, fn ($n) => ! $approving($n)), 'work_standards.manage', 'positions.view', 'positions.viewAny', 'organizations.view', 'organization-units.viewAny', 'organization-units.view',
        'organization-units.request_create', 'organization-units.request_update', 'organization-units.request_move', 'organization-units.request_deactivate']);
    Role::findOrCreate('GP Approver', 'web')->syncPermissions([...array_filter($names, fn ($n) => $approving($n) || str_ends_with($n, '.viewAny') || str_ends_with($n, '.view')), 'work_standards.approve', 'positions.view', 'positions.viewAny', 'organizations.view', 'organization-units.viewAny']);
    LegalInstrumentType::query()->firstOrCreate(['code' => 'PROCLAMATION'], ['name_en' => 'Proclamation', 'sort_order' => 10]);
    CompetencyLevel::query()->firstOrCreate(['code' => 'PROFICIENT'], ['name_en' => 'Proficient', 'rank' => 3, 'is_active' => true]);
    EducationLevel::query()->firstOrCreate(['code' => 'BACHELOR'], ['name_en' => 'Bachelor degree', 'rank' => 6, 'is_active' => true]);
    ResponsibilityType::query()->firstOrCreate(['code' => 'RESPONSIBLE'], ['name_en' => 'Responsible', 'is_exclusive' => false, 'covers_object' => true]);
});

function gpUser(Organization $organization, string $role, OrganizationScopeType $scope = OrganizationScopeType::Self): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'scope_type' => $scope, 'is_active' => true, 'effective_from' => now()->subDay()->toDateString()]);

    return $user->fresh();
}

// 92 ─────────────────────────────────────────────────────────────────────────
test('golden path: an organization is designed end to end through the routes, by separate hands, and no employee is ever assigned', function (): void {
    $type = OrganizationType::query()->firstOrCreate(['code' => 'GP-BUREAU'], ['name_en' => 'GP Bureau', 'is_demo' => false]);
    $organization = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'GP-'.uniqid(), 'name_en' => 'Golden Path Bureau', 'status' => OrganizationStatus::Active, 'effective_from' => now()->subYear()->toDateString(), 'is_demo' => false]);
    $designer = gpUser($organization, 'GP Designer');
    $approver = gpUser($organization, 'GP Approver');
    $unitType = OrganizationUnitType::query()->firstOrCreate(['code' => 'GP-DIR'], ['name_en' => 'Directorate', 'is_active' => true]);
    $head = OrganizationUnit::query()->create(['organization_id' => $organization->id, 'organization_unit_type_id' => $unitType->id, 'code' => 'HEAD', 'name_en' => 'Office of the Head', 'unit_type' => 'directorate', 'status' => 'active']);
    $hr = OrganizationUnit::query()->create(['organization_id' => $organization->id, 'organization_unit_type_id' => $unitType->id, 'parent_unit_id' => $head->id, 'code' => 'HR', 'name_en' => 'Human Resource Directorate', 'unit_type' => 'directorate', 'status' => 'active']);
    $occupation = Occupation::query()->create(['code' => 'GP-2423', 'isco_code' => '2423', 'name_en' => 'Personnel professional', 'is_active' => true]);
    $competency = Competency::query()->create(['framework_id' => CompetencyFramework::query()->firstOrCreate(['code' => 'GP-FW'], ['name_en' => 'GP framework', 'is_active' => true])->id, 'code' => 'REC', 'name_en' => 'Records management', 'is_active' => true]);
    $responsible = ResponsibilityType::query()->where('code', 'RESPONSIBLE')->firstOrFail();

    // 1. The design study is the container of the exercise.
    $this->actingAs($designer)->post(route('organization-design.studies.store'), ['organization_id' => $organization->id, 'title_en' => 'Golden path study', 'study_type' => 'restructure', 'reason' => 'New mandate', 'target_effective_date' => '2027-01-01'])->assertRedirect();
    $study = OrganizationDesignStudy::query()->where('organization_id', $organization->id)->firstOrFail();

    // 2. Mandate → functions (published by the approver, not the designer).
    $this->actingAs($designer)->post(route('organization-design.mandates.store'), ['organization_id' => $organization->id, 'code' => 'MND-GP', 'title_en' => 'Establishment proclamation', 'legal_instrument_type_id' => LegalInstrumentType::query()->value('id'), 'legal_instrument_number' => '1/2026', 'effective_from' => '2026-01-01'])->assertRedirect();
    $mandate = InstitutionMandate::query()->where('organization_id', $organization->id)->firstOrFail();
    foreach (['F-1' => 'Human resource management', 'F-2' => 'Records management', 'F-3' => 'Service delivery'] as $code => $title) {
        $this->actingAs($designer)->post(route('organization-design.mandates.functions.store', $mandate), ['code' => $code, 'title_en' => $title, 'sequence' => 1])->assertRedirect();
    }
    // The designer holds no approval permission at all; the approver cannot be the drafter either (checked in test 94).
    $this->actingAs($designer)->from('/')->post(route('organization-design.mandates.publish', $mandate))->assertForbidden();
    $this->actingAs($approver)->post(route('organization-design.mandates.publish', $mandate))->assertRedirect();
    expect($mandate->fresh()->status)->toBe(OrganizationDesignStatus::Published)->and(OrganizationFunction::query()->where('mandate_id', $mandate->id)->count())->toBe(3);
    $functions = OrganizationFunction::query()->where('mandate_id', $mandate->id)->get()->keyBy('code');

    // 3. Services under the functions.
    foreach (['SRV-1' => 'F-1', 'SRV-2' => 'F-2'] as $code => $function) {
        $this->actingAs($designer)->post(route('organization-design.services.store'), ['organization_id' => $organization->id, 'code' => $code, 'name_en' => 'Service '.$code, 'beneficiary_type' => 'employee', 'delivery_channel' => 'in_person', 'owner_organization_unit_id' => $hr->id, 'effective_from' => '2026-01-01', 'function_ids' => [$functions[$function]->id]])->assertRedirect();
        $this->actingAs($approver)->post(route('organization-design.services.publish', Service::query()->where('organization_id', $organization->id)->where('code', $code)->firstOrFail()))->assertRedirect();
    }
    $service = Service::query()->where('organization_id', $organization->id)->where('code', 'SRV-1')->firstOrFail();

    // 4. Process → activities → tasks → requirements, then published; a standard approved on the task.
    $this->actingAs($designer)->post(route('organization-design.processes.store'), ['organization_id' => $organization->id, 'service_id' => $service->id, 'code' => 'PRC-1', 'name_en' => 'Maintain records', 'process_type' => 'core', 'owner_organization_unit_id' => $hr->id, 'effective_from' => '2026-01-01'])->assertRedirect();
    $process = Process::query()->where('organization_id', $organization->id)->firstOrFail();
    $this->actingAs($designer)->post(route('organization-design.processes.activities.store', $process), ['code' => 'A-1', 'name_en' => 'Keep files', 'sequence' => 1])->assertRedirect();
    $activity = ProcessActivity::query()->where('process_id', $process->id)->firstOrFail();
    foreach (['T-1' => 'Update record', 'T-2' => 'Archive file'] as $code => $name) {
        $this->actingAs($designer)->post(route('organization-design.activities.tasks.store', $activity), ['code' => $code, 'name_en' => $name, 'sequence' => 1, 'required_output' => 'Updated file'])->assertRedirect();
    }
    $task = ProcessTask::query()->where('activity_id', $activity->id)->where('code', 'T-1')->firstOrFail();
    $this->actingAs($designer)->put(route('organization-design.requirements.sync', ['subjectType' => 'task', 'subject' => $task->id]), [
        'occupations' => [['occupation_id' => $occupation->id, 'is_primary' => true]],
        'competencies' => [['competency_id' => $competency->id, 'minimum_level_id' => CompetencyLevel::query()->where('code', 'PROFICIENT')->value('id'), 'is_mandatory' => true]],
        'qualifications' => [['education_level_id' => EducationLevel::query()->where('code', 'BACHELOR')->value('id'), 'field_of_study' => 'Human resource management', 'experience_years' => 2, 'is_mandatory' => true]],
    ])->assertRedirect();
    $this->actingAs($approver)->post(route('organization-design.processes.publish', $process))->assertRedirect();
    $this->actingAs($designer)->post(route('organization-design.tasks.standards.store', $task), ['planned_quantity' => '40', 'quantity_unit' => 'records', 'planned_time_minutes' => '15', 'quality_source' => 'employee', 'effective_from' => now()->toDateString()])->assertRedirect();
    $standard = PositionServiceTaskStandard::query()->where('master_task_id', $task->id)->firstOrFail();
    $this->actingAs($approver)->post(route('organization-design.standards.approve', $standard))->assertRedirect();
    expect($standard->fresh()->status->value)->toBe('approved');

    // 5. Unit design: responsibilities on the live unit, a proposed team in the study, approved by the other hand.
    $this->actingAs($designer)->put(route('organization-design.responsibilities.sync', ['subjectType' => 'unit', 'subject' => $hr->id]), ['items' => [
        ['object_type' => 'function', 'object_id' => $functions['F-1']->id, 'responsibility_type_id' => $responsible->id],
        ['object_type' => 'service', 'object_id' => $service->id, 'responsibility_type_id' => $responsible->id],
        ['object_type' => 'process', 'object_id' => $process->id, 'responsibility_type_id' => $responsible->id],
    ]])->assertRedirect();
    $this->actingAs($designer)->post(route('organization-design.studies.proposals.store', $study), ['proposal_type' => 'create', 'name_en' => 'Records Team', 'proposed_unit_type_id' => $unitType->id, 'parent_existing_unit_id' => $hr->id])->assertRedirect();
    $proposal = UnitDesignProposal::query()->where('study_id', $study->id)->firstOrFail();
    $this->actingAs($designer)->put(route('organization-design.responsibilities.sync', ['subjectType' => 'proposal', 'subject' => $proposal->id]), ['items' => [['object_type' => 'process', 'object_id' => $process->id, 'responsibility_type_id' => $responsible->id]]])->assertRedirect();
    $this->actingAs($designer)->post(route('organization-design.studies.submit', $study))->assertRedirect();
    $this->actingAs($designer)->from('/')->post(route('organization-design.studies.approve', $study))->assertForbidden();
    $this->actingAs($approver)->post(route('organization-design.studies.approve', $study))->assertRedirect();
    expect($study->fresh()->status)->toBe(OrganizationDesignStatus::Approved);

    // 6. Manpower: a methodology with test parameters (no policy is seeded), a plan line with its workload source, calculated, approved, implemented.
    $this->actingAs($designer)->post(route('organization-design.methodologies.store'), ['code' => 'GP-FIX', 'name_en' => 'Fixed minimum', 'calculation_type' => 'FIXED_MINIMUM', 'rounding_rule' => 'ceil', 'effective_from' => '2026-01-01', 'parameters' => ['minimum_positions' => '2']])->assertRedirect();
    $methodology = WorkforcePlanningMethodology::query()->where('code', 'GP-FIX')->firstOrFail();
    $this->actingAs($approver)->post(route('organization-design.methodologies.publish', $methodology))->assertRedirect();
    $this->actingAs($designer)->post(route('organization-design.manpower-plans.store'), ['organization_id' => $organization->id, 'title_en' => 'Plan 2027', 'methodology_id' => $methodology->id, 'planning_period_start' => '2027-01-01', 'planning_period_end' => '2027-12-31', 'study_id' => $study->id])->assertRedirect();
    $plan = ManpowerPlan::query()->where('organization_id', $organization->id)->firstOrFail();
    $this->actingAs($designer)->post(route('organization-design.manpower-plans.lines.store', $plan), ['organization_unit_id' => $hr->id, 'occupation_id' => $occupation->id, 'task_id' => $task->id, 'inputs' => [], 'workload_source_type' => 'approved_forecast', 'workload_source_reference' => 'Records forecast 2027'])->assertRedirect();
    $this->actingAs($designer)->post(route('organization-design.manpower-plans.calculate', $plan))->assertRedirect();
    $line = ManpowerPlanLine::query()->where('plan_id', $plan->id)->firstOrFail();
    expect($line->calculated_fte)->not->toBeNull()->and($line->recommended_headcount)->toBe(2)->and($line->approved_headcount)->toBeNull();
    $this->actingAs($designer)->post(route('organization-design.manpower-plans.submit', $plan))->assertRedirect();
    $this->actingAs($designer)->from('/')->post(route('organization-design.manpower-plans.approve', $plan))->assertForbidden();
    $this->actingAs($approver)->post(route('organization-design.manpower-plans.approve', $plan))->assertRedirect();
    $requirement = PositionRequirement::query()->where('plan_id', $plan->id)->firstOrFail();
    expect($requirement->status)->toBe('approved')->and($requirement->approved_headcount)->toBe(2)->and($requirement->position_id)->toBeNull();
    $this->actingAs($approver)->post(route('organization-design.manpower-plans.implement', $plan))->assertRedirect();
    $requirement->refresh();
    $position = Position::query()->findOrFail($requirement->position_id);
    expect($requirement->status)->toBe('implemented')->and($position->organization_unit_id)->toBe($hr->id)->and($position->occupation_id)->toBe($occupation->id)
        ->and(PositionEstablishment::query()->where('position_id', $position->id)->where('status', 'approved')->sum('approved_slots'))->toBe(2);

    // 7. Position design: links, derived requirements, published profile, published job description.
    $this->actingAs($designer)->put(route('organization-design.position-work-links.sync', $position), ['items' => [['object_type' => 'task', 'object_id' => $task->id, 'is_primary' => true]]])->assertRedirect();
    $this->actingAs($designer)->post(route('organization-design.position-requirements.derive', $position))->assertRedirect();
    $this->actingAs($designer)->post(route('organization-design.position-profiles.store', $position), ['purpose_en' => 'Keeps records complete.', 'effective_from' => now()->toDateString()])->assertRedirect();
    $profile = PositionProfile::query()->where('position_id', $position->id)->firstOrFail();
    $this->actingAs($approver)->post(route('organization-design.position-profiles.publish', $profile))->assertRedirect();
    $this->actingAs($designer)->post(route('organization-design.job-descriptions.store', $position), ['effective_from' => now()->toDateString()])->assertRedirect();
    $jd = JobDescription::query()->where('position_id', $position->id)->firstOrFail();
    $this->actingAs($designer)->post(route('organization-design.job-descriptions.submit', $jd))->assertRedirect();
    $this->actingAs($approver)->post(route('organization-design.job-descriptions.approve', $jd))->assertRedirect();
    $this->actingAs($approver)->post(route('organization-design.job-descriptions.publish', $jd))->assertRedirect();
    expect($jd->fresh()->status)->toBe(OrganizationDesignStatus::Published)
        ->and($jd->sections()->where('section_key', 'duties')->value('content_en'))->toContain('T-1')
        ->and($jd->sections()->where('section_key', 'competencies')->first()->payload[0]['competency']['code'])->toBe('REC');
    $this->actingAs($designer)->get(route('organization-design.job-descriptions.pdf', ['jobDescription' => $jd, 'locale' => 'am']))->assertOk();

    // 8. Validation and organogram: no structural error; the organogram page derives from the live structure.
    $structure = app(OrganizationDesignValidationService::class)->validateStructure($organization->id);
    expect($structure['summary']['errors'])->toBe(0);
    $this->actingAs($designer)->get(route('organization-design.structure.index', ['organization_id' => $organization->id]))->assertOk();
    $this->actingAs($designer)->get(route('organizations.organogram', $organization))->assertOk();
    expect(app(OrganizationDesignGapService::class)->gaps($organization->id)['ok'])->toBeTrue();

    // 9. Traceability both ways.
    $up = app(TraceabilityService::class)->trace('position', $position->id);
    expect(collect($up['upstream'])->pluck('type')->all())->toContain('task', 'activity', 'process', 'service', 'function', 'mandate')
        ->and(collect($up['upstream'])->firstWhere('type', 'mandate')['code'])->toBe('MND-GP');
    $down = app(TraceabilityService::class)->trace('mandate', $mandate->id);
    expect(collect($down['downstream'])->pluck('type')->all())->toContain('function', 'service');
    $rows = app(OrganizationDesignReportService::class)->run('traceability', $approver, ['organization_id' => $organization->id])['rows'];
    expect(collect($rows)->firstWhere('task_code', 'T-1'))->toMatchArray(['mandate_code' => 'MND-GP', 'function_code' => 'F-1', 'service_code' => 'SRV-1', 'process_code' => 'PRC-1']);
    expect(collect($rows)->firstWhere('task_code', 'T-1')['positions'])->toContain($position->job_position_code)
        ->and(collect($rows)->firstWhere('task_code', 'T-1')['job_descriptions'])->toContain($jd->reference_number);

    // 10. Nobody was assigned: positions are slots, employees are a separate HR act.
    expect(EmployeeAssignment::query()->where('position_id', $position->id)->count())->toBe(0);
});
