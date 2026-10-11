<?php

declare(strict_types=1);

use App\Enums\OrganizationDesignStatus;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\AuditLog;
use App\Models\Competency;
use App\Models\CompetencyFramework;
use App\Models\CompetencyType;
use App\Models\IscoGroup;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\PositionService;
use App\Models\PositionServiceSubService;
use App\Models\PositionServiceTask;
use App\Models\PositionServiceTaskStandard;
use App\Models\PositionWorkLink;
use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use App\Models\Service;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\OrganizationDesign\DataQualityService;
use App\Services\OrganizationDesign\MappingWorkspaceService;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Organization Design & Workforce Planning, phase 10: the mapping workspace
 * for records that pre-date the design (UNMAPPED_EXISTING_RECORD), the
 * data-quality report, and the rule that nothing ambiguous is ever mapped
 * automatically (NEEDS_DATA_REVIEW). Brief §116–118, D-11; tests 75–79.
 */

beforeEach(function (): void {
    foreach (['data-mapping.view', 'processes.update', 'professions.manage', 'competency-catalog.manage', 'organization-design.viewAny'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web');
    Role::findOrCreate('Mapping Viewer', 'web')->syncPermissions(['data-mapping.view']);
    Role::findOrCreate('Mapping Designer', 'web')->syncPermissions(['data-mapping.view', 'processes.update']);
    Role::findOrCreate('Catalog Manager', 'web')->syncPermissions(['data-mapping.view', 'professions.manage', 'competency-catalog.manage']);
    Role::findOrCreate('Nobody', 'web')->syncPermissions([]);
});

function mwOrganization(string $suffix): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'MW-BUREAU'], ['name_en' => 'MW Bureau', 'is_demo' => false]);

    return Organization::query()->create([
        'organization_type_id' => $type->id, 'code' => 'MW-'.$suffix.'-'.uniqid(), 'name_en' => 'MW Org '.$suffix,
        'status' => OrganizationStatus::Active, 'effective_from' => now()->subYear()->toDateString(), 'is_demo' => false,
    ]);
}

function mwUser(Organization $organization, string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'scope_type' => OrganizationScopeType::Self, 'is_active' => true, 'effective_from' => now()->subDay()->toDateString()]);

    return $user->fresh();
}

function mwUnit(Organization $organization, string $code): OrganizationUnit
{
    $type = OrganizationUnitType::query()->firstOrCreate(['code' => 'MW-DIR'], ['name_en' => 'Directorate', 'is_active' => true]);

    return OrganizationUnit::query()->create(['organization_id' => $organization->id, 'organization_unit_type_id' => $type->id, 'code' => $code, 'name_en' => 'Unit '.$code, 'unit_type' => 'directorate', 'status' => 'active']);
}

function mwPosition(Organization $organization, OrganizationUnit $unit, string $code = 'P-1'): Position
{
    return Position::query()->create(['organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'job_position_code' => $code.'-'.substr(uniqid(), -4), 'title_en' => 'Officer', 'grade_level' => 'IX', 'is_active' => true]);
}

/** A master task of a published process. */
function mwMasterTask(Organization $organization, string $code, string $name, bool $active = true): ProcessTask
{
    static $processes = [];
    $process = $processes[$organization->id] ??= (function () use ($organization): Process {
        $service = Service::query()->create(['organization_id' => $organization->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'SRV-'.substr(uniqid(), -4), 'name_en' => 'Service', 'effective_from' => '2021-01-01', 'status' => OrganizationDesignStatus::Published]);

        return Process::query()->create(['organization_id' => $organization->id, 'service_id' => $service->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'PRC-'.substr(uniqid(), -4), 'name_en' => 'Process', 'process_type' => 'core', 'effective_from' => '2022-01-01', 'status' => OrganizationDesignStatus::Published]);
    })();
    $activity = ProcessActivity::query()->create(['process_id' => $process->id, 'organization_id' => $organization->id, 'code' => 'A-'.substr(uniqid(), -4), 'name_en' => 'Activity', 'path' => '', 'depth' => 0]);
    $activity->forceFill(['path' => $activity->id])->save();

    return ProcessTask::query()->create(['activity_id' => $activity->id, 'process_id' => $process->id, 'organization_id' => $organization->id, 'code' => $code, 'name_en' => $name, 'is_active' => $active]);
}

/** A legacy EPMS position task (pre-dates the design) with its own approved standard. */
function mwLegacyTask(Organization $organization, Position $position, string $code, string $name): PositionServiceTask
{
    $positionService = PositionService::query()->create(['organization_id' => $organization->id, 'position_id' => $position->id, 'service_no' => (string) random_int(1, 999999), 'name_en' => 'Legacy service', 'is_active' => true]);
    $sub = PositionServiceSubService::query()->create(['position_service_id' => $positionService->id, 'organization_id' => $organization->id, 'code' => 'SS-'.substr(uniqid(), -4), 'name_en' => 'Sub']);
    $task = PositionServiceTask::query()->create(['sub_service_id' => $sub->id, 'position_service_id' => $positionService->id, 'organization_id' => $organization->id, 'code' => $code, 'name_en' => $name, 'is_active' => true]);
    PositionServiceTaskStandard::query()->create(['task_id' => $task->id, 'organization_id' => $organization->id, 'version_no' => 1, 'status' => 'approved', 'planned_quantity' => '12', 'quantity_unit' => 'files', 'effective_from' => '2024-01-01']);

    return $task;
}

// 75 ─────────────────────────────────────────────────────────────────────────
test('the workspace lists unmapped legacy records with suggestions — one exact match, several matches need review — and maps nothing by itself', function (): void {
    $organization = mwOrganization('A');
    $unit = mwUnit($organization, 'U-1');
    $emptyUnit = mwUnit($organization, 'U-EMPTY');
    $position = mwPosition($organization, $unit);
    $exact = mwMasterTask($organization, 'T-100', 'Screen applications');
    mwMasterTask($organization, 'T-200', 'Verify documents');
    mwMasterTask($organization, 'T-201', 'Verify documents');
    $byCode = mwLegacyTask($organization, $position, 'T-100', 'Old screening');
    $byName = mwLegacyTask($organization, $position, 'PT-9', 'verify documents');
    $none = mwLegacyTask($organization, $position, 'PT-7', 'Archive files');
    $mapped = mwLegacyTask($organization, $position, 'PT-8', 'Already mapped');
    $mapped->forceFill(['master_task_id' => $exact->id])->save();

    $unmapped = app(MappingWorkspaceService::class)->unmapped($organization->id);
    $tasks = collect($unmapped['position_tasks'])->keyBy('id');
    expect($tasks)->toHaveCount(3)->and($tasks->has($mapped->id))->toBeFalse()
        ->and($tasks[$byCode->id]['flag'])->toBe('UNMAPPED_EXISTING_RECORD')
        ->and($tasks[$byCode->id]['suggestions'])->toHaveCount(1)
        ->and($tasks[$byCode->id]['suggestions'][0]['id'])->toBe($exact->id)
        ->and($tasks[$byCode->id]['needs_review'])->toBeFalse()
        ->and($tasks[$byName->id]['suggestions'])->toHaveCount(2)
        ->and($tasks[$byName->id]['needs_review'])->toBeTrue()
        ->and($tasks[$byName->id]['flag'])->toBe('NEEDS_DATA_REVIEW')
        ->and($tasks[$none->id]['suggestions'])->toBe([])
        ->and($tasks[$byCode->id]['position']['code'])->toBe($position->job_position_code);
    expect(collect($unmapped['positions'])->pluck('id')->all())->toBe([$position->id])
        ->and(collect($unmapped['units'])->pluck('code')->all())->toBe(['U-1', 'U-EMPTY']);
    expect(PositionServiceTask::query()->whereNull('master_task_id')->where('organization_id', $organization->id)->count())->toBe(3);
});

// 76 ─────────────────────────────────────────────────────────────────────────
test('applying suggestions maps only the unambiguous exact-code matches, keeps every legacy standard untouched, is audited and idempotent', function (): void {
    $organization = mwOrganization('B');
    $other = mwOrganization('B2');
    $position = mwPosition($organization, mwUnit($organization, 'U-1'));
    $exact = mwMasterTask($organization, 'T-100', 'Screen applications');
    mwMasterTask($organization, 'T-300', 'Verify documents');
    mwMasterTask($organization, 'T-301', 'Verify documents');
    $byCode = mwLegacyTask($organization, $position, 'T-100', 'Old screening');
    $byName = mwLegacyTask($organization, $position, 'PT-9', 'Verify documents');
    $none = mwLegacyTask($organization, $position, 'PT-7', 'Archive files');
    $foreignPosition = mwPosition($other, mwUnit($other, 'U-X'));
    mwMasterTask($other, 'T-700', 'Foreign');
    $foreign = mwLegacyTask($other, $foreignPosition, 'T-700', 'Foreign legacy');
    $standardsBefore = PositionServiceTaskStandard::query()->where('task_id', $byCode->id)->get()->map->only(['id', 'status', 'planned_quantity', 'effective_from'])->all();
    $designer = mwUser($organization, 'Mapping Designer');

    $this->actingAs($designer)->post(route('organization-design.mapping.apply', ['organization_id' => $organization->id]))->assertRedirect();
    expect($byCode->fresh()->master_task_id)->toBe($exact->id)
        ->and($byName->fresh()->master_task_id)->toBeNull()
        ->and($none->fresh()->master_task_id)->toBeNull()
        ->and($foreign->fresh()->master_task_id)->toBeNull();
    expect(PositionServiceTaskStandard::query()->where('task_id', $byCode->id)->get()->map->only(['id', 'status', 'planned_quantity', 'effective_from'])->all())->toEqual($standardsBefore)
        ->and(PositionServiceTaskStandard::query()->where('master_task_id', $exact->id)->count())->toBe(0);
    expect(AuditLog::query()->where('event_type', 'organization_design.position_task_linked')->count())->toBe(1)
        ->and(AuditLog::query()->where('event_type', 'organization_design.mapping_suggestions_applied')->count())->toBe(1);

    $result = app(MappingWorkspaceService::class)->applySuggestions($designer, $organization->id);
    expect($result)->toBe(['mapped' => 0, 'review' => 1, 'none' => 1]);

    $viewer = mwUser($organization, 'Mapping Viewer');
    $this->actingAs($viewer)->post(route('organization-design.mapping.apply', ['organization_id' => $organization->id]))->assertForbidden();
    $this->actingAs($designer)->post(route('organization-design.mapping.apply', ['organization_id' => $other->id]))->assertForbidden();
});

// 77 ─────────────────────────────────────────────────────────────────────────
test('the data-quality report counts unmapped records, flags ambiguous ones for review, and warns about links to inactive or missing design objects', function (): void {
    $organization = mwOrganization('C');
    $unit = mwUnit($organization, 'U-1');
    $position = mwPosition($organization, $unit);
    $inactive = mwMasterTask($organization, 'T-OLD', 'Retired task', active: false);
    mwMasterTask($organization, 'T-400', 'Check');
    mwMasterTask($organization, 'T-401', 'Check');
    $stale = mwLegacyTask($organization, $position, 'PT-1', 'Stale');
    $stale->forceFill(['master_task_id' => $inactive->id])->save();
    mwLegacyTask($organization, $position, 'PT-2', 'check');
    mwLegacyTask($organization, $position, 'PT-3', 'Nothing alike');
    PositionWorkLink::query()->create(['position_id' => $position->id, 'organization_id' => $organization->id, 'object_type' => 'task', 'object_id' => (string) Str::uuid()]);

    $report = app(DataQualityService::class)->check($organization->id);
    $byCode = collect($report['findings'])->groupBy('code');
    expect($report['ok'])->toBeTrue()
        ->and($byCode->get('unmapped_position_tasks')[0]['severity'])->toBe('info')
        ->and($byCode->get('unmapped_position_tasks')[0]['params']['count'])->toBe(2)
        ->and($byCode->get('needs_data_review'))->toHaveCount(1)
        ->and($byCode->get('needs_data_review')[0]['subject']['code'])->toBe('PT-2')
        ->and($byCode->get('position_task_master_inactive')[0]['severity'])->toBe('warning')
        ->and($byCode->get('position_task_master_inactive')[0]['subject']['code'])->toBe('PT-1')
        ->and($byCode->get('work_link_object_missing')[0]['severity'])->toBe('warning')
        ->and($byCode->get('work_link_object_missing')[0]['subject']['code'])->toBe($position->job_position_code)
        ->and($byCode->get('unmapped_positions'))->toBeNull()
        ->and($byCode->get('unmapped_units')[0]['params']['count'])->toBe(1);
    expect($report['findings'][0]['message'])->not->toContain('organization-design.validation');
});

// 78 ─────────────────────────────────────────────────────────────────────────
test('city-level catalog records are mapped from the workspace: occupations to ISCO groups, competencies to types — behind their own permissions', function (): void {
    $organization = mwOrganization('D');
    $manager = mwUser($organization, 'Catalog Manager');
    $designer = mwUser($organization, 'Mapping Designer');
    $group = IscoGroup::query()->create(['revision' => 'ISCO-08', 'code' => '2411', 'level' => 'unit', 'name_en' => 'Accountants', 'is_active' => true]);
    IscoGroup::query()->create(['revision' => 'ISCO-08', 'code' => '2412', 'level' => 'unit', 'name_en' => 'Financial advisers', 'is_active' => true]);
    $occupation = Occupation::query()->create(['code' => 'MW-OCC-'.substr(uniqid(), -4), 'isco_code' => '2411', 'name_en' => 'Accountant', 'is_active' => true]);
    $mappedOccupation = Occupation::query()->create(['code' => 'MW-OCC2-'.substr(uniqid(), -4), 'isco_code' => '2412', 'name_en' => 'Adviser', 'is_active' => true, 'isco_group_id' => $group->id]);
    $type = CompetencyType::query()->firstOrCreate(['code' => 'TECHNICAL'], ['name_en' => 'Technical', 'is_active' => true]);
    $framework = CompetencyFramework::query()->firstOrCreate(['code' => 'MW-FW'], ['name_en' => 'MW framework', 'is_active' => true]);
    $competency = Competency::query()->create(['framework_id' => $framework->id, 'code' => 'MW-'.substr(uniqid(), -4), 'name_en' => 'Bookkeeping', 'is_active' => true]);

    $catalog = app(MappingWorkspaceService::class)->unmappedCatalog();
    $occupations = collect($catalog['occupations'])->keyBy('id');
    expect($occupations->has($occupation->id))->toBeTrue()->and($occupations->has($mappedOccupation->id))->toBeFalse()
        ->and($occupations[$occupation->id]['suggestions'][0]['id'])->toBe($group->id)
        ->and(collect($catalog['competencies'])->pluck('id')->all())->toContain($competency->id);

    $this->actingAs($designer)->put(route('organization-design.mapping.occupations.link', $occupation), ['isco_group_id' => $group->id])->assertForbidden();
    $this->actingAs($manager)->from('/')->put(route('organization-design.mapping.occupations.link', $occupation), ['isco_group_id' => (string) Str::uuid()])->assertSessionHasErrors('isco_group_id');
    $this->actingAs($manager)->put(route('organization-design.mapping.occupations.link', $occupation), ['isco_group_id' => $group->id])->assertRedirect();
    expect($occupation->fresh()->isco_group_id)->toBe($group->id);

    $this->actingAs($designer)->put(route('organization-design.mapping.competencies.link', $competency), ['competency_type_id' => $type->id])->assertForbidden();
    $this->actingAs($manager)->put(route('organization-design.mapping.competencies.link', $competency), ['competency_type_id' => $type->id])->assertRedirect();
    expect($competency->fresh()->competency_type_id)->toBe($type->id)
        ->and(collect(app(MappingWorkspaceService::class)->unmappedCatalog()['competencies'])->pluck('id')->all())->not->toContain($competency->id);
    expect(AuditLog::query()->where('event_type', 'organization_design.occupation_linked')->count())->toBe(1)
        ->and(AuditLog::query()->where('event_type', 'organization_design.competency_linked')->count())->toBe(1);
});

// 79 ─────────────────────────────────────────────────────────────────────────
test('the mapping page needs its permission, stays inside the organization scope, and carries the unmapped lists, the quality report and the pickers', function (): void {
    $mine = mwOrganization('E');
    $theirs = mwOrganization('E2');
    $position = mwPosition($mine, mwUnit($mine, 'U-1'));
    mwMasterTask($mine, 'T-500', 'Register');
    mwLegacyTask($mine, $position, 'T-500', 'Register legacy');
    $designer = mwUser($mine, 'Mapping Designer');
    $nobody = mwUser($mine, 'Nobody');

    $this->actingAs($nobody)->get(route('organization-design.mapping.index', ['organization_id' => $mine->id]))->assertForbidden();
    $this->actingAs($designer)->get(route('organization-design.mapping.index', ['organization_id' => $theirs->id]))->assertForbidden();
    $this->actingAs($designer)->get(route('organization-design.mapping.index', ['organization_id' => $mine->id]))
        ->assertInertia(fn ($page) => $page->component('OrganizationDesign/Mapping/Index')
            ->where('organizationId', $mine->id)
            ->has('organizations', 1)
            ->has('unmapped.position_tasks', 1)
            ->where('unmapped.position_tasks.0.code', 'T-500')
            ->where('unmapped.position_tasks.0.suggestions.0.code', 'T-500')
            ->has('unmapped.positions', 1)
            ->where('quality.ok', true)
            ->has('masterTasks', 1)
            ->where('can.map', true)
            ->where('can.apply', true)
            ->where('can.catalog', false)
            ->where('catalog', null));
    $this->actingAs($designer)->get(route('organization-design.mapping.index'))
        ->assertInertia(fn ($page) => $page->where('organizationId', $mine->id));
});
