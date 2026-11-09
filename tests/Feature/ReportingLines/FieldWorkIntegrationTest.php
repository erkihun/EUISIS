<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\ReportingLines\ReportingGraphRepository;
use App\Services\ReportingLines\SupervisorActions;
use App\Services\ReportingLines\SupervisorDelegationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Modules\DailyWork\Contracts\Data\ManagerResolution;
use Modules\DailyWork\Domain\Models\DailyActivityReviewerAssignment;
use Modules\FieldWork\Contracts\Data\FieldWorkRoles;
use Modules\FieldWork\Contracts\Data\FieldWorkStatus;
use Modules\FieldWork\Domain\Models\FieldWorkRequest;
use Modules\FieldWork\Domain\Models\FieldWorkType;
use Tests\Support\ReportingHierarchyFixture;

/*
 * Field work is the workflow that explicitly needs the direct supervisor
 * (ADR 0025). Off: exactly the reviewer-assignment behaviour as before. On:
 * the organization structure names the supervisor and records which rule
 * did; authority is re-checked live at decision time; a delegate may decide
 * in the supervisor's place and the record shows for whom; completed
 * decisions keep their actor after the authority that allowed them ends.
 */

const RL_DAY = '2026-10-12';

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse(RL_DAY.' 08:00:00', 'Africa/Addis_Ababa')->setTimezone(date_default_timezone_get()));
    Notification::fake();

    $this->f = ReportingHierarchyFixture::build();
    app(ReportingGraphRepository::class)->forget();
    $this->destination = Organization::query()->create(['organization_type_id' => $this->f->bureau->organization_type_id, 'code' => 'RL-DEST', 'name_en' => 'Health Bureau', 'status' => 'active']);
    $this->type = FieldWorkType::query()->where('code', 'INSPECTION')->firstOrFail();

    $this->director = $this->f->person('Director', $this->f->director);
    $this->leader = $this->f->person('Leader', $this->f->teamLeader);
    $this->alice = $this->f->person('Alice', $this->f->member);
    $this->officer = $this->f->person('Officer', $this->f->directorateOfficer);

    $this->aliceUser = $this->f->user($this->alice, FieldWorkRoles::EMPLOYEE_PERMISSIONS);
    $this->leaderUser = $this->f->user($this->leader, [...FieldWorkRoles::EMPLOYEE_PERMISSIONS, ...FieldWorkRoles::SUPERVISOR_PERMISSIONS, 'reporting-lines.delegate'], 'RL Supervisor');
    $this->directorUser = $this->f->user($this->director, [...FieldWorkRoles::EMPLOYEE_PERMISSIONS, ...FieldWorkRoles::SUPERVISOR_PERMISSIONS], 'RL Supervisor');
    $this->officerUser = $this->f->user($this->officer, [...FieldWorkRoles::EMPLOYEE_PERMISSIONS, ...FieldWorkRoles::SUPERVISOR_PERMISSIONS], 'RL Supervisor');
    app(OrganizationScopeService::class)->clearCache();
});

afterEach(fn () => Carbon::setTestNow());

function rlSubmit(User $user): FieldWorkRequest
{
    test()->actingAs($user)->post(route('employee.field-work.store'), [
        'action' => 'draft', 'field_work_type_id' => test()->type->id, 'purpose' => 'Inspect the records office',
        'destination_type' => 'registered_organization', 'destination_organization_id' => test()->destination->id,
        'start_date' => RL_DAY, 'start_time' => '09:00', 'return_date' => RL_DAY, 'return_time' => '15:00',
    ])->assertSessionHasNoErrors();
    $request = FieldWorkRequest::query()->latest('created_at')->latest('id')->firstOrFail();
    test()->actingAs($user)->post(route('employee.field-work.submit', $request))->assertSessionHasNoErrors();

    return $request->fresh();
}

it('keeps the reviewer-assignment behaviour while structural reporting is off', function (): void {
    config(['features.structural_reporting' => false]);
    DailyActivityReviewerAssignment::query()->create(['reviewer_user_id' => $this->directorUser->id, 'organization_id' => $this->f->bureau->id, 'organization_unit_id' => $this->f->directorate->id, 'include_sub_units' => true, 'is_active' => true]);

    $request = rlSubmit($this->aliceUser);
    expect($request->supervisor_user_id)->toBe($this->directorUser->id)
        ->and($request->supervisor_basis)->toBe(ManagerResolution::BASIS_REVIEWER_ASSIGNMENT);

    // The unit head holds no reviewer assignment, so the structure grants nothing yet.
    $this->actingAs($this->leaderUser)->post(route('field-work.requests.approve', $request))->assertForbidden();
    $this->actingAs($this->directorUser)->post(route('field-work.requests.approve', $request))->assertSessionHasNoErrors();
    expect($request->fresh()->status)->toBe(FieldWorkStatus::Approved);
});

it('names the unit head as supervisor when structural reporting is on and records the basis', function (): void {
    config(['features.structural_reporting' => true]);

    $request = rlSubmit($this->aliceUser);
    expect($request->supervisor_user_id)->toBe($this->leaderUser->id)
        ->and($request->supervisor_basis)->toBe(ManagerResolution::BASIS_UNIT_HEAD)
        ->and($request->supervisor_resolution->value)->toBe('resolved');

    // The higher supervisor does not decide a direct-supervisor action; the direct supervisor does.
    $this->actingAs($this->directorUser)->post(route('field-work.requests.approve', $request))->assertForbidden();
    $this->actingAs($this->officerUser)->post(route('field-work.requests.approve', $request))->assertForbidden();
    $this->actingAs($this->leaderUser)->post(route('field-work.requests.approve', $request))->assertSessionHasNoErrors();
    $request->refresh();
    expect($request->status)->toBe(FieldWorkStatus::Approved)
        ->and($request->decided_by)->toBe($this->leaderUser->id)
        ->and($request->decided_for_user_id)->toBeNull()
        ->and($request->supervisor_basis)->toBe(ManagerResolution::BASIS_UNIT_HEAD);
});

it('falls back to the reviewer assignment when the structure names nobody, and waits when neither does', function (): void {
    config(['features.structural_reporting' => true]);
    $this->leader->currentAssignment->update(['effective_to' => '2026-09-30', 'is_current' => false, 'assignment_status' => 'closed']);

    $waiting = rlSubmit($this->aliceUser);
    expect($waiting->supervisor_user_id)->toBeNull()->and($waiting->supervisor_resolution->value)->toBe('supervisor_not_resolved')->and($waiting->supervisor_basis)->toBeNull();
    // A vacant head never escalates by itself: the director cannot decide it.
    $this->actingAs($this->directorUser)->post(route('field-work.requests.approve', $waiting))->assertForbidden();

    DailyActivityReviewerAssignment::query()->create(['reviewer_user_id' => $this->directorUser->id, 'organization_id' => $this->f->bureau->id, 'organization_unit_id' => $this->f->team->id, 'include_sub_units' => true, 'is_active' => true]);
    $this->actingAs($this->aliceUser)->post(route('employee.field-work.cancel', $waiting))->assertSessionHasNoErrors();
    $covered = rlSubmit($this->aliceUser);
    expect($covered->supervisor_user_id)->toBe($this->directorUser->id)->and($covered->supervisor_basis)->toBe(ManagerResolution::BASIS_REVIEWER_ASSIGNMENT);
});

it('re-resolves a pending request after the head changes: the successor decides, the predecessor cannot', function (): void {
    config(['features.structural_reporting' => true]);
    $request = rlSubmit($this->aliceUser);
    expect($request->supervisor_user_id)->toBe($this->leaderUser->id);

    // The leader leaves; the officer takes the leader position from today.
    $this->leader->currentAssignment->update(['effective_to' => Carbon::parse(RL_DAY)->subDay()->toDateString(), 'is_current' => false, 'assignment_status' => 'closed']);
    $this->officer->currentAssignment->update(['effective_to' => Carbon::parse(RL_DAY)->subDay()->toDateString(), 'is_current' => false, 'assignment_status' => 'closed']);
    $this->f->place($this->officer, $this->f->teamLeader, RL_DAY);
    app(ReportingGraphRepository::class)->forget();

    $this->actingAs($this->leaderUser)->post(route('field-work.requests.approve', $request))->assertForbidden();
    $this->actingAs($this->officerUser)->post(route('field-work.requests.approve', $request))->assertSessionHasNoErrors();
    expect($request->fresh()->status)->toBe(FieldWorkStatus::Approved)->and($request->fresh()->decided_by)->toBe($this->officerUser->id);
});

it('lets a delegate decide in the supervisor\'s place, records whose authority was used, and keeps the history after revocation', function (): void {
    config(['features.structural_reporting' => true]);
    $delegation = app(SupervisorDelegationService::class)->delegate($this->leaderUser, $this->leader, [
        'delegate_employee_id' => $this->officer->id, 'actions' => [SupervisorActions::FIELD_WORK_DECIDE], 'starts_on' => RL_DAY, 'ends_on' => null,
    ]);
    $request = rlSubmit($this->aliceUser);
    expect($request->supervisor_user_id)->toBe($this->leaderUser->id);

    // The delegate sees it in the team queue and may decide; a delegation for a different action would not do.
    $this->actingAs($this->officerUser)->get(route('field-work.pending'))->assertOk()->assertSee($request->id, false);
    $this->actingAs($this->officerUser)->post(route('field-work.requests.approve', $request))->assertSessionHasNoErrors();
    $request->refresh();
    expect($request->status)->toBe(FieldWorkStatus::Approved)
        ->and($request->decided_by)->toBe($this->officerUser->id)
        ->and($request->decided_for_user_id)->toBe($this->leaderUser->id)
        ->and($request->supervisor_user_id)->toBe($this->leaderUser->id)
        ->and($request->supervisor_basis)->toBe('delegated');

    // Revoking the delegation changes nothing already decided, and the delegate decides nothing new.
    app(SupervisorDelegationService::class)->revoke($this->leaderUser, $delegation, 'Back');
    $request->refresh();
    expect($request->status)->toBe(FieldWorkStatus::Approved)->and($request->decided_by)->toBe($this->officerUser->id);
    $again = rlSubmit($this->f->user($this->f->person('Bob', $this->f->member), FieldWorkRoles::EMPLOYEE_PERMISSIONS));
    $this->actingAs($this->officerUser)->post(route('field-work.requests.approve', $again))->assertForbidden();
});

it('never lets a request be decided by its own requester, even a unit head or a delegate', function (): void {
    config(['features.structural_reporting' => true]);
    app(SupervisorDelegationService::class)->delegate($this->leaderUser, $this->leader, [
        'delegate_employee_id' => $this->alice->id, 'actions' => [SupervisorActions::FIELD_WORK_DECIDE], 'starts_on' => RL_DAY, 'ends_on' => null,
    ]);

    // The leader's own request goes to the director, not to the leader; Alice, a delegate, cannot decide it either
    // (the leader does not supervise themself), and cannot decide her own.
    $own = rlSubmit($this->leaderUser);
    expect($own->supervisor_user_id)->toBe($this->directorUser->id)->and($own->supervisor_basis)->toBe(ManagerResolution::BASIS_UNIT_HEAD);
    $this->actingAs($this->leaderUser)->post(route('field-work.requests.approve', $own))->assertForbidden();
    $this->actingAs($this->aliceUser)->post(route('field-work.requests.approve', $own))->assertForbidden();

    $mine = rlSubmit($this->aliceUser);
    $this->actingAs($this->aliceUser)->post(route('field-work.requests.approve', $mine))->assertForbidden();
});
