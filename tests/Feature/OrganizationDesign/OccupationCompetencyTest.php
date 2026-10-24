<?php

declare(strict_types=1);

use App\Enums\OrganizationDesignStatus;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\Competency;
use App\Models\CompetencyFramework;
use App\Models\CompetencyLevel;
use App\Models\CompetencyType;
use App\Models\EducationLevel;
use App\Models\IscoGroup;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use App\Models\Profession;
use App\Models\Service;
use App\Models\Specialization;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Models\WorkCompetencyRequirement;
use App\Models\WorkOccupationRequirement;
use App\Models\WorkQualificationRequirement;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Organization Design & Workforce Planning, phase 4: ISCO reference data,
 * professions and specializations, the competency catalog (types, levels)
 * and the occupation / competency / qualification requirements of
 * activities and tasks. Brief tests 26–34.
 */

beforeEach(function (): void {
    foreach ([
        'processes.viewAny', 'processes.view', 'processes.create', 'processes.update', 'processes.approve',
        'occupations.viewAny', 'professions.manage', 'competency-catalog.viewAny', 'competency-catalog.manage',
        'classifications.viewAny', 'classifications.manage',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web');
    Role::findOrCreate('Requirement Designer', 'web')->syncPermissions([
        'processes.viewAny', 'processes.view', 'processes.create', 'processes.update', 'occupations.viewAny', 'competency-catalog.viewAny', 'classifications.viewAny',
    ]);
    Role::findOrCreate('Requirement Approver', 'web')->syncPermissions(['processes.viewAny', 'processes.view', 'processes.approve']);
    Role::findOrCreate('Catalog Steward', 'web')->syncPermissions(['occupations.viewAny', 'professions.manage', 'competency-catalog.viewAny', 'competency-catalog.manage', 'classifications.viewAny', 'classifications.manage']);
});

function ocOrganization(string $suffix): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'OC-BUREAU'], ['name_en' => 'OC Bureau', 'is_demo' => false]);

    return Organization::query()->create([
        'organization_type_id' => $type->id, 'code' => 'OC-'.$suffix.'-'.uniqid(), 'name_en' => 'OC Org '.$suffix,
        'status' => OrganizationStatus::Active, 'effective_from' => now()->subYear()->toDateString(), 'is_demo' => false,
    ]);
}

function ocDesignerFor(Organization $organization): User
{
    $user = User::factory()->create();
    $user->assignRole('Requirement Designer');
    UserOrganizationScope::query()->create([
        'user_id' => $user->id, 'organization_id' => $organization->id, 'scope_type' => OrganizationScopeType::Self,
        'is_active' => true, 'effective_from' => now()->subDay()->toDateString(),
    ]);

    return $user->fresh();
}

function ocSteward(): User
{
    $user = User::factory()->create();
    $user->assignRole('Catalog Steward');

    return $user;
}

/** A draft process with one activity and one task, created straight in the database. */
function ocDraftProcess(Organization $organization, User $designer): array
{
    $service = Service::query()->create([
        'organization_id' => $organization->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'SRV-'.substr(uniqid(), -4),
        'name_en' => 'Service', 'effective_from' => '2021-01-01', 'status' => OrganizationDesignStatus::Published,
    ]);
    $process = Process::query()->create([
        'organization_id' => $organization->id, 'service_id' => $service->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1,
        'code' => 'PRC-'.substr(uniqid(), -4), 'name_en' => 'Process', 'process_type' => 'core', 'effective_from' => '2022-01-01',
        'status' => OrganizationDesignStatus::Draft, 'created_by' => $designer->id,
    ]);
    $activity = ProcessActivity::query()->create(['process_id' => $process->id, 'organization_id' => $organization->id, 'code' => 'A-1', 'name_en' => 'Activity', 'path' => '', 'depth' => 0]);
    $activity->forceFill(['path' => $activity->id])->save();
    $task = ProcessTask::query()->create(['activity_id' => $activity->id, 'process_id' => $process->id, 'organization_id' => $organization->id, 'code' => 'T-1', 'name_en' => 'Task']);

    return [$process, $activity, $task];
}

function ocOccupation(string $code = '2411'): Occupation
{
    return Occupation::query()->create(['code' => 'OCC-'.$code.'-'.substr(uniqid(), -3), 'isco_code' => $code, 'name_en' => 'Accountant', 'is_active' => true]);
}

function ocCompetency(string $code = 'COMM'): Competency
{
    $framework = CompetencyFramework::query()->firstOrCreate(['code' => 'OC-FW'], ['name_en' => 'OC framework', 'is_active' => true]);

    return Competency::query()->create(['framework_id' => $framework->id, 'code' => $code.'-'.substr(uniqid(), -3), 'name_en' => 'Competency '.$code, 'is_active' => true]);
}

function ocLevel(int $rank, string $code): CompetencyLevel
{
    return CompetencyLevel::query()->firstOrCreate(['code' => $code], ['name_en' => $code, 'rank' => $rank, 'is_active' => true]);
}

// 26 ─────────────────────────────────────────────────────────────────────────
test('ISCO is a revisioned reference catalog loaded from an official dataset, and an occupation may cite a unit group', function (): void {
    $steward = ocSteward();
    $csv = "code,parent_code,level,name_en,name_am\n2,,1,Professionals,ባለሙያዎች\n24,2,2,Business and administration professionals,\n241,24,3,Finance professionals,\n2411,241,4,Accountants,የሂሳብ ባለሙያዎች\n";
    $file = UploadedFile::fake()->createWithContent('isco-08.csv', $csv);

    $this->actingAs($steward)->post(route('organization-design.classifications.import'), ['scheme' => 'ISCO', 'revision' => 'ISCO-08', 'file' => $file])->assertRedirect();
    expect(IscoGroup::query()->where('revision', 'ISCO-08')->count())->toBe(4)
        ->and(IscoGroup::query()->where('code', '2411')->firstOrFail()->level)->toBe('unit')
        ->and(IscoGroup::query()->where('code', '2411')->firstOrFail()->parent?->code)->toBe('241');

    // The occupation keeps its recorded ISCO codes and may cite the reference row.
    $occupation = ocOccupation('2411');
    $unit = IscoGroup::query()->where('code', '2411')->firstOrFail();
    $occupation->forceFill(['isco_group_id' => $unit->id])->save();
    expect($occupation->fresh()->iscoGroup?->code)->toBe('2411')->and($occupation->fresh()->isco_code)->toBe('2411');

    // Browsable with the other classifications; the designer reads, never writes.
    $this->actingAs($steward)->get(route('organization-design.classifications.index', ['scheme' => 'ISCO']))
        ->assertInertia(fn ($page) => $page->component('OrganizationDesign/Classifications/Index')->where('scheme', 'ISCO')->where('rows.total', 4));
    $designer = ocDesignerFor(ocOrganization('A'));
    $this->actingAs($designer)->post(route('organization-design.classifications.import'), ['scheme' => 'ISCO', 'revision' => 'ISCO-08', 'file' => UploadedFile::fake()->createWithContent('x.csv', $csv)])->assertForbidden();
});

// 27 ─────────────────────────────────────────────────────────────────────────
test('professions and specializations hang off occupations as city-level catalogs managed by stewards', function (): void {
    $steward = ocSteward();
    $occupation = ocOccupation();

    $this->actingAs($steward)->post(route('organization-design.professions.store'), ['occupation_id' => $occupation->id, 'code' => 'ACC', 'name_en' => 'Accounting', 'name_am' => 'የሂሳብ አያያዝ'])->assertRedirect();
    $profession = Profession::query()->where('code', 'ACC')->firstOrFail();
    expect($profession->occupation_id)->toBe($occupation->id);

    $this->actingAs($steward)->post(route('organization-design.professions.specializations.store', $profession), ['code' => 'AUD', 'name_en' => 'Auditing'])->assertRedirect();
    $this->actingAs($steward)->from('/')->post(route('organization-design.professions.specializations.store', $profession), ['code' => 'AUD', 'name_en' => 'Dup'])->assertSessionHasErrors('code');
    expect($profession->specializations()->pluck('code')->all())->toBe(['AUD']);

    $this->actingAs($steward)->put(route('organization-design.specializations.update', Specialization::query()->firstOrFail()), ['name_en' => 'Internal auditing', 'is_active' => false])->assertRedirect();
    expect(Specialization::query()->firstOrFail()->is_active)->toBeFalse();

    // Institution designers read the catalog but cannot change it.
    $designer = ocDesignerFor(ocOrganization('B'));
    $this->actingAs($designer)->get(route('organization-design.professions.index'))->assertOk();
    $this->actingAs($designer)->post(route('organization-design.professions.store'), ['occupation_id' => $occupation->id, 'code' => 'X', 'name_en' => 'x'])->assertForbidden();
});

// 28 ─────────────────────────────────────────────────────────────────────────
test('an occupation requirement names an occupation and, within it, a profession and specialization', function (): void {
    $organization = ocOrganization('C');
    $designer = ocDesignerFor($organization);
    [$process, $activity, $task] = ocDraftProcess($organization, $designer);
    $occupation = ocOccupation();
    $other = ocOccupation('2412');
    $profession = Profession::query()->create(['occupation_id' => $occupation->id, 'code' => 'ACC', 'name_en' => 'Accounting']);
    $foreignProfession = Profession::query()->create(['occupation_id' => $other->id, 'code' => 'FIN', 'name_en' => 'Finance']);
    $specialization = Specialization::query()->create(['profession_id' => $profession->id, 'code' => 'AUD', 'name_en' => 'Auditing']);

    $url = route('organization-design.requirements.sync', ['subjectType' => 'task', 'subject' => $task->id]);
    $this->actingAs($designer)->from('/')->put($url, ['occupations' => [['occupation_id' => $occupation->id, 'profession_id' => $foreignProfession->id]]])
        ->assertSessionHasErrors('occupations.0.profession_id');
    $this->actingAs($designer)->put($url, ['occupations' => [
        ['occupation_id' => $occupation->id, 'profession_id' => $profession->id, 'specialization_id' => $specialization->id, 'is_primary' => true],
        ['occupation_id' => $other->id],
    ]])->assertRedirect();

    $rows = WorkOccupationRequirement::query()->where('task_id', $task->id)->orderByDesc('is_primary')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->first()->specialization_id)->toBe($specialization->id)
        ->and($rows->first()->activity_id)->toBeNull();

    // The same set on the activity is independent; re-syncing replaces.
    $this->actingAs($designer)->put(route('organization-design.requirements.sync', ['subjectType' => 'activity', 'subject' => $activity->id]), ['occupations' => [['occupation_id' => $occupation->id]]])->assertRedirect();
    $this->actingAs($designer)->put($url, ['occupations' => [['occupation_id' => $other->id]]])->assertRedirect();
    expect(WorkOccupationRequirement::query()->where('task_id', $task->id)->count())->toBe(1)
        ->and(WorkOccupationRequirement::query()->where('activity_id', $activity->id)->count())->toBe(1);
});

// 29 ─────────────────────────────────────────────────────────────────────────
test('competency types and named levels are catalogs; level ranks are unique and comparable to EPMS ratings', function (): void {
    $steward = ocSteward();

    $this->actingAs($steward)->post(route('organization-design.competency-types.store'), ['code' => 'TECHNICAL', 'name_en' => 'Technical', 'name_am' => 'ቴክኒካል'])->assertRedirect();
    $this->actingAs($steward)->post(route('organization-design.competency-levels.store'), ['code' => 'PROFICIENT', 'name_en' => 'Proficient', 'rank' => 3])->assertRedirect();
    $this->actingAs($steward)->from('/')->post(route('organization-design.competency-levels.store'), ['code' => 'OTHER', 'name_en' => 'Other', 'rank' => 3])->assertSessionHasErrors('rank');
    $this->actingAs($steward)->from('/')->post(route('organization-design.competency-levels.store'), ['code' => 'TOO-HIGH', 'name_en' => 'x', 'rank' => 11])->assertSessionHasErrors('rank');

    $type = CompetencyType::query()->where('code', 'TECHNICAL')->firstOrFail();
    $competency = ocCompetency();
    $this->actingAs($steward)->put(route('organization-design.competencies.update', $competency), ['competency_type_id' => $type->id])->assertRedirect();
    expect($competency->fresh()->competency_type_id)->toBe($type->id)
        ->and(CompetencyLevel::query()->where('code', 'PROFICIENT')->firstOrFail()->rank)->toBe(3);

    $this->actingAs($steward)->get(route('organization-design.competency-catalog.index'))
        ->assertInertia(fn ($page) => $page->component('OrganizationDesign/CompetencyCatalog/Index')->has('types', 1)->has('levels', 1));

    $designer = ocDesignerFor(ocOrganization('D'));
    $this->actingAs($designer)->post(route('organization-design.competency-types.store'), ['code' => 'X', 'name_en' => 'x'])->assertForbidden();
});

// 30 ─────────────────────────────────────────────────────────────────────────
test('a competency requirement carries a minimum level and whether it is mandatory, once per competency and subject', function (): void {
    $organization = ocOrganization('E');
    $designer = ocDesignerFor($organization);
    [$process, $activity, $task] = ocDraftProcess($organization, $designer);
    $competency = ocCompetency('COMM');
    $level = ocLevel(3, 'PROFICIENT');

    $url = route('organization-design.requirements.sync', ['subjectType' => 'activity', 'subject' => $activity->id]);
    $this->actingAs($designer)->from('/')->put($url, ['competencies' => [['competency_id' => $competency->id]]])->assertSessionHasErrors('competencies.0.minimum_level_id');
    $this->actingAs($designer)->from('/')->put($url, ['competencies' => [
        ['competency_id' => $competency->id, 'minimum_level_id' => $level->id, 'is_mandatory' => true],
        ['competency_id' => $competency->id, 'minimum_level_id' => $level->id, 'is_mandatory' => false],
    ]])->assertSessionHasErrors('competencies.1.competency_id');

    $this->actingAs($designer)->put($url, ['competencies' => [['competency_id' => $competency->id, 'minimum_level_id' => $level->id, 'is_mandatory' => true]]])->assertRedirect();
    $row = WorkCompetencyRequirement::query()->where('activity_id', $activity->id)->firstOrFail();
    expect($row->minimum_level_id)->toBe($level->id)->and($row->is_mandatory)->toBeTrue()->and($row->level->rank)->toBe(3);
});

// 31 ─────────────────────────────────────────────────────────────────────────
test('qualification requirements are typed columns: education level, field of study, certification, license, experience years', function (): void {
    $organization = ocOrganization('F');
    $designer = ocDesignerFor($organization);
    [$process, $activity, $task] = ocDraftProcess($organization, $designer);
    $degree = EducationLevel::query()->create(['code' => 'BACHELOR', 'name_en' => 'Bachelor degree', 'rank' => 6, 'is_active' => true]);

    $url = route('organization-design.requirements.sync', ['subjectType' => 'task', 'subject' => $task->id]);
    $this->actingAs($designer)->from('/')->put($url, ['qualifications' => [['education_level_id' => $degree->id, 'experience_years' => -1]]])->assertSessionHasErrors('qualifications.0.experience_years');
    $this->actingAs($designer)->from('/')->put($url, ['qualifications' => [['field_of_study' => 'Accounting']]])->assertSessionHasErrors('qualifications.0.education_level_id');

    $this->actingAs($designer)->put($url, ['qualifications' => [
        ['education_level_id' => $degree->id, 'field_of_study' => 'Accounting', 'certification' => 'ACCA', 'license' => null, 'experience_years' => 4, 'is_mandatory' => true],
    ]])->assertRedirect();
    $row = WorkQualificationRequirement::query()->where('task_id', $task->id)->firstOrFail();
    expect($row->education_level_id)->toBe($degree->id)->and($row->experience_years)->toBe(4)->and($row->certification)->toBe('ACCA')
        ->and($row->getAttributes())->not->toHaveKey('requirements_text');
});

// 32 ─────────────────────────────────────────────────────────────────────────
test('requirements freeze with the published process and travel with a new version', function (): void {
    $organization = ocOrganization('G');
    $designer = ocDesignerFor($organization);
    $approver = User::factory()->create();
    $approver->assignRole('Requirement Approver');
    // The approver works city-wide; organization-level roles need an explicit scope (ADR 0003).
    UserOrganizationScope::query()->create([
        'user_id' => $approver->id, 'organization_id' => null, 'scope_type' => OrganizationScopeType::Citywide, 'is_active' => true,
    ]);
    [$process, $activity, $task] = ocDraftProcess($organization, $designer);
    $occupation = ocOccupation();
    $competency = ocCompetency();
    $level = ocLevel(2, 'DEVELOPING');

    $url = route('organization-design.requirements.sync', ['subjectType' => 'task', 'subject' => $task->id]);
    $this->actingAs($designer)->put($url, [
        'occupations' => [['occupation_id' => $occupation->id]],
        'competencies' => [['competency_id' => $competency->id, 'minimum_level_id' => $level->id, 'is_mandatory' => true]],
    ])->assertRedirect();

    $this->actingAs($approver)->post(route('organization-design.processes.publish', $process))->assertRedirect();
    $this->actingAs($designer)->from('/')->put($url, ['occupations' => []])->assertSessionHasErrors('status');
    expect(WorkOccupationRequirement::query()->where('task_id', $task->id)->count())->toBe(1);

    $this->actingAs($designer)->post(route('organization-design.processes.versions.store', $process), ['effective_from' => '2023-01-01'])->assertRedirect();
    $v2 = Process::query()->where('lineage_key', $process->lineage_key)->where('version_no', 2)->firstOrFail();
    $copiedTask = ProcessTask::query()->where('process_id', $v2->id)->where('code', 'T-1')->firstOrFail();
    expect(WorkOccupationRequirement::query()->where('task_id', $copiedTask->id)->count())->toBe(1)
        ->and(WorkCompetencyRequirement::query()->where('task_id', $copiedTask->id)->firstOrFail()->minimum_level_id)->toBe($level->id)
        ->and(WorkOccupationRequirement::query()->where('task_id', $task->id)->count())->toBe(1);

    // The show page serves requirements with the tree.
    $this->actingAs($designer)->get(route('organization-design.processes.show', $v2))
        ->assertInertia(fn ($page) => $page->component('OrganizationDesign/Processes/Show')
            ->where('activities.0.tasks.0.requirements.occupations.0.occupation.isco_code', '2411')
            ->where('activities.0.tasks.0.requirements.competencies.0.minimum_level.rank', 2));
});

// 33 ─────────────────────────────────────────────────────────────────────────
test('a scoped designer cannot write requirements on another organization\'s process', function (): void {
    $mine = ocOrganization('H');
    $theirs = ocOrganization('H2');
    $designer = ocDesignerFor($mine);
    [, , $foreignTask] = ocDraftProcess($theirs, ocDesignerFor($theirs));
    $occupation = ocOccupation();

    $this->actingAs($designer)->put(route('organization-design.requirements.sync', ['subjectType' => 'task', 'subject' => $foreignTask->id]), ['occupations' => [['occupation_id' => $occupation->id]]])
        ->assertForbidden();
    expect(WorkOccupationRequirement::query()->count())->toBe(0);
});

// 34 ─────────────────────────────────────────────────────────────────────────
test('catalog entries in use are never deleted, only deactivated; an inactive entry cannot be newly required', function (): void {
    $organization = ocOrganization('I');
    $designer = ocDesignerFor($organization);
    $steward = ocSteward();
    [$process, $activity, $task] = ocDraftProcess($organization, $designer);
    $occupation = ocOccupation();
    $profession = Profession::query()->create(['occupation_id' => $occupation->id, 'code' => 'ACC', 'name_en' => 'Accounting']);

    $url = route('organization-design.requirements.sync', ['subjectType' => 'task', 'subject' => $task->id]);
    $this->actingAs($designer)->put($url, ['occupations' => [['occupation_id' => $occupation->id, 'profession_id' => $profession->id]]])->assertRedirect();

    // A savepoint keeps the test transaction usable on PostgreSQL after the refused delete.
    expect(fn () => DB::transaction(fn () => $profession->delete()))->toThrow(QueryException::class);

    $this->actingAs($steward)->put(route('organization-design.professions.update', $profession), ['is_active' => false])->assertRedirect();
    expect($profession->fresh()->is_active)->toBeFalse()
        ->and(WorkOccupationRequirement::query()->where('task_id', $task->id)->count())->toBe(1);

    $this->actingAs($designer)->from('/')->put(route('organization-design.requirements.sync', ['subjectType' => 'activity', 'subject' => $activity->id]), ['occupations' => [['occupation_id' => $occupation->id, 'profession_id' => $profession->id]]])
        ->assertSessionHasErrors('occupations.0.profession_id');
});
