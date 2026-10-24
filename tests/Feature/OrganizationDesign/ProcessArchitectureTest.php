<?php

declare(strict_types=1);

use App\Enums\OrganizationDesignStatus;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionService;
use App\Models\PositionServiceSubService;
use App\Models\PositionServiceTask;
use App\Models\PositionServiceTaskStandard;
use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use App\Models\Service;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Models\WorkItemIo;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Organization Design & Workforce Planning, phase 3: the process
 * architecture (process → activity → task), typed inputs/outputs, work
 * standards on master tasks, and the bridge to Daily Work. Brief tests 16–25.
 */

beforeEach(function (): void {
    foreach ([
        'processes.viewAny', 'processes.view', 'processes.create', 'processes.update', 'processes.approve',
        'work_standards.manage', 'work_standards.approve', 'service-catalog.view',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web');
    Role::findOrCreate('Process Designer', 'web')->syncPermissions([
        'processes.viewAny', 'processes.view', 'processes.create', 'processes.update', 'work_standards.manage', 'service-catalog.view',
    ]);
    Role::findOrCreate('Process Approver', 'web')->syncPermissions(['processes.viewAny', 'processes.view', 'processes.approve', 'work_standards.approve']);
});

function paOrganization(string $suffix): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'PA-BUREAU'], ['name_en' => 'PA Bureau', 'is_demo' => false]);

    return Organization::query()->create([
        'organization_type_id' => $type->id, 'code' => 'PA-'.$suffix.'-'.uniqid(), 'name_en' => 'PA Org '.$suffix,
        'status' => OrganizationStatus::Active, 'effective_from' => now()->subYear()->toDateString(), 'is_demo' => false,
    ]);
}

function paDesignerFor(Organization $organization): User
{
    $user = User::factory()->create();
    $user->assignRole('Process Designer');
    UserOrganizationScope::query()->create([
        'user_id' => $user->id, 'organization_id' => $organization->id, 'scope_type' => OrganizationScopeType::Self,
        'is_active' => true, 'effective_from' => now()->subDay()->toDateString(),
    ]);

    return $user->fresh();
}

function paApprover(): User
{
    $user = User::factory()->create();
    $user->assignRole('Process Approver');
    // The approver works city-wide; organization-level roles need an explicit scope (ADR 0003).
    UserOrganizationScope::query()->create([
        'user_id' => $user->id, 'organization_id' => null, 'scope_type' => OrganizationScopeType::Citywide, 'is_active' => true,
    ]);

    return $user;
}

function paService(Organization $organization, string $code = 'SRV-1'): Service
{
    return Service::query()->create([
        'organization_id' => $organization->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => $code,
        'name_en' => 'Service '.$code, 'effective_from' => '2021-01-01', 'status' => OrganizationDesignStatus::Published,
    ]);
}

/** @return array<string, mixed> */
function paProcessPayload(Organization $organization, Service $service, array $overrides = []): array
{
    return [
        'organization_id' => $organization->id, 'service_id' => $service->id, 'code' => 'PRC-'.substr(uniqid(), -5),
        'name_en' => 'Hire an employee', 'name_am' => 'ሠራተኛ መቅጠር', 'process_type' => 'core', 'effective_from' => '2022-01-01', ...$overrides,
    ];
}

function paDraftProcess(Organization $organization, User $designer, array $overrides = []): Process
{
    $service = Service::query()->where('organization_id', $organization->id)->first() ?? paService($organization);
    $payload = paProcessPayload($organization, $service, $overrides);
    test()->actingAs($designer)->post(route('organization-design.processes.store'), $payload)->assertRedirect();

    return Process::query()->where('code', $payload['code'])->firstOrFail();
}

function paActivity(User $designer, Process $process, string $code, ?string $parentId = null): ProcessActivity
{
    test()->actingAs($designer)->post(route('organization-design.processes.activities.store', $process), [
        'code' => $code, 'name_en' => 'Activity '.$code, 'parent_activity_id' => $parentId, 'sequence' => 1,
    ])->assertRedirect();

    return ProcessActivity::query()->where('process_id', $process->id)->where('code', $code)->firstOrFail();
}

function paTask(User $designer, ProcessActivity $activity, string $code): ProcessTask
{
    test()->actingAs($designer)->post(route('organization-design.activities.tasks.store', $activity), [
        'code' => $code, 'name_en' => 'Task '.$code, 'sequence' => 1, 'required_output' => 'Signed record',
    ])->assertRedirect();

    return ProcessTask::query()->where('activity_id', $activity->id)->where('code', $code)->firstOrFail();
}

/** @return array<string, mixed> */
function paStandardPayload(array $overrides = []): array
{
    return ['planned_quantity' => '5', 'quantity_unit' => 'files', 'planned_time_minutes' => '30', 'quality_source' => 'employee', 'effective_from' => '2022-01-01', ...$overrides];
}

// 16 ─────────────────────────────────────────────────────────────────────────
test('a process belongs to a service of its own organization and is internally coded, never by ISIC', function (): void {
    $organization = paOrganization('A');
    $other = paOrganization('A2');
    $designer = paDesignerFor($organization);
    $service = paService($organization);
    $foreignService = paService($other, 'SRV-X');

    $this->actingAs($designer)->from('/')->post(route('organization-design.processes.store'), paProcessPayload($organization, $foreignService))
        ->assertSessionHasErrors('service_id');

    $this->actingAs($designer)->post(route('organization-design.processes.store'), paProcessPayload($organization, $service, ['code' => 'HR-WFP-001']))->assertRedirect();
    $process = Process::query()->where('code', 'HR-WFP-001')->firstOrFail();
    expect($process->status)->toBe(OrganizationDesignStatus::Draft)
        ->and($process->service_id)->toBe($service->id)
        ->and($process->getAttributes())->not->toHaveKey('isic_activity_id');

    // The code is unique per organization and version, not globally.
    $this->actingAs($designer)->from('/')->post(route('organization-design.processes.store'), paProcessPayload($organization, $service, ['code' => 'HR-WFP-001']))
        ->assertSessionHasErrors('code');
});

// 17 ─────────────────────────────────────────────────────────────────────────
test('activities nest under a process without cycles and tasks hang off activities in sequence', function (): void {
    $organization = paOrganization('B');
    $designer = paDesignerFor($organization);
    $process = paDraftProcess($organization, $designer);

    $a1 = paActivity($designer, $process, 'A-1');
    $a11 = paActivity($designer, $process, 'A-1-1', $a1->id);
    $a111 = paActivity($designer, $process, 'A-1-1-1', $a11->id);

    // Moving the root under its own descendant is refused.
    $this->actingAs($designer)->from('/')->put(route('organization-design.activities.update', $a1), ['parent_activity_id' => $a111->id])
        ->assertSessionHasErrors('parent_activity_id');

    $t1 = paTask($designer, $a11, 'T-1');
    $this->actingAs($designer)->post(route('organization-design.activities.tasks.store', $a11), ['code' => 'T-2', 'name_en' => 'Task T-2', 'sequence' => 0])->assertRedirect();
    expect($a11->tasks()->orderBy('sequence')->pluck('code')->all())->toBe(['T-2', 'T-1'])
        ->and($t1->activity_id)->toBe($a11->id);

    // A task under a different process's activity cannot be moved here; codes are unique per activity.
    $this->actingAs($designer)->from('/')->post(route('organization-design.activities.tasks.store', $a11), ['code' => 'T-1', 'name_en' => 'Dup', 'sequence' => 3])
        ->assertSessionHasErrors('code');

    // The tree is served nested from one query.
    $this->actingAs($designer)->get(route('organization-design.processes.show', $process))
        ->assertInertia(fn ($page) => $page->component('OrganizationDesign/Processes/Show')
            ->where('activities.0.code', 'A-1')
            ->where('activities.0.children.0.code', 'A-1-1')
            ->where('activities.0.children.0.children.0.code', 'A-1-1-1')
            ->where('activities.0.children.0.tasks.0.code', 'T-2'));
});

// 18 ─────────────────────────────────────────────────────────────────────────
test('inputs, outputs, beneficiaries and dependencies are typed rows on processes, activities and tasks', function (): void {
    $organization = paOrganization('C');
    $designer = paDesignerFor($organization);
    $process = paDraftProcess($organization, $designer);
    $a1 = paActivity($designer, $process, 'A-1');
    $a2 = paActivity($designer, $process, 'A-2');
    $task = paTask($designer, $a1, 'T-1');

    $this->actingAs($designer)->put(route('organization-design.io.sync', ['subjectType' => 'activity', 'subject' => $a2->id]), ['items' => [
        ['kind' => 'input', 'label_en' => 'Application form', 'label_am' => 'የማመልከቻ ቅጽ'],
        ['kind' => 'output', 'label_en' => 'Appointment letter'],
        ['kind' => 'beneficiary', 'label_en' => 'Applicant'],
        ['kind' => 'dependency', 'label_en' => 'After screening', 'depends_on_activity_id' => $a1->id],
    ]])->assertRedirect();

    $rows = WorkItemIo::query()->where('subject_type', $a2->getMorphClass())->where('subject_id', $a2->id)->orderBy('sequence')->get();
    expect($rows->pluck('kind')->map(fn ($kind) => $kind->value)->all())->toBe(['input', 'output', 'beneficiary', 'dependency'])
        ->and($rows->last()->depends_on_activity_id)->toBe($a1->id);

    // A dependency must point at an activity of the same process; an unknown kind is refused.
    $foreign = paActivity($designer, paDraftProcess($organization, $designer), 'A-9');
    $this->actingAs($designer)->from('/')->put(route('organization-design.io.sync', ['subjectType' => 'activity', 'subject' => $a2->id]), ['items' => [
        ['kind' => 'dependency', 'label_en' => 'x', 'depends_on_activity_id' => $foreign->id],
    ]])->assertSessionHasErrors('items.0.depends_on_activity_id');
    $this->actingAs($designer)->from('/')->put(route('organization-design.io.sync', ['subjectType' => 'task', 'subject' => $task->id]), ['items' => [
        ['kind' => 'remark', 'label_en' => 'free text'],
    ]])->assertSessionHasErrors('items.0.kind');

    // Re-syncing replaces the set; it never leaves orphans.
    $this->actingAs($designer)->put(route('organization-design.io.sync', ['subjectType' => 'activity', 'subject' => $a2->id]), ['items' => [
        ['kind' => 'output', 'label_en' => 'Only output'],
    ]])->assertRedirect();
    expect(WorkItemIo::query()->where('subject_id', $a2->id)->count())->toBe(1);
});

// 19 ─────────────────────────────────────────────────────────────────────────
test('a published process is immutable and a new version deep-copies its activities, tasks and io rows', function (): void {
    $organization = paOrganization('D');
    $designer = paDesignerFor($organization);
    $approver = paApprover();
    $process = paDraftProcess($organization, $designer, ['code' => 'PRC-V']);
    $a1 = paActivity($designer, $process, 'A-1');
    $task = paTask($designer, $a1, 'T-1');
    $this->actingAs($designer)->put(route('organization-design.io.sync', ['subjectType' => 'task', 'subject' => $task->id]), ['items' => [['kind' => 'output', 'label_en' => 'Letter']]])->assertRedirect();

    $this->actingAs($approver)->post(route('organization-design.processes.publish', $process))->assertRedirect();
    $process->refresh();
    expect($process->status)->toBe(OrganizationDesignStatus::Published);

    // Nothing under a published process changes.
    $this->actingAs($designer)->from('/')->put(route('organization-design.processes.update', $process), ['name_en' => 'Changed'])->assertSessionHasErrors('status');
    $this->actingAs($designer)->from('/')->post(route('organization-design.processes.activities.store', $process), ['code' => 'A-2', 'name_en' => 'Late'])->assertSessionHasErrors('status');
    $this->actingAs($designer)->from('/')->put(route('organization-design.activities.update', $a1), ['name_en' => 'Changed'])->assertSessionHasErrors('status');
    $this->actingAs($designer)->from('/')->delete(route('organization-design.tasks.destroy', $task))->assertSessionHasErrors('status');
    expect($a1->fresh()->name_en)->toBe('Activity A-1');

    // The new version carries the whole tree; editing the copy leaves v1 alone.
    $this->actingAs($designer)->post(route('organization-design.processes.versions.store', $process), ['effective_from' => '2023-01-01'])->assertRedirect();
    $v2 = Process::query()->where('lineage_key', $process->lineage_key)->where('version_no', 2)->firstOrFail();
    $copiedActivity = ProcessActivity::query()->where('process_id', $v2->id)->where('code', 'A-1')->firstOrFail();
    $copiedTask = ProcessTask::query()->where('activity_id', $copiedActivity->id)->where('code', 'T-1')->firstOrFail();
    expect($copiedActivity->id)->not->toBe($a1->id)
        ->and(WorkItemIo::query()->where('subject_id', $copiedTask->id)->count())->toBe(1)
        ->and(WorkItemIo::query()->where('subject_id', $task->id)->count())->toBe(1);

    $this->actingAs($designer)->put(route('organization-design.activities.update', $copiedActivity), ['name_en' => 'Renamed in v2'])->assertRedirect();
    expect($a1->fresh()->name_en)->toBe('Activity A-1')->and($copiedActivity->fresh()->name_en)->toBe('Renamed in v2');

    $this->actingAs($approver)->post(route('organization-design.processes.publish', $v2))->assertRedirect();
    expect($process->fresh()->status)->toBe(OrganizationDesignStatus::Superseded)
        ->and($process->fresh()->effective_to?->toDateString())->toBe('2022-12-31')
        ->and(Process::query()->where('lineage_key', $process->lineage_key)->count())->toBe(2);
});

// 20 ─────────────────────────────────────────────────────────────────────────
test('a master task carries versioned work standards: approving closes the previous version and approved versions are immutable', function (): void {
    $organization = paOrganization('E');
    $designer = paDesignerFor($organization);
    $approver = paApprover();
    $process = paDraftProcess($organization, $designer);
    $task = paTask($designer, paActivity($designer, $process, 'A-1'), 'T-1');

    $this->actingAs($designer)->post(route('organization-design.tasks.standards.store', $task), paStandardPayload())->assertRedirect();
    $v1 = PositionServiceTaskStandard::query()->where('master_task_id', $task->id)->firstOrFail();
    expect($v1->version_no)->toBe(1)->and($v1->task_id)->toBeNull()->and($v1->organization_id)->toBe($organization->id);

    $this->actingAs($approver)->post(route('organization-design.standards.approve', $v1))->assertRedirect();
    $this->actingAs($designer)->from('/')->put(route('organization-design.standards.update', $v1), paStandardPayload(['planned_quantity' => '9']))
        ->assertSessionHasErrors('status');

    $this->actingAs($designer)->post(route('organization-design.tasks.standards.store', $task), paStandardPayload(['planned_quantity' => '8', 'effective_from' => now()->addDays(10)->toDateString()]))->assertRedirect();
    $v2 = PositionServiceTaskStandard::query()->where('master_task_id', $task->id)->where('version_no', 2)->firstOrFail();
    $this->actingAs($approver)->post(route('organization-design.standards.approve', $v2))->assertRedirect();

    expect($v1->fresh()->effective_to?->toDateString())->toBe(now()->addDays(9)->toDateString())
        ->and((string) $v1->fresh()->planned_quantity)->toBe('5.0000')
        ->and($task->standards()->count())->toBe(2);
});

// 21 ─────────────────────────────────────────────────────────────────────────
test('a standard measures exactly one subject: a position task, a master task or an activity', function (): void {
    $organization = paOrganization('F');
    $designer = paDesignerFor($organization);
    $process = paDraftProcess($organization, $designer);
    $activity = paActivity($designer, $process, 'A-1');
    $task = paTask($designer, $activity, 'T-1');

    // Activities can be measured too (a standard on an activity, no task).
    $this->actingAs($designer)->post(route('organization-design.activities.standards.store', $activity), paStandardPayload())->assertRedirect();
    $onActivity = PositionServiceTaskStandard::query()->where('activity_id', $activity->id)->firstOrFail();
    expect($onActivity->master_task_id)->toBeNull()->and($onActivity->task_id)->toBeNull();

    // Two subjects on one row is refused at the model boundary.
    expect(fn () => PositionServiceTaskStandard::query()->create([
        'master_task_id' => $task->id, 'activity_id' => $activity->id, 'organization_id' => $organization->id, 'version_no' => 7,
        'planned_quantity' => 1, 'effective_from' => '2022-01-01',
    ]))->toThrow(LogicException::class);
    expect(fn () => PositionServiceTaskStandard::query()->create([
        'organization_id' => $organization->id, 'version_no' => 8, 'planned_quantity' => 1, 'effective_from' => '2022-01-01',
    ]))->toThrow(LogicException::class);
});

// 22 ─────────────────────────────────────────────────────────────────────────
test('a position task can be traced to a master task of the same organization and Daily Work keeps its own standards', function (): void {
    $organization = paOrganization('G');
    $other = paOrganization('G2');
    $designer = paDesignerFor($organization);
    $process = paDraftProcess($organization, $designer);
    $master = paTask($designer, paActivity($designer, $process, 'A-1'), 'T-1');
    $foreignMaster = paTask(paDesignerFor($other), paActivity(paDesignerFor($other), paDraftProcess($other, paDesignerFor($other)), 'A-9'), 'T-9');

    $unit = OrganizationUnit::query()->create(['organization_id' => $organization->id, 'code' => 'PA-U', 'name_en' => 'Unit', 'unit_type' => 'directorate', 'status' => 'active']);
    $position = Position::query()->create(['organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'job_position_code' => 'PA-P1', 'title_en' => 'Officer', 'is_active' => true]);
    $positionService = PositionService::query()->create(['organization_id' => $organization->id, 'position_id' => $position->id, 'service_no' => 1, 'name_en' => 'Hiring', 'is_active' => true]);
    $sub = PositionServiceSubService::query()->create(['position_service_id' => $positionService->id, 'organization_id' => $organization->id, 'code' => 'SS-1', 'name_en' => 'Screening']);
    $positionTask = PositionServiceTask::query()->create(['sub_service_id' => $sub->id, 'position_service_id' => $positionService->id, 'organization_id' => $organization->id, 'code' => 'PT-1', 'name_en' => 'Screen files']);

    $this->actingAs($designer)->from('/')->put(route('organization-design.position-tasks.link', $positionTask), ['master_task_id' => $foreignMaster->id])
        ->assertSessionHasErrors('master_task_id');
    $this->actingAs($designer)->put(route('organization-design.position-tasks.link', $positionTask), ['master_task_id' => $master->id])->assertRedirect();
    expect($positionTask->fresh()->master_task_id)->toBe($master->id)
        ->and($master->positionTasks()->pluck('code')->all())->toBe(['PT-1']);

    // A standard on the master task never becomes the position task's standard by itself.
    $this->actingAs($designer)->post(route('organization-design.tasks.standards.store', $master), paStandardPayload())->assertRedirect();
    expect(PositionServiceTaskStandard::query()->where('task_id', $positionTask->id)->count())->toBe(0)
        ->and(PositionServiceTaskStandard::query()->where('master_task_id', $master->id)->count())->toBe(1);

    // Unlinking is allowed; the position task keeps working.
    $this->actingAs($designer)->put(route('organization-design.position-tasks.link', $positionTask), ['master_task_id' => null])->assertRedirect();
    expect($positionTask->fresh()->master_task_id)->toBeNull();
});

// 23 ─────────────────────────────────────────────────────────────────────────
test('a scoped designer only sees and writes processes of their own organization', function (): void {
    $mine = paOrganization('H');
    $theirs = paOrganization('H2');
    $designer = paDesignerFor($mine);
    $foreignDesigner = paDesignerFor($theirs);
    $own = paDraftProcess($mine, $designer, ['code' => 'PRC-MINE']);
    $foreign = paDraftProcess($theirs, $foreignDesigner, ['code' => 'PRC-THEIRS']);
    $foreignActivity = paActivity($foreignDesigner, $foreign, 'A-1');

    $this->actingAs($designer)->get(route('organization-design.processes.index'))
        ->assertInertia(fn ($page) => $page->component('OrganizationDesign/Processes/Index')->where('processes.total', 1)->where('processes.data.0.code', 'PRC-MINE'));
    $this->actingAs($designer)->get(route('organization-design.processes.show', $foreign))->assertForbidden();
    $this->actingAs($designer)->put(route('organization-design.processes.update', $foreign), ['name_en' => 'x'])->assertForbidden();
    $this->actingAs($designer)->post(route('organization-design.processes.activities.store', $foreign), ['code' => 'A-2', 'name_en' => 'x'])->assertForbidden();
    $this->actingAs($designer)->put(route('organization-design.activities.update', $foreignActivity), ['name_en' => 'x'])->assertForbidden();
    $this->actingAs($designer)->from('/')->post(route('organization-design.processes.store'), paProcessPayload($theirs, paService($theirs, 'SRV-T')))->assertForbidden();
    $this->actingAs($designer)->get(route('organization-design.processes.show', $own))->assertOk();
});

// 24 ─────────────────────────────────────────────────────────────────────────
test('the designer who drafted a process cannot publish it, and publishing needs the approve permission', function (): void {
    $organization = paOrganization('I');
    $designer = paDesignerFor($organization);
    $process = paDraftProcess($organization, $designer);

    $this->actingAs($designer)->post(route('organization-design.processes.publish', $process))->assertForbidden();

    $selfApprover = paDesignerFor($organization);
    $selfApprover->assignRole('Process Approver');
    $own = paDraftProcess($organization, $selfApprover, ['code' => 'PRC-SELF']);
    $this->actingAs($selfApprover)->from('/')->post(route('organization-design.processes.publish', $own))->assertSessionHasErrors('status');
    expect($own->fresh()->status)->toBe(OrganizationDesignStatus::Draft);

    $this->actingAs(paApprover())->post(route('organization-design.processes.publish', $process))->assertRedirect();
    expect($process->fresh()->status)->toBe(OrganizationDesignStatus::Published)->and($process->fresh()->approved_by)->not->toBeNull();
});

// 25 ─────────────────────────────────────────────────────────────────────────
test('removing an activity with tasks or children is refused, and removing a task with standards or linked position tasks is refused', function (): void {
    $organization = paOrganization('J');
    $designer = paDesignerFor($organization);
    $process = paDraftProcess($organization, $designer);
    $a1 = paActivity($designer, $process, 'A-1');
    $a11 = paActivity($designer, $process, 'A-1-1', $a1->id);
    $task = paTask($designer, $a11, 'T-1');

    $this->actingAs($designer)->from('/')->delete(route('organization-design.activities.destroy', $a1))->assertSessionHasErrors('activity');
    $this->actingAs($designer)->from('/')->delete(route('organization-design.activities.destroy', $a11))->assertSessionHasErrors('activity');

    $this->actingAs($designer)->post(route('organization-design.tasks.standards.store', $task), paStandardPayload())->assertRedirect();
    $this->actingAs($designer)->from('/')->delete(route('organization-design.tasks.destroy', $task))->assertSessionHasErrors('task');

    $standard = PositionServiceTaskStandard::query()->where('master_task_id', $task->id)->firstOrFail();
    $this->actingAs($designer)->delete(route('organization-design.standards.destroy', $standard))->assertRedirect();
    $this->actingAs($designer)->delete(route('organization-design.tasks.destroy', $task))->assertRedirect();
    $this->actingAs($designer)->delete(route('organization-design.activities.destroy', $a11))->assertRedirect();
    $this->actingAs($designer)->delete(route('organization-design.activities.destroy', $a1))->assertRedirect();
    expect(ProcessActivity::query()->where('process_id', $process->id)->count())->toBe(0);
});
