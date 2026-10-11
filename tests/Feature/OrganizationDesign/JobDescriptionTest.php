<?php

declare(strict_types=1);

use App\Enums\OrganizationDesignStatus;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\Competency;
use App\Models\CompetencyFramework;
use App\Models\CompetencyLevel;
use App\Models\EducationLevel;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\JobDescription;
use App\Models\JobDescriptionSection;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\PositionCompetencyRequirement;
use App\Models\PositionProfile;
use App\Models\PositionQualificationRequirement;
use App\Models\PositionServiceTaskStandard;
use App\Models\PositionWorkLink;
use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use App\Models\Service;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Models\WorkCompetencyRequirement;
use App\Models\WorkQualificationRequirement;
use App\Services\OrganizationDesign\JobDescriptionService;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Organization Design & Workforce Planning, phase 8: position profiles,
 * the position's links to the work design, position-level competency and
 * qualification requirements (derived from the work or written), and job
 * descriptions with derived and custom sections, versioned and recorded on
 * assignments. Brief §58–71, 81; tests 59–68.
 */

beforeEach(function (): void {
    foreach ([
        'position-profiles.viewAny', 'position-profiles.manage', 'position-profiles.approve',
        'job-descriptions.viewAny', 'job-descriptions.manage', 'job-descriptions.approve', 'job-descriptions.publish', 'positions.view',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web');
    Role::findOrCreate('Position Designer', 'web')->syncPermissions(['position-profiles.viewAny', 'position-profiles.manage', 'job-descriptions.viewAny', 'job-descriptions.manage', 'positions.view']);
    Role::findOrCreate('Position Approver', 'web')->syncPermissions(['position-profiles.viewAny', 'position-profiles.approve', 'job-descriptions.viewAny', 'job-descriptions.approve', 'job-descriptions.publish', 'positions.view']);
    CompetencyLevel::query()->firstOrCreate(['code' => 'PROFICIENT'], ['name_en' => 'Proficient', 'rank' => 3, 'is_active' => true]);
    CompetencyLevel::query()->firstOrCreate(['code' => 'ADVANCED'], ['name_en' => 'Advanced', 'rank' => 4, 'is_active' => true]);
    EducationLevel::query()->firstOrCreate(['code' => 'BACHELOR'], ['name_en' => 'Bachelor degree', 'rank' => 6, 'is_active' => true]);
});

function jdOrganization(string $suffix): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'JD-BUREAU'], ['name_en' => 'JD Bureau', 'is_demo' => false]);

    return Organization::query()->create([
        'organization_type_id' => $type->id, 'code' => 'JD-'.$suffix.'-'.uniqid(), 'name_en' => 'JD Org '.$suffix,
        'status' => OrganizationStatus::Active, 'effective_from' => now()->subYear()->toDateString(), 'is_demo' => false,
    ]);
}

function jdUser(Organization $organization, string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'scope_type' => OrganizationScopeType::Self, 'is_active' => true, 'effective_from' => now()->subDay()->toDateString()]);

    return $user->fresh();
}

function jdUnit(Organization $organization, string $code): OrganizationUnit
{
    $type = OrganizationUnitType::query()->firstOrCreate(['code' => 'JD-DIR'], ['name_en' => 'Directorate', 'is_active' => true]);

    return OrganizationUnit::query()->create(['organization_id' => $organization->id, 'organization_unit_type_id' => $type->id, 'code' => $code, 'name_en' => 'Unit '.$code, 'unit_type' => 'directorate', 'status' => 'active']);
}

function jdOccupation(): Occupation
{
    static $n = 0;
    $isco = '24'.str_pad((string) (++$n), 2, '0', STR_PAD_LEFT);

    return Occupation::query()->create(['code' => 'JD-'.$isco.'-'.substr(uniqid(), -3), 'isco_code' => $isco, 'name_en' => 'Accountant', 'name_am' => 'የሂሳብ ባለሙያ', 'is_active' => true]);
}

function jdPosition(Organization $organization, OrganizationUnit $unit, string $code = 'P-1'): Position
{
    return Position::query()->create(['organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'occupation_id' => jdOccupation()->id, 'job_position_code' => $code.'-'.substr(uniqid(), -4), 'title_en' => 'Senior Accountant', 'title_am' => 'ከፍተኛ የሂሳብ ባለሙያ', 'grade_level' => 'IX', 'is_active' => true]);
}

/** A published process with one activity and one task that carries work requirements and an approved standard. */
function jdTask(Organization $organization, string $code = 'T-1'): ProcessTask
{
    $service = Service::query()->create(['organization_id' => $organization->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'SRV-'.substr(uniqid(), -4), 'name_en' => 'Payroll service', 'effective_from' => '2021-01-01', 'status' => OrganizationDesignStatus::Published]);
    $process = Process::query()->create(['organization_id' => $organization->id, 'service_id' => $service->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'PRC-'.substr(uniqid(), -4), 'name_en' => 'Run payroll', 'process_type' => 'core', 'effective_from' => '2022-01-01', 'status' => OrganizationDesignStatus::Published]);
    $activity = ProcessActivity::query()->create(['process_id' => $process->id, 'organization_id' => $organization->id, 'code' => 'A-'.substr(uniqid(), -3), 'name_en' => 'Prepare payroll', 'path' => '', 'depth' => 0]);
    $activity->forceFill(['path' => $activity->id])->save();
    $task = ProcessTask::query()->create(['activity_id' => $activity->id, 'process_id' => $process->id, 'organization_id' => $organization->id, 'code' => $code, 'name_en' => 'Compute salaries', 'name_am' => 'ደመወዝ ማስላት', 'required_output' => 'Payroll sheet']);
    $framework = CompetencyFramework::query()->firstOrCreate(['code' => 'JD-FW'], ['name_en' => 'JD framework', 'is_active' => true]);
    $competency = Competency::query()->create(['framework_id' => $framework->id, 'code' => 'NUM-'.substr(uniqid(), -3), 'name_en' => 'Numeracy', 'is_active' => true]);
    WorkCompetencyRequirement::query()->create(['organization_id' => $organization->id, 'task_id' => $task->id, 'competency_id' => $competency->id, 'minimum_level_id' => CompetencyLevel::query()->where('code', 'PROFICIENT')->value('id'), 'is_mandatory' => true]);
    WorkQualificationRequirement::query()->create(['organization_id' => $organization->id, 'task_id' => $task->id, 'education_level_id' => EducationLevel::query()->where('code', 'BACHELOR')->value('id'), 'field_of_study' => 'Accounting', 'experience_years' => 4, 'is_mandatory' => true]);
    PositionServiceTaskStandard::query()->create(['master_task_id' => $task->id, 'organization_id' => $organization->id, 'version_no' => 1, 'status' => 'approved', 'planned_quantity' => '40', 'quantity_unit' => 'sheets', 'planned_time_minutes' => '30', 'effective_from' => '2024-01-01']);

    return $task;
}

function jdEmployee(Organization $organization, OrganizationUnit $unit, Position $position): EmployeeAssignment
{
    $employee = Employee::query()->create(['employee_number' => 'JD-'.substr(uniqid(), -5), 'first_name' => 'Abebe', 'last_name' => 'Kebede', 'full_name' => 'Abebe Kebede', 'email' => uniqid().'@example.test', 'status' => 'active']);
    $assignment = EmployeeAssignment::query()->create(['employee_id' => $employee->id, 'organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'position_id' => $position->id, 'assignment_status' => 'active', 'effective_from' => '2026-01-01', 'is_current' => true]);
    $employee->update(['current_assignment_id' => $assignment->id]);

    return $assignment;
}

/** @return array<string, mixed> */
function jdProfilePayload(array $overrides = []): array
{
    return ['purpose_en' => 'Keeps the books of the bureau.', 'purpose_am' => 'የቢሮውን ሂሳብ ይይዛል።', 'working_conditions_en' => 'Office hours', 'effective_from' => '2026-01-01', ...$overrides];
}

function jdPublishedProfile(Organization $organization, Position $position, User $designer, User $approver): PositionProfile
{
    test()->actingAs($designer)->post(route('organization-design.position-profiles.store', $position), jdProfilePayload())->assertRedirect();
    $profile = PositionProfile::query()->where('position_id', $position->id)->firstOrFail();
    test()->actingAs($approver)->post(route('organization-design.position-profiles.publish', $profile))->assertRedirect();

    return $profile->fresh();
}

// 59 ─────────────────────────────────────────────────────────────────────────
test('a position profile is versioned like the other design records: drafted, published by someone else, superseded by a later version', function (): void {
    $organization = jdOrganization('A');
    $other = jdOrganization('A2');
    $designer = jdUser($organization, 'Position Designer');
    $approver = jdUser($organization, 'Position Approver');
    $unit = jdUnit($organization, 'U-1');
    $position = jdPosition($organization, $unit);
    $boss = jdPosition($organization, $unit, 'P-BOSS');
    $foreignBoss = jdPosition($other, jdUnit($other, 'U-X'), 'P-X');

    $this->actingAs($designer)->from('/')->post(route('organization-design.position-profiles.store', $position), jdProfilePayload(['reports_to_position_id' => $foreignBoss->id]))->assertSessionHasErrors('reports_to_position_id');
    $this->actingAs($designer)->post(route('organization-design.position-profiles.store', $position), jdProfilePayload(['reports_to_position_id' => $boss->id]))->assertRedirect();
    $profile = PositionProfile::query()->where('position_id', $position->id)->firstOrFail();
    expect($profile->status)->toBe(OrganizationDesignStatus::Draft)->and($profile->version_no)->toBe(1)->and($profile->reports_to_position_id)->toBe($boss->id);

    $this->actingAs($designer)->post(route('organization-design.position-profiles.publish', $profile))->assertForbidden();
    $this->actingAs($approver)->post(route('organization-design.position-profiles.publish', $profile))->assertRedirect();
    expect($profile->fresh()->status)->toBe(OrganizationDesignStatus::Published);
    $this->actingAs($designer)->from('/')->put(route('organization-design.position-profiles.update', $profile), ['purpose_en' => 'x'])->assertSessionHasErrors('status');

    $this->actingAs($designer)->post(route('organization-design.position-profiles.versions.store', $profile), ['effective_from' => '2027-01-01', 'purpose_en' => 'Leads the accounts team.'])->assertRedirect();
    $v2 = PositionProfile::query()->where('lineage_key', $profile->lineage_key)->where('version_no', 2)->firstOrFail();
    expect($v2->reports_to_position_id)->toBe($boss->id)->and($v2->purpose_en)->toBe('Leads the accounts team.');
    $this->actingAs($approver)->post(route('organization-design.position-profiles.publish', $v2))->assertRedirect();
    expect($profile->fresh()->status)->toBe(OrganizationDesignStatus::Superseded)->and($profile->fresh()->effective_to?->toDateString())->toBe('2026-12-31');
});

// 60 ─────────────────────────────────────────────────────────────────────────
test('a position is linked to the services, processes, activities and tasks it is responsible for, within its own organization', function (): void {
    $organization = jdOrganization('B');
    $other = jdOrganization('B2');
    $designer = jdUser($organization, 'Position Designer');
    $position = jdPosition($organization, jdUnit($organization, 'U-1'));
    $task = jdTask($organization);
    $foreignTask = jdTask($other, 'T-X');

    $url = route('organization-design.position-work-links.sync', $position);
    $this->actingAs($designer)->from('/')->put($url, ['items' => [['object_type' => 'task', 'object_id' => $foreignTask->id]]])->assertSessionHasErrors('items.0.object_id');
    $this->actingAs($designer)->from('/')->put($url, ['items' => [['object_type' => 'position', 'object_id' => $task->id]]])->assertSessionHasErrors('items.0.object_type');
    $this->actingAs($designer)->from('/')->put($url, ['items' => [['object_type' => 'task', 'object_id' => $task->id], ['object_type' => 'task', 'object_id' => $task->id]]])->assertSessionHasErrors('items.1.object_id');

    $this->actingAs($designer)->put($url, ['items' => [
        ['object_type' => 'task', 'object_id' => $task->id, 'is_primary' => true],
        ['object_type' => 'process', 'object_id' => $task->process_id],
        ['object_type' => 'service', 'object_id' => $task->process->service_id],
    ]])->assertRedirect();
    expect(PositionWorkLink::query()->where('position_id', $position->id)->count())->toBe(3)
        ->and(PositionWorkLink::query()->where('position_id', $position->id)->where('is_primary', true)->value('object_id'))->toBe($task->id);

    $this->actingAs($designer)->put($url, ['items' => [['object_type' => 'task', 'object_id' => $task->id]]])->assertRedirect();
    expect(PositionWorkLink::query()->where('position_id', $position->id)->count())->toBe(1);
});

// 61 ─────────────────────────────────────────────────────────────────────────
test('position requirements are derived from the linked work — competencies at the highest required level — and merged with what the designer writes', function (): void {
    $organization = jdOrganization('C');
    $designer = jdUser($organization, 'Position Designer');
    $position = jdPosition($organization, jdUnit($organization, 'U-1'));
    $task = jdTask($organization);
    $second = jdTask($organization, 'T-2');
    // The second task needs the same competency at a higher level.
    $competency = WorkCompetencyRequirement::query()->where('task_id', $task->id)->first()->competency;
    WorkCompetencyRequirement::query()->where('task_id', $second->id)->delete();
    WorkCompetencyRequirement::query()->create(['organization_id' => $organization->id, 'task_id' => $second->id, 'competency_id' => $competency->id, 'minimum_level_id' => CompetencyLevel::query()->where('code', 'ADVANCED')->value('id'), 'is_mandatory' => false]);
    $this->actingAs($designer)->put(route('organization-design.position-work-links.sync', $position), ['items' => [['object_type' => 'task', 'object_id' => $task->id], ['object_type' => 'task', 'object_id' => $second->id]]])->assertRedirect();

    // A written requirement first.
    $framework = CompetencyFramework::query()->firstOrCreate(['code' => 'JD-FW'], ['name_en' => 'JD framework', 'is_active' => true]);
    $manual = Competency::query()->create(['framework_id' => $framework->id, 'code' => 'LEAD-'.substr(uniqid(), -3), 'name_en' => 'Leadership', 'is_active' => true]);
    $this->actingAs($designer)->put(route('organization-design.position-requirements.sync', $position), ['competencies' => [['competency_id' => $manual->id, 'minimum_level_id' => CompetencyLevel::query()->where('code', 'PROFICIENT')->value('id'), 'is_mandatory' => true]]])->assertRedirect();

    $this->actingAs($designer)->post(route('organization-design.position-requirements.derive', $position))->assertRedirect();
    $rows = PositionCompetencyRequirement::query()->where('position_id', $position->id)->get();
    expect($rows)->toHaveCount(2);
    $derived = $rows->firstWhere('competency_id', $competency->id);
    expect($derived->source)->toBe('derived')->and($derived->level->code)->toBe('ADVANCED')->and($derived->is_mandatory)->toBeTrue()
        ->and($rows->firstWhere('competency_id', $manual->id)->source)->toBe('manual');
    $qualification = PositionQualificationRequirement::query()->where('position_id', $position->id)->firstOrFail();
    expect($qualification->source)->toBe('derived')->and($qualification->field_of_study)->toBe('Accounting')->and($qualification->experience_years)->toBe(4);

    // Deriving again is idempotent and never touches the written ones.
    $this->actingAs($designer)->post(route('organization-design.position-requirements.derive', $position))->assertRedirect();
    expect(PositionCompetencyRequirement::query()->where('position_id', $position->id)->count())->toBe(2)
        ->and(PositionQualificationRequirement::query()->where('position_id', $position->id)->count())->toBe(1);
});

// 62 ─────────────────────────────────────────────────────────────────────────
test('a job description is built from the published profile and the linked work: derived sections regenerate, custom sections are written', function (): void {
    $organization = jdOrganization('D');
    $designer = jdUser($organization, 'Position Designer');
    $approver = jdUser($organization, 'Position Approver');
    $unit = jdUnit($organization, 'U-1');
    $position = jdPosition($organization, $unit);
    $task = jdTask($organization);
    $this->actingAs($designer)->put(route('organization-design.position-work-links.sync', $position), ['items' => [['object_type' => 'task', 'object_id' => $task->id, 'is_primary' => true]]])->assertRedirect();
    $this->actingAs($designer)->post(route('organization-design.position-requirements.derive', $position))->assertRedirect();

    // No published profile yet: refused.
    $this->actingAs($designer)->from('/')->post(route('organization-design.job-descriptions.store', $position), ['effective_from' => '2026-01-01'])->assertSessionHasErrors('position_profile_id');
    $profile = jdPublishedProfile($organization, $position, $designer, $approver);

    $this->actingAs($designer)->post(route('organization-design.job-descriptions.store', $position), ['effective_from' => '2026-01-01'])->assertRedirect();
    $jd = JobDescription::query()->where('position_id', $position->id)->firstOrFail();
    expect($jd->position_profile_id)->toBe($profile->id)->and($jd->status)->toBe(OrganizationDesignStatus::Draft);
    $sections = $jd->sections()->get()->keyBy('section_key');
    expect($sections->keys()->all())->toContain('purpose', 'reporting', 'duties', 'occupation', 'qualifications', 'competencies', 'standards', 'working_conditions')
        ->and($sections['duties']->source)->toBe('derived')
        ->and($sections['duties']->payload['tasks'][0]['code'])->toBe('T-1')
        ->and($sections['duties']->content_en)->toContain('Compute salaries')
        ->and($sections['competencies']->payload[0]['competency']['name_en'])->toBe('Numeracy')
        ->and($sections['qualifications']->content_en)->toContain('Accounting')
        ->and($sections['standards']->payload[0]['planned_quantity'])->toBe('40.0000')
        ->and($sections['purpose']->source)->toBe('custom')
        ->and($sections['purpose']->content_en)->toBe('Keeps the books of the bureau.')
        ->and($sections['reporting']->payload['unit']['code'])->toBe('U-1');

    // Custom sections are edited; derived ones are not.
    $this->actingAs($designer)->put(route('organization-design.job-descriptions.sections.update', [$jd, 'authorities']), ['content_en' => 'Approves payments up to ETB 50,000.', 'content_am' => 'እስከ 50,000 ብር ክፍያዎችን ያጸድቃል።'])->assertRedirect();
    $this->actingAs($designer)->from('/')->put(route('organization-design.job-descriptions.sections.update', [$jd, 'duties']), ['content_en' => 'x'])->assertSessionHasErrors('section');
    expect(JobDescriptionSection::query()->where('job_description_id', $jd->id)->where('section_key', 'authorities')->value('content_am'))->toBe('እስከ 50,000 ብር ክፍያዎችን ያጸድቃል።');

    // A new task linked later shows up after regeneration; the custom text stays.
    $second = jdTask($organization, 'T-2');
    $this->actingAs($designer)->put(route('organization-design.position-work-links.sync', $position), ['items' => [['object_type' => 'task', 'object_id' => $task->id], ['object_type' => 'task', 'object_id' => $second->id]]])->assertRedirect();
    $this->actingAs($designer)->post(route('organization-design.job-descriptions.regenerate', $jd))->assertRedirect();
    $sections = $jd->sections()->get()->keyBy('section_key');
    expect(collect($sections['duties']->payload['tasks'])->pluck('code')->all())->toBe(['T-1', 'T-2'])
        ->and($sections['authorities']->content_en)->toBe('Approves payments up to ETB 50,000.');
});

// 63 ─────────────────────────────────────────────────────────────────────────
test('a job description goes draft → under review → approved → published, maker ≠ checker, published versions immutable and superseded by the next', function (): void {
    $organization = jdOrganization('E');
    $designer = jdUser($organization, 'Position Designer');
    $approver = jdUser($organization, 'Position Approver');
    $position = jdPosition($organization, jdUnit($organization, 'U-1'));
    $task = jdTask($organization);
    $this->actingAs($designer)->put(route('organization-design.position-work-links.sync', $position), ['items' => [['object_type' => 'task', 'object_id' => $task->id]]])->assertRedirect();
    jdPublishedProfile($organization, $position, $designer, $approver);
    $this->actingAs($designer)->post(route('organization-design.job-descriptions.store', $position), ['effective_from' => '2026-01-01'])->assertRedirect();
    $jd = JobDescription::query()->where('position_id', $position->id)->firstOrFail();
    $this->actingAs($designer)->put(route('organization-design.job-descriptions.sections.update', [$jd, 'authorities']), ['content_en' => 'Signs vouchers.'])->assertRedirect();

    $this->actingAs($approver)->from('/')->post(route('organization-design.job-descriptions.approve', $jd))->assertSessionHasErrors('status');
    $this->actingAs($designer)->post(route('organization-design.job-descriptions.submit', $jd))->assertRedirect();
    expect($jd->fresh()->status)->toBe(OrganizationDesignStatus::UnderReview);
    $this->actingAs($designer)->post(route('organization-design.job-descriptions.approve', $jd))->assertForbidden();
    $self = jdUser($organization, 'Position Approver');
    $self->assignRole('Position Designer');
    $ownPosition = jdPosition($organization, jdUnit($organization, 'U-2'), 'P-OWN');
    $this->actingAs($self)->put(route('organization-design.position-work-links.sync', $ownPosition), ['items' => [['object_type' => 'task', 'object_id' => $task->id]]])->assertRedirect();
    jdPublishedProfile($organization, $ownPosition, $self, $approver);
    $this->actingAs($self)->post(route('organization-design.job-descriptions.store', $ownPosition), ['effective_from' => '2026-01-01'])->assertRedirect();
    $own = JobDescription::query()->where('position_id', $ownPosition->id)->firstOrFail();
    $this->actingAs($self)->post(route('organization-design.job-descriptions.submit', $own))->assertRedirect();
    $this->actingAs($self)->from('/')->post(route('organization-design.job-descriptions.approve', $own))->assertSessionHasErrors('status');

    $this->actingAs($approver)->post(route('organization-design.job-descriptions.approve', $jd))->assertRedirect();
    expect($jd->fresh()->status)->toBe(OrganizationDesignStatus::Approved)->and($jd->fresh()->approved_by)->toBe($approver->id);
    $this->actingAs($designer)->post(route('organization-design.job-descriptions.publish', $jd))->assertForbidden();
    $this->actingAs($approver)->post(route('organization-design.job-descriptions.publish', $jd))->assertRedirect();
    expect($jd->fresh()->status)->toBe(OrganizationDesignStatus::Published)->and($jd->fresh()->published_by)->toBe($approver->id);

    $this->actingAs($designer)->from('/')->put(route('organization-design.job-descriptions.sections.update', [$jd, 'authorities']), ['content_en' => 'x'])->assertSessionHasErrors('status');
    $this->actingAs($designer)->from('/')->post(route('organization-design.job-descriptions.regenerate', $jd))->assertSessionHasErrors('status');

    $this->actingAs($designer)->post(route('organization-design.job-descriptions.versions.store', $jd), ['effective_from' => '2027-01-01'])->assertRedirect();
    $v2 = JobDescription::query()->where('lineage_key', $jd->lineage_key)->where('version_no', 2)->firstOrFail();
    expect($v2->sections()->where('section_key', 'authorities')->value('content_en'))->toBe('Signs vouchers.');
    $this->actingAs($designer)->post(route('organization-design.job-descriptions.submit', $v2))->assertRedirect();
    $this->actingAs($approver)->post(route('organization-design.job-descriptions.approve', $v2))->assertRedirect();
    $this->actingAs($approver)->post(route('organization-design.job-descriptions.publish', $v2))->assertRedirect();
    expect($jd->fresh()->status)->toBe(OrganizationDesignStatus::Superseded)->and($jd->fresh()->effective_to?->toDateString())->toBe('2026-12-31');
});

// 64 ─────────────────────────────────────────────────────────────────────────
test('publishing a job description records it on the position\'s current assignments, and history resolves the version in force', function (): void {
    $organization = jdOrganization('F');
    $designer = jdUser($organization, 'Position Designer');
    $approver = jdUser($organization, 'Position Approver');
    $unit = jdUnit($organization, 'U-1');
    $position = jdPosition($organization, $unit);
    $assignment = jdEmployee($organization, $unit, $position);
    $closed = jdEmployee($organization, $unit, $position);
    $closed->forceFill(['is_current' => false, 'assignment_status' => 'closed', 'effective_to' => '2025-12-31'])->save();
    $task = jdTask($organization);
    $this->actingAs($designer)->put(route('organization-design.position-work-links.sync', $position), ['items' => [['object_type' => 'task', 'object_id' => $task->id]]])->assertRedirect();
    jdPublishedProfile($organization, $position, $designer, $approver);
    $this->actingAs($designer)->post(route('organization-design.job-descriptions.store', $position), ['effective_from' => '2026-01-01'])->assertRedirect();
    $jd = JobDescription::query()->where('position_id', $position->id)->firstOrFail();
    $this->actingAs($designer)->post(route('organization-design.job-descriptions.submit', $jd))->assertRedirect();
    $this->actingAs($approver)->post(route('organization-design.job-descriptions.approve', $jd))->assertRedirect();
    $this->actingAs($approver)->post(route('organization-design.job-descriptions.publish', $jd))->assertRedirect();

    expect($assignment->fresh()->job_description_id)->toBe($jd->id)->and($closed->fresh()->job_description_id)->toBeNull();
    $service = app(JobDescriptionService::class);
    expect($service->inForceFor($position, '2026-06-01')?->id)->toBe($jd->id)->and($service->inForceFor($position, '2025-06-01'))->toBeNull();
});

// 65 ─────────────────────────────────────────────────────────────────────────
test('the job-description gate: no duties means no submission; missing competencies is only a warning', function (): void {
    $organization = jdOrganization('G');
    $designer = jdUser($organization, 'Position Designer');
    $approver = jdUser($organization, 'Position Approver');
    $position = jdPosition($organization, jdUnit($organization, 'U-1'));
    jdPublishedProfile($organization, $position, $designer, $approver);
    $this->actingAs($designer)->post(route('organization-design.job-descriptions.store', $position), ['effective_from' => '2026-01-01'])->assertRedirect();
    $jd = JobDescription::query()->where('position_id', $position->id)->firstOrFail();

    $this->actingAs($designer)->from('/')->post(route('organization-design.job-descriptions.submit', $jd))->assertSessionHasErrors('validation');
    $this->actingAs($designer)->get(route('organization-design.job-descriptions.show', $jd))
        ->assertInertia(fn ($page) => $page->component('OrganizationDesign/JobDescriptions/Show')->where('validation.ok', false)->where('validation.findings.0.code', 'jd_without_duties'));

    $task = jdTask($organization);
    WorkCompetencyRequirement::query()->where('task_id', $task->id)->delete();
    $this->actingAs($designer)->put(route('organization-design.position-work-links.sync', $position), ['items' => [['object_type' => 'task', 'object_id' => $task->id]]])->assertRedirect();
    $this->actingAs($designer)->post(route('organization-design.job-descriptions.regenerate', $jd))->assertRedirect();
    $this->actingAs($designer)->post(route('organization-design.job-descriptions.submit', $jd))->assertRedirect();
    expect($jd->fresh()->status)->toBe(OrganizationDesignStatus::UnderReview);
});

// 66 ─────────────────────────────────────────────────────────────────────────
test('a scoped designer cannot read or write another organization\'s profiles, links or job descriptions', function (): void {
    $mine = jdOrganization('H');
    $theirs = jdOrganization('H2');
    $designer = jdUser($mine, 'Position Designer');
    $foreignDesigner = jdUser($theirs, 'Position Designer');
    $foreignApprover = jdUser($theirs, 'Position Approver');
    $foreignPosition = jdPosition($theirs, jdUnit($theirs, 'U-X'));
    $foreignTask = jdTask($theirs);
    $this->actingAs($foreignDesigner)->put(route('organization-design.position-work-links.sync', $foreignPosition), ['items' => [['object_type' => 'task', 'object_id' => $foreignTask->id]]])->assertRedirect();
    $profile = jdPublishedProfile($theirs, $foreignPosition, $foreignDesigner, $foreignApprover);
    $this->actingAs($foreignDesigner)->post(route('organization-design.job-descriptions.store', $foreignPosition), ['effective_from' => '2026-01-01'])->assertRedirect();
    $jd = JobDescription::query()->where('position_id', $foreignPosition->id)->firstOrFail();

    $this->actingAs($designer)->post(route('organization-design.position-profiles.store', $foreignPosition), jdProfilePayload())->assertForbidden();
    $this->actingAs($designer)->put(route('organization-design.position-profiles.update', $profile), ['purpose_en' => 'x'])->assertForbidden();
    $this->actingAs($designer)->put(route('organization-design.position-work-links.sync', $foreignPosition), ['items' => []])->assertForbidden();
    $this->actingAs($designer)->get(route('organization-design.job-descriptions.show', $jd))->assertForbidden();
    $this->actingAs($designer)->put(route('organization-design.job-descriptions.sections.update', [$jd, 'authorities']), ['content_en' => 'x'])->assertForbidden();
    $this->actingAs($designer)->get(route('organization-design.position-design.show', $foreignPosition))->assertForbidden();
    $this->actingAs($designer)->get(route('organization-design.position-design.index'))
        ->assertInertia(fn ($page) => $page->component('OrganizationDesign/PositionDesign/Index')->where('positions.total', 0));
});

// 67 ─────────────────────────────────────────────────────────────────────────
test('the position design page carries the profile, links, requirements and job descriptions; the JD page carries sections in order', function (): void {
    $organization = jdOrganization('I');
    $designer = jdUser($organization, 'Position Designer');
    $approver = jdUser($organization, 'Position Approver');
    $position = jdPosition($organization, jdUnit($organization, 'U-1'));
    $task = jdTask($organization);
    $this->actingAs($designer)->put(route('organization-design.position-work-links.sync', $position), ['items' => [['object_type' => 'task', 'object_id' => $task->id]]])->assertRedirect();
    $this->actingAs($designer)->post(route('organization-design.position-requirements.derive', $position))->assertRedirect();
    jdPublishedProfile($organization, $position, $designer, $approver);
    $this->actingAs($designer)->post(route('organization-design.job-descriptions.store', $position), ['effective_from' => '2026-01-01'])->assertRedirect();
    $jd = JobDescription::query()->where('position_id', $position->id)->firstOrFail();

    $this->actingAs($designer)->get(route('organization-design.position-design.show', $position))
        ->assertInertia(fn ($page) => $page->component('OrganizationDesign/PositionDesign/Show')
            ->where('profile.status', 'published')
            ->where('workLinks.0.object.code', 'T-1')
            ->has('competencies', 1)
            ->has('qualifications', 1)
            ->where('jobDescriptions.0.version_no', 1));
    $this->actingAs($designer)->get(route('organization-design.job-descriptions.show', $jd))
        ->assertInertia(fn ($page) => $page->component('OrganizationDesign/JobDescriptions/Show')
            ->where('sections.0.section_key', 'purpose')
            ->where('sections.1.section_key', 'reporting')
            ->where('sections.2.section_key', 'duties')
            ->where('jobDescription.position.title_en', 'Senior Accountant'));
    $this->actingAs($designer)->get(route('organization-design.position-design.index', ['organization_id' => $organization->id]))
        ->assertInertia(fn ($page) => $page->where('positions.total', 1)->where('positions.data.0.profile_status', 'published')->where('positions.data.0.job_description_status', 'draft'));
});

// 68 ─────────────────────────────────────────────────────────────────────────
test('a job description cannot be drafted twice for the same position while one draft is open, and only from a published profile of that position', function (): void {
    $organization = jdOrganization('J');
    $designer = jdUser($organization, 'Position Designer');
    $approver = jdUser($organization, 'Position Approver');
    $position = jdPosition($organization, jdUnit($organization, 'U-1'));
    $other = jdPosition($organization, jdUnit($organization, 'U-2'), 'P-2');
    $foreignProfile = jdPublishedProfile($organization, $other, $designer, $approver);
    jdPublishedProfile($organization, $position, $designer, $approver);

    $this->actingAs($designer)->from('/')->post(route('organization-design.job-descriptions.store', $position), ['effective_from' => '2026-01-01', 'position_profile_id' => $foreignProfile->id])->assertSessionHasErrors('position_profile_id');
    $this->actingAs($designer)->post(route('organization-design.job-descriptions.store', $position), ['effective_from' => '2026-01-01'])->assertRedirect();
    $this->actingAs($designer)->from('/')->post(route('organization-design.job-descriptions.store', $position), ['effective_from' => '2026-02-01'])->assertSessionHasErrors('status');
    expect(JobDescription::query()->where('position_id', $position->id)->count())->toBe(1);
});
