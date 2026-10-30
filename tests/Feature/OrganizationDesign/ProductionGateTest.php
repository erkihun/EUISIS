<?php

declare(strict_types=1);

use App\Enums\OrganizationDesignStatus;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\InstitutionMandate;
use App\Models\JobDescription;
use App\Models\LegalInstrumentType;
use App\Models\ManpowerPlan;
use App\Models\ManpowerPlanLine;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\PositionProfile;
use App\Models\PositionRequirement;
use App\Models\PositionService;
use App\Models\PositionServiceSubService;
use App\Models\PositionServiceTask;
use App\Models\PositionServiceTaskStandard;
use App\Models\PositionWorkLink;
use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use App\Models\ResponsibilityType;
use App\Models\Service;
use App\Models\UnitResponsibility;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Models\WorkforcePlanningMethodology;
use App\Services\OrganizationDesign\TaskStandardService;
use App\Services\OrganizationDesign\JobDescriptionService;
use App\Services\OrganizationDesign\LegacyMappingService;
use App\Services\OrganizationDesign\OrganizationDesignGapService;
use App\Services\OrganizationDesign\PositionProfileService;
use App\Services\OrganizationDesign\WorkforcePlanningService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Organization Design & Workforce Planning, production gate: scoped server
 * search instead of preloaded option lists, workload provenance on manpower
 * lines, ownership and justification gaps, a bilingual job-description PDF,
 * the legacy-record classification, tampering refusals and historical
 * immutability. Tests 83–90.
 */

beforeEach(function (): void {
    $names = array_column(require base_path('database/seeders/data/organization-design-permissions.php'), 'name');
    foreach ([...$names, 'positions.view', 'work_standards.manage', 'work_standards.approve'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web');
    Role::findOrCreate('Designer', 'web')->syncPermissions([...array_filter($names, fn ($n) => ! str_ends_with($n, '.approve') && ! str_ends_with($n, '.publish') && ! str_ends_with($n, '.review')), 'work_standards.manage']);
    Role::findOrCreate('Approver', 'web')->syncPermissions([...$names, 'work_standards.manage', 'work_standards.approve']);
    Role::findOrCreate('Nobody', 'web')->syncPermissions([]);
    LegalInstrumentType::query()->firstOrCreate(['code' => 'PROCLAMATION'], ['name_en' => 'Proclamation', 'sort_order' => 10]);
});

function pgOrganization(string $suffix): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'PG-BUREAU'], ['name_en' => 'PG Bureau', 'is_demo' => false]);

    return Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'PG-'.$suffix.'-'.uniqid(), 'name_en' => 'PG Org '.$suffix, 'status' => OrganizationStatus::Active, 'effective_from' => now()->subYear()->toDateString(), 'is_demo' => false]);
}

function pgUser(Organization $organization, string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'scope_type' => OrganizationScopeType::Self, 'is_active' => true, 'effective_from' => now()->subDay()->toDateString()]);

    return $user->fresh();
}

function pgUnit(Organization $organization, string $code): OrganizationUnit
{
    $type = OrganizationUnitType::query()->firstOrCreate(['code' => 'PG-DIR'], ['name_en' => 'Directorate', 'is_active' => true]);

    return OrganizationUnit::query()->create(['organization_id' => $organization->id, 'organization_unit_type_id' => $type->id, 'code' => $code, 'name_en' => 'Unit '.$code, 'unit_type' => 'directorate', 'status' => 'active']);
}

function pgOccupation(): Occupation
{
    static $n = 0;
    $isco = '27'.str_pad((string) (++$n), 2, '0', STR_PAD_LEFT);

    return Occupation::query()->create(['code' => 'PG-'.$isco.'-'.substr(uniqid(), -3), 'isco_code' => $isco, 'name_en' => 'Occupation '.$isco, 'is_active' => true]);
}

function pgPosition(Organization $organization, OrganizationUnit $unit, string $code = 'P', ?Occupation $occupation = null): Position
{
    return Position::query()->create(['organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'occupation_id' => $occupation?->id, 'job_position_code' => $code.'-'.substr(uniqid(), -4), 'title_en' => 'Officer', 'grade_level' => 'IX', 'is_active' => true]);
}

/** A published service → process → activity → task, optionally owned by a unit. */
function pgTask(Organization $organization, string $code = 'T-1', ?OrganizationUnit $owner = null): ProcessTask
{
    $service = Service::query()->create(['organization_id' => $organization->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'SRV-'.substr(uniqid(), -4), 'name_en' => 'Service '.$code, 'owner_organization_unit_id' => $owner?->id, 'effective_from' => '2021-01-01', 'status' => OrganizationDesignStatus::Published]);
    $process = Process::query()->create(['organization_id' => $organization->id, 'service_id' => $service->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'PRC-'.substr(uniqid(), -4), 'name_en' => 'Process '.$code, 'process_type' => 'core', 'effective_from' => '2022-01-01', 'status' => OrganizationDesignStatus::Published]);
    $activity = ProcessActivity::query()->create(['process_id' => $process->id, 'organization_id' => $organization->id, 'code' => 'A-'.substr(uniqid(), -4), 'name_en' => 'Activity '.$code, 'path' => '', 'depth' => 0]);
    $activity->forceFill(['path' => $activity->id])->save();

    return ProcessTask::query()->create(['activity_id' => $activity->id, 'process_id' => $process->id, 'organization_id' => $organization->id, 'code' => $code, 'name_en' => 'Task '.$code]);
}

function pgPublishedJd(Organization $organization, Position $position, ProcessTask $task, User $designer, User $approver, string $from = '2025-01-01'): JobDescription
{
    $profiles = app(PositionProfileService::class);
    if (! PositionWorkLink::query()->where('position_id', $position->id)->exists()) {
        $profiles->syncWorkLinks($designer, $position, [['object_type' => 'task', 'object_id' => $task->id, 'is_primary' => true]]);
    }
    if (! $profiles->inForceFor($position)) {
        $profiles->publish($approver, $profiles->createDraft($designer, $position, ['purpose_en' => 'Purpose', 'purpose_am' => 'ዓላማ', 'effective_from' => $from]));
    }
    $jds = app(JobDescriptionService::class);
    $jd = $jds->createDraft($designer, $position, ['effective_from' => $from]);
    $jds->submit($designer, $jd);
    $jds->approve($approver, $jd);

    return $jds->publish($approver, $jd);
}

// 83 ─────────────────────────────────────────────────────────────────────────
test('pickers search the server inside the organization scope: bounded, filtered by code or name, refused outside the scope', function (): void {
    $mine = pgOrganization('A');
    $theirs = pgOrganization('A2');
    $designer = pgUser($mine, 'Designer');
    $nobody = pgUser($mine, 'Nobody');
    foreach (range(1, 40) as $i) {
        pgTask($mine, 'LK-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
    }
    $foreign = pgTask($theirs, 'LK-99');
    $unit = pgUnit($mine, 'U-1');
    $position = pgPosition($mine, $unit, 'LKP');

    $this->actingAs($nobody)->getJson(route('organization-design.lookups', ['kind' => 'tasks', 'organization_id' => $mine->id]))->assertForbidden();
    $this->actingAs($designer)->getJson(route('organization-design.lookups', ['kind' => 'tasks', 'organization_id' => $theirs->id]))->assertForbidden();
    $this->actingAs($designer)->getJson(route('organization-design.lookups', ['kind' => 'not-a-kind', 'organization_id' => $mine->id]))->assertNotFound();

    $all = $this->actingAs($designer)->getJson(route('organization-design.lookups', ['kind' => 'tasks', 'organization_id' => $mine->id]))->assertOk()->json();
    expect(count($all))->toBeLessThanOrEqual(30)->and(collect($all)->pluck('id')->all())->not->toContain($foreign->id)
        ->and($all[0])->toHaveKeys(['id', 'code', 'name_en', 'name_am', 'label']);
    $filtered = $this->actingAs($designer)->getJson(route('organization-design.lookups', ['kind' => 'tasks', 'organization_id' => $mine->id, 'q' => 'LK-07']))->assertOk()->json();
    expect(collect($filtered)->pluck('code')->all())->toBe(['LK-07']);
    $byName = $this->actingAs($designer)->getJson(route('organization-design.lookups', ['kind' => 'tasks', 'organization_id' => $mine->id, 'q' => 'task lk-12']))->assertOk()->json();
    expect(collect($byName)->pluck('code')->all())->toBe(['LK-12']);
    $positions = $this->actingAs($designer)->getJson(route('organization-design.lookups', ['kind' => 'positions', 'organization_id' => $mine->id, 'q' => 'LKP']))->assertOk()->json();
    expect($positions[0]['id'])->toBe($position->id)->and($positions[0]['code'])->toBe($position->job_position_code);
    foreach (['services', 'processes', 'activities', 'occupations', 'competencies', 'isco-groups', 'units'] as $kind) {
        $this->actingAs($designer)->getJson(route('organization-design.lookups', ['kind' => $kind, 'organization_id' => $mine->id]))->assertOk();
    }

    // The pages themselves no longer ship the whole catalog.
    $this->actingAs($designer)->get(route('organization-design.position-design.show', $position))
        ->assertInertia(fn ($page) => $page->component('OrganizationDesign/PositionDesign/Show')->missing('catalog.objects')->missing('catalog.positions'));
});

// 84 ─────────────────────────────────────────────────────────────────────────
test('every manpower line records where its workload came from; a plan with an unexplained line cannot be submitted', function (): void {
    $organization = pgOrganization('B');
    $planner = pgUser($organization, 'Designer');
    $unit = pgUnit($organization, 'U-1');
    $occupation = pgOccupation();
    $methodology = WorkforcePlanningMethodology::query()->create(['code' => 'PG-FM-'.substr(uniqid(), -4), 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'name_en' => 'Fixed', 'calculation_type' => 'FIXED_MINIMUM', 'parameters' => ['minimum_positions' => '2'], 'rounding_rule' => 'ceil', 'status' => OrganizationDesignStatus::Published, 'effective_from' => '2024-01-01']);
    $this->actingAs($planner)->post(route('organization-design.manpower-plans.store'), ['organization_id' => $organization->id, 'title_en' => 'Plan', 'methodology_id' => $methodology->id, 'planning_period_start' => '2027-01-01', 'planning_period_end' => '2027-12-31'])->assertRedirect();
    $plan = ManpowerPlan::query()->where('organization_id', $organization->id)->firstOrFail();

    $this->actingAs($planner)->from('/')->post(route('organization-design.manpower-plans.lines.store', $plan), ['organization_unit_id' => $unit->id, 'occupation_id' => $occupation->id, 'inputs' => [], 'workload_source_type' => 'guesswork'])->assertSessionHasErrors('workload_source_type');
    $this->actingAs($planner)->post(route('organization-design.manpower-plans.lines.store', $plan), ['organization_unit_id' => $unit->id, 'occupation_id' => $occupation->id, 'inputs' => []])->assertRedirect();
    $unexplained = ManpowerPlanLine::query()->where('plan_id', $plan->id)->firstOrFail();
    $this->actingAs($planner)->post(route('organization-design.manpower-plans.lines.store', $plan), ['organization_unit_id' => $unit->id, 'occupation_id' => $occupation->id, 'inputs' => [], 'workload_source_type' => 'approved_forecast', 'workload_source_reference' => 'Service forecast 2027, annex B', 'sequence' => 2])->assertRedirect();
    $explained = ManpowerPlanLine::query()->where('plan_id', $plan->id)->where('sequence', 2)->firstOrFail();
    expect($explained->workload_source_type)->toBe('approved_forecast')->and($explained->workload_source_reference)->toBe('Service forecast 2027, annex B');

    $this->actingAs($planner)->post(route('organization-design.manpower-plans.calculate', $plan))->assertRedirect();
    $report = app(WorkforcePlanningService::class)->validatePlan($plan->fresh());
    $codes = collect($report['findings'])->map(fn ($f) => $f['code'].':'.$f['subject']['id']);
    expect($report['ok'])->toBeFalse()->and($codes)->toContain('line_without_workload_source:'.$unexplained->id)->and($codes)->not->toContain('line_without_workload_source:'.$explained->id);
    $this->actingAs($planner)->from('/')->post(route('organization-design.manpower-plans.submit', $plan))->assertSessionHasErrors('validation');

    $this->actingAs($planner)->put(route('organization-design.manpower-plans.lines.update', $unexplained), ['workload_source_type' => 'work_measurement_study', 'workload_source_reference' => 'Time study, March 2026'])->assertRedirect();
    $this->actingAs($planner)->post(route('organization-design.manpower-plans.calculate', $plan))->assertRedirect();
    expect(app(WorkforcePlanningService::class)->validatePlan($plan->fresh())['ok'])->toBeTrue();
    $this->actingAs($planner)->post(route('organization-design.manpower-plans.submit', $plan))->assertRedirect();
    expect($plan->fresh()->status)->toBe(OrganizationDesignStatus::UnderReview);
});

// 85 ─────────────────────────────────────────────────────────────────────────
test('the gap report also sees services without an owner, owners that are not live, positions without an occupation and approved requirements that never became positions', function (): void {
    $organization = pgOrganization('C');
    $other = pgOrganization('C2');
    $unit = pgUnit($organization, 'U-1');
    $foreignUnit = pgUnit($other, 'U-X');
    $inactiveUnit = pgUnit($organization, 'U-OLD');
    $inactiveUnit->forceFill(['status' => 'inactive'])->save();
    $owned = pgTask($organization, 'T-OWNED', $unit);
    $orphan = pgTask($organization, 'T-ORPHAN');
    $foreignOwned = pgTask($organization, 'T-FOREIGN', $foreignUnit);
    $inactiveOwned = pgTask($organization, 'T-INACTIVE', $inactiveUnit);
    $noOccupation = pgPosition($organization, $unit, 'P-NOOCC');
    $withOccupation = pgPosition($organization, $unit, 'P-OCC', pgOccupation());
    $methodology = WorkforcePlanningMethodology::query()->create(['code' => 'PG-GAP-'.substr(uniqid(), -4), 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'name_en' => 'Fixed', 'calculation_type' => 'FIXED_MINIMUM', 'parameters' => ['minimum_positions' => '2'], 'rounding_rule' => 'ceil', 'status' => OrganizationDesignStatus::Published, 'effective_from' => '2024-01-01']);
    $plan = ManpowerPlan::query()->create(['reference_number' => 'MPP-GAP-'.substr(uniqid(), -4), 'organization_id' => $organization->id, 'methodology_id' => $methodology->id, 'title_en' => 'Plan', 'planning_period_start' => '2027-01-01', 'planning_period_end' => '2027-12-31', 'status' => OrganizationDesignStatus::Approved]);
    $requirement = PositionRequirement::query()->create(['plan_id' => $plan->id, 'organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'occupation_id' => pgOccupation()->id, 'position_title_en' => 'Planner', 'required_fte' => '2.0000', 'approved_headcount' => 2, 'status' => 'approved', 'sequence' => 1]);

    $findings = collect(app(OrganizationDesignGapService::class)->gaps($organization->id)['findings'])->map(fn ($f) => $f['code'].':'.($f['subject']['code'] ?? $f['subject']['id']));
    expect($findings)->toContain('service_without_owner_unit:'.$orphan->process->service->code)
        ->and($findings)->not->toContain('service_without_owner_unit:'.$owned->process->service->code)
        ->and($findings)->toContain('service_owner_unit_invalid:'.$foreignOwned->process->service->code)
        ->and($findings)->toContain('service_owner_unit_invalid:'.$inactiveOwned->process->service->code)
        ->and($findings)->toContain('position_without_occupation:'.$noOccupation->job_position_code)
        ->and($findings)->not->toContain('position_without_occupation:'.$withOccupation->job_position_code)
        ->and($findings)->toContain('approved_requirement_without_position:'.$requirement->id);
});

// 86 ─────────────────────────────────────────────────────────────────────────
test('a job description prints as a bilingual PDF through the document fonts, dated in the Gregorian calendar in English and the Ethiopian calendar in Amharic', function (): void {
    $organization = pgOrganization('D');
    $other = pgOrganization('D2');
    $designer = pgUser($organization, 'Designer');
    $approver = pgUser($organization, 'Approver');
    $outsider = pgUser($other, 'Approver');
    $unit = pgUnit($organization, 'U-1');
    $position = pgPosition($organization, $unit, 'P-PDF', pgOccupation());
    $jd = pgPublishedJd($organization, $position, pgTask($organization, 'T-PDF'), $designer, $approver);

    $this->actingAs($outsider)->get(route('organization-design.job-descriptions.pdf', $jd))->assertForbidden();
    $en = $this->actingAs($designer)->get(route('organization-design.job-descriptions.pdf', ['jobDescription' => $jd, 'locale' => 'en']))->assertOk();
    expect($en->headers->get('content-type'))->toContain('application/pdf')->and($en->headers->get('content-disposition'))->toContain('.pdf');
    $am = $this->actingAs($designer)->get(route('organization-design.job-descriptions.pdf', ['jobDescription' => $jd, 'locale' => 'am']))->assertOk();
    expect($am->headers->get('content-type'))->toContain('application/pdf');
    $html = $this->actingAs($designer)->get(route('organization-design.job-descriptions.pdf', ['jobDescription' => $jd, 'locale' => 'am', 'preview' => 1]))->assertOk()->getContent();
    expect($html)->toContain('ዓላማ')->toContain($jd->reference_number)->toContain('@font-face')->not->toContain('font-family: DejaVu Sans');
    expect($html)->toMatch('/\d{4}/');
});

// 87 ─────────────────────────────────────────────────────────────────────────
test('legacy units, positions and position services are classified as FULLY_MAPPED, PARTIALLY_MAPPED, UNMAPPED_LEGACY or INVALID_REFERENCE — never auto-linked', function (): void {
    $organization = pgOrganization('E');
    $designer = pgUser($organization, 'Designer');
    $approver = pgUser($organization, 'Approver');
    $unit = pgUnit($organization, 'U-FULL');
    $partialUnit = pgUnit($organization, 'U-PART');
    $bareUnit = pgUnit($organization, 'U-BARE');
    $task = pgTask($organization, 'T-1', $unit);
    $full = pgPosition($organization, $unit, 'P-FULL', pgOccupation());
    pgPublishedJd($organization, $full, $task, $designer, $approver);
    $partial = pgPosition($organization, $partialUnit, 'P-PART', pgOccupation());
    $bare = pgPosition($organization, $bareUnit, 'P-BARE');
    $broken = pgPosition($organization, $unit, 'P-BROKEN');
    PositionWorkLink::query()->create(['position_id' => $broken->id, 'organization_id' => $organization->id, 'object_type' => 'task', 'object_id' => (string) Str::uuid()]);
    $responsible = ResponsibilityType::query()->create(['code' => 'PG-R', 'name_en' => 'Responsible', 'is_exclusive' => false, 'covers_object' => true]);
    UnitResponsibility::query()->create(['organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'object_type' => 'service', 'object_id' => $task->process->service_id, 'responsibility_type_id' => $responsible->id]);
    // U-PART holds a responsibility but its position is not linked to any work: partially mapped.
    UnitResponsibility::query()->create(['organization_id' => $organization->id, 'organization_unit_id' => $partialUnit->id, 'object_type' => 'process', 'object_id' => $task->process_id, 'responsibility_type_id' => $responsible->id]);

    $legacy = function (Position $position, string $code, ?string $master = null, bool $mapTask = false) use ($organization): PositionService {
        $service = PositionService::query()->create(['organization_id' => $organization->id, 'position_id' => $position->id, 'service_no' => $code, 'name_en' => 'Legacy '.$code, 'is_active' => true, 'master_service_id' => $master]);
        $sub = PositionServiceSubService::query()->create(['position_service_id' => $service->id, 'organization_id' => $organization->id, 'code' => 'SS-'.$code, 'name_en' => 'Sub']);
        PositionServiceTask::query()->create(['sub_service_id' => $sub->id, 'position_service_id' => $service->id, 'organization_id' => $organization->id, 'code' => 'PT-'.$code, 'name_en' => 'Legacy task', 'is_active' => true, 'master_task_id' => $mapTask ? ProcessTask::query()->where('organization_id', $organization->id)->value('id') : null]);

        return $service;
    };
    $fullService = $legacy($full, 'PS-FULL', $task->process->service_id, true);
    $partialService = $legacy($partial, 'PS-PART', $task->process->service_id, false);
    $bareService = $legacy($bare, 'PS-BARE');
    $foreignService = Service::query()->create(['organization_id' => pgOrganization('E2')->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'SRV-FOREIGN', 'name_en' => 'Foreign', 'effective_from' => '2021-01-01', 'status' => OrganizationDesignStatus::Published]);
    $invalidService = $legacy($bare, 'PS-INVALID', $foreignService->id);

    $report = app(LegacyMappingService::class)->classify($organization->id);
    $status = fn (string $kind, string $id) => collect($report[$kind])->firstWhere('id', $id)['status'] ?? null;
    expect($status('units', $unit->id))->toBe('FULLY_MAPPED')
        ->and($status('units', $partialUnit->id))->toBe('PARTIALLY_MAPPED')
        ->and($status('units', $bareUnit->id))->toBe('UNMAPPED_LEGACY')
        ->and($status('positions', $full->id))->toBe('FULLY_MAPPED')
        ->and($status('positions', $partial->id))->toBe('PARTIALLY_MAPPED')
        ->and($status('positions', $bare->id))->toBe('UNMAPPED_LEGACY')
        ->and($status('positions', $broken->id))->toBe('INVALID_REFERENCE')
        ->and($status('position_services', $fullService->id))->toBe('FULLY_MAPPED')
        ->and($status('position_services', $partialService->id))->toBe('PARTIALLY_MAPPED')
        ->and($status('position_services', $bareService->id))->toBe('UNMAPPED_LEGACY')
        ->and($status('position_services', $invalidService->id))->toBe('INVALID_REFERENCE')
        ->and($report['summary']['positions'])->toMatchArray(['FULLY_MAPPED' => 1, 'PARTIALLY_MAPPED' => 1, 'UNMAPPED_LEGACY' => 1, 'INVALID_REFERENCE' => 1]);

    // Mapping a legacy position service to a master service is a person's explicit act, inside the organization.
    $this->actingAs($designer)->from('/')->put(route('organization-design.mapping.position-services.link', $bareService), ['master_service_id' => $foreignService->id])->assertSessionHasErrors('master_service_id');
    $this->actingAs($designer)->put(route('organization-design.mapping.position-services.link', $bareService), ['master_service_id' => $task->process->service_id])->assertRedirect();
    expect($bareService->fresh()->master_service_id)->toBe($task->process->service_id);
    $this->actingAs($designer)->get(route('organization-design.mapping.index', ['organization_id' => $organization->id]))
        ->assertInertia(fn ($page) => $page->has('unmapped.position_services')->has('legacy.summary'));
    $this->artisan('organization-design:legacy-report', ['--organization' => $organization->id])->assertSuccessful();
});

// 88 ─────────────────────────────────────────────────────────────────────────
test('published records refuse status, approval and effective-date tampering and hidden calculation fields through every write route', function (): void {
    $organization = pgOrganization('F');
    $designer = pgUser($organization, 'Designer');
    $approver = pgUser($organization, 'Approver');
    $unit = pgUnit($organization, 'U-1');
    $position = pgPosition($organization, $unit, 'P-TAMPER', pgOccupation());
    $task = pgTask($organization, 'T-TAMPER');
    $jd = pgPublishedJd($organization, $position, $task, $designer, $approver);
    $mandate = InstitutionMandate::query()->create(['organization_id' => $organization->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'MND-T', 'title_en' => 'Mandate', 'legal_instrument_type_id' => LegalInstrumentType::query()->value('id'), 'legal_instrument_number' => '1/2020', 'effective_from' => '2020-01-01', 'status' => OrganizationDesignStatus::Published, 'approved_by' => $approver->id]);

    // A published mandate cannot be edited at all, whatever the body says.
    $this->actingAs($approver)->from('/')->put(route('organization-design.mandates.update', $mandate), ['title_en' => 'Changed', 'status' => 'draft', 'effective_from' => '1999-01-01', 'approved_by' => $designer->id])->assertSessionHasErrors('status');
    expect($mandate->fresh()->only(['title_en', 'effective_from', 'approved_by']))->toMatchArray(['title_en' => 'Mandate', 'approved_by' => $approver->id]);
    // A published job description's custom section is immutable; its status cannot be pushed back.
    $this->actingAs($designer)->from('/')->put(route('organization-design.job-descriptions.sections.update', [$jd, 'authorities']), ['content_en' => 'x', 'status' => 'draft'])->assertSessionHasErrors('status');
    expect($jd->fresh()->status)->toBe(OrganizationDesignStatus::Published);
    // A profile cannot be published twice nor re-dated once published.
    $profile = app(PositionProfileService::class)->inForceFor($position);
    $this->actingAs($approver)->from('/')->post(route('organization-design.position-profiles.publish', $profile))->assertSessionHasErrors('status');
    $this->actingAs($designer)->from('/')->put(route('organization-design.position-profiles.update', $profile), ['effective_from' => '2010-01-01'])->assertSessionHasErrors('status');
    expect($profile->fresh()->effective_from->toDateString())->toBe('2025-01-01');
    // A standard that is approved cannot be edited; a draft standard ignores a status in the body.
    $standard = PositionServiceTaskStandard::query()->create(['master_task_id' => $task->id, 'organization_id' => $organization->id, 'version_no' => 1, 'status' => 'approved', 'planned_quantity' => '10', 'quantity_unit' => 'items', 'effective_from' => '2024-01-01']);
    $this->actingAs($designer)->from('/')->put(route('organization-design.standards.update', $standard), ['planned_quantity' => '1', 'status' => 'draft'])->assertSessionHasErrors();
    expect($standard->fresh()->planned_quantity)->not->toBe('1.0000');
});

// 89 ─────────────────────────────────────────────────────────────────────────
test('a new version never rewrites history: version 1 of a profile, job description and standard stay as they were', function (): void {
    $organization = pgOrganization('G');
    $designer = pgUser($organization, 'Designer');
    $approver = pgUser($organization, 'Approver');
    $unit = pgUnit($organization, 'U-1');
    $position = pgPosition($organization, $unit, 'P-HIST', pgOccupation());
    $task = pgTask($organization, 'T-HIST');
    $jd1 = pgPublishedJd($organization, $position, $task, $designer, $approver, '2025-01-01');
    $sections1 = $jd1->sections()->get()->map->only(['section_key', 'content_en', 'payload'])->all();
    $profile1 = app(PositionProfileService::class)->inForceFor($position);
    $standard1 = PositionServiceTaskStandard::query()->create(['master_task_id' => $task->id, 'organization_id' => $organization->id, 'version_no' => 1, 'status' => 'approved', 'planned_quantity' => '10', 'quantity_unit' => 'items', 'effective_from' => '2024-01-01']);

    // Version 2 of the profile, the job description and the standard.
    $profiles = app(PositionProfileService::class);
    $profile2 = $profiles->newVersion($designer, $profile1, ['purpose_en' => 'New purpose', 'effective_from' => '2026-01-01']);
    $profiles->publish($approver, $profile2);
    $jds = app(JobDescriptionService::class);
    $jd2 = $jds->newVersion($designer, $jd1, ['effective_from' => '2026-02-01']);
    $jds->updateSection($designer, $jd2, 'authorities', ['content_en' => 'Version two authorities']);
    $jds->submit($designer, $jd2);
    $jds->approve($approver, $jd2);
    $jds->publish($approver, $jd2);
    $standard2 = app(TaskStandardService::class)->createDraft($designer, $task, ['planned_quantity' => '20', 'quantity_unit' => 'items', 'effective_from' => now()->addDay()->toDateString()]);
    app(TaskStandardService::class)->approve($approver, $standard2);

    expect($profile1->fresh()->status)->toBe(OrganizationDesignStatus::Superseded)->and($profile1->fresh()->purpose_en)->toBe('Purpose')->and($profile1->fresh()->effective_to?->toDateString())->toBe('2025-12-31')
        ->and($jd1->fresh()->status)->toBe(OrganizationDesignStatus::Superseded)->and($jd1->fresh()->effective_to?->toDateString())->toBe('2026-01-31')
        ->and($jd1->sections()->get()->map->only(['section_key', 'content_en', 'payload'])->all())->toEqual($sections1)
        ->and($jd2->fresh()->position_profile_id)->toBe($profile2->id)
        ->and($standard1->fresh()->planned_quantity)->toBe('10.0000')->and($standard1->fresh()->status->value)->toBe('approved')
        ->and($jds->inForceFor($position, '2025-06-01')?->id)->toBe($jd1->id)
        ->and($jds->inForceFor($position, '2026-06-01')?->id)->toBe($jd2->id);
    $this->actingAs($designer)->get(route('organization-design.job-descriptions.show', $jd1))->assertOk()
        ->assertInertia(fn ($page) => $page->where('jobDescription.status', 'superseded')->has('versions', 2));
});

// 90 ─────────────────────────────────────────────────────────────────────────
test('double approval, double publication and a duplicate requirement are refused by state and constraints, not by luck', function (): void {
    $organization = pgOrganization('H');
    $designer = pgUser($organization, 'Designer');
    $approver = pgUser($organization, 'Approver');
    $second = pgUser($organization, 'Approver');
    $unit = pgUnit($organization, 'U-1');
    $position = pgPosition($organization, $unit, 'P-DBL', pgOccupation());
    $task = pgTask($organization, 'T-DBL');
    $profiles = app(PositionProfileService::class);
    $profiles->syncWorkLinks($designer, $position, [['object_type' => 'task', 'object_id' => $task->id]]);
    $draft = $profiles->createDraft($designer, $position, ['purpose_en' => 'P', 'effective_from' => '2025-01-01']);
    $profiles->publish($approver, $draft);
    $this->actingAs($second)->from('/')->post(route('organization-design.position-profiles.publish', $draft))->assertSessionHasErrors('status');
    expect(PositionProfile::query()->where('position_id', $position->id)->where('status', OrganizationDesignStatus::Published)->count())->toBe(1);

    $jds = app(JobDescriptionService::class);
    $jd = $jds->createDraft($designer, $position, ['effective_from' => '2025-01-01']);
    $jds->submit($designer, $jd);
    $jds->approve($approver, $jd);
    $this->actingAs($second)->from('/')->post(route('organization-design.job-descriptions.approve', $jd))->assertSessionHasErrors('status');
    $jds->publish($approver, $jd);
    $this->actingAs($second)->from('/')->post(route('organization-design.job-descriptions.publish', $jd))->assertSessionHasErrors('status');
    expect(JobDescription::query()->where('position_id', $position->id)->where('status', OrganizationDesignStatus::Published)->count())->toBe(1)
        ->and($jd->fresh()->published_by)->toBe($approver->id);

    // One lineage version at a time, one current requirement row per (unit, occupation, profession) in a plan.
    expect(fn () => JobDescription::query()->create(['position_id' => $position->id, 'organization_id' => $organization->id, 'position_profile_id' => $draft->id, 'lineage_key' => $jd->lineage_key, 'version_no' => $jd->version_no, 'status' => 'draft', 'effective_from' => '2027-01-01']))
        ->toThrow(UniqueConstraintViolationException::class);
});
