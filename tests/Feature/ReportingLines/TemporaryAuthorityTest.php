<?php

declare(strict_types=1);

use App\Enums\TemporaryAuthorityStatus;
use App\Models\ActingAppointment;
use App\Models\SupervisorDelegation;
use App\Services\ReportingLines\ActingAppointmentService;
use App\Services\ReportingLines\AuthorityDecision;
use App\Services\ReportingLines\ReportingAuthority;
use App\Services\ReportingLines\ReportingGraphRepository;
use App\Services\ReportingLines\ReportingLineResolver;
use App\Services\ReportingLines\SupervisorActions;
use App\Services\ReportingLines\SupervisorDelegationService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\ReportingHierarchyFixture;

/*
 * Temporary authority (ADR 0025): an acting appointment fills a position
 * for a period; a delegation lends named actions without moving the
 * reporting line. Both are bounded by dates, revocable, written to the
 * audit log, and never exceed what the structure grants on the day.
 */

beforeEach(function (): void {
    $this->f = ReportingHierarchyFixture::build();
    app(ReportingGraphRepository::class)->forget();
    $this->resolver = app(ReportingLineResolver::class);
    $this->acting = app(ActingAppointmentService::class);
    $this->delegations = app(SupervisorDelegationService::class);
    $this->authority = app(ReportingAuthority::class);

    $this->director = $this->f->person('Director', $this->f->director);
    $this->leader = $this->f->person('Leader', $this->f->teamLeader);
    $this->alice = $this->f->person('Alice', $this->f->member);
    $this->bob = $this->f->person('Bob', $this->f->member);
    $this->officer = $this->f->person('Officer', $this->f->directorateOfficer);
    $this->hr = \App\Models\User::factory()->create();
    app(OrganizationScopeService::class)->clearCache();
});

afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

function rlErrors(callable $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

it('records an acting appointment with its window, authoriser and reason, and resolves to the acting person', function (): void {
    $appointment = $this->acting->appoint($this->hr, [
        'position_id' => $this->f->teamLeader->id, 'employee_id' => $this->officer->id,
        'starts_on' => today()->toDateString(), 'ends_on' => today()->addDays(10)->toDateString(), 'reason' => 'Leader on leave',
    ]);

    expect($appointment->status)->toBe(TemporaryAuthorityStatus::Active)
        ->and($appointment->authorized_by)->toBe($this->hr->id)
        ->and($this->resolver->directSupervisorOf($this->alice)->supervisorEmployeeUuid)->toBe($this->officer->id)
        ->and($this->resolver->directSupervisorOf($this->alice, null, today()->addDays(11))->supervisorEmployeeUuid)->toBe($this->leader->id)
        ->and(DB::table('audit_logs')->where('event_type', 'reporting_lines.acting_appointment_created')->count())->toBe(1);

    $this->acting->revoke($this->hr, $appointment, 'Leader is back');
    expect($appointment->fresh()->status)->toBe(TemporaryAuthorityStatus::Revoked)
        ->and($appointment->fresh()->revoked_by)->toBe($this->hr->id)
        ->and($this->resolver->directSupervisorOf($this->alice)->supervisorEmployeeUuid)->toBe($this->leader->id)
        ->and(rlErrors(fn () => $this->acting->revoke($this->hr, $appointment->fresh(), 'again')))->toHaveKey('status');
});

it('rejects conflicting or impossible acting appointments', function (): void {
    $base = ['position_id' => $this->f->teamLeader->id, 'employee_id' => $this->officer->id, 'starts_on' => today()->toDateString(), 'ends_on' => today()->addDays(10)->toDateString()];
    $this->acting->appoint($this->hr, $base);

    // Overlapping window on the same position, even for someone else.
    expect(rlErrors(fn () => $this->acting->appoint($this->hr, [...$base, 'employee_id' => $this->bob->id, 'starts_on' => today()->addDays(5)->toDateString(), 'ends_on' => null])))->toHaveKey('starts_on')
        // The substantive holder needs no acting appointment.
        ->and(rlErrors(fn () => $this->acting->appoint($this->hr, [...$base, 'employee_id' => $this->leader->id, 'starts_on' => today()->addDays(20)->toDateString(), 'ends_on' => null])))->toHaveKey('employee_id')
        // End before start.
        ->and(rlErrors(fn () => $this->acting->appoint($this->hr, [...$base, 'starts_on' => today()->addDays(30)->toDateString(), 'ends_on' => today()->addDays(29)->toDateString()])))->toHaveKey('ends_on')
        // Inactive position.
        ->and(rlErrors(function (): void {
            $this->f->financeDirector->update(['is_active' => false]);
            $this->acting->appoint($this->hr, ['position_id' => $this->f->financeDirector->id, 'employee_id' => $this->bob->id, 'starts_on' => today()->toDateString()]);
        }))->toHaveKey('position_id');

    // An employee of another organization cannot act here.
    $other = ReportingHierarchyFixture::build('XX');
    $stranger = $other->person('Stranger', $other->member);
    expect(rlErrors(fn () => $this->acting->appoint($this->hr, ['position_id' => $this->f->sectorHead->id, 'employee_id' => $stranger->id, 'starts_on' => today()->toDateString()])))->toHaveKey('employee_id');
});

it('grants direct, indirect and delegated authority and nothing else', function (): void {
    $leaderUser = $this->f->user($this->leader, ['reporting-lines.view_team']);
    $directorUser = $this->f->user($this->director, ['reporting-lines.view_team']);
    $officerUser = $this->f->user($this->officer);
    app(OrganizationScopeService::class)->clearCache();

    expect($this->authority->decide($leaderUser, $this->alice, SupervisorActions::FIELD_WORK_DECIDE)->basis)->toBe(AuthorityDecision::DIRECT)
        ->and($this->authority->decide($directorUser, $this->alice, SupervisorActions::VIEW_TEAM)->basis)->toBe(AuthorityDecision::INDIRECT)
        // A higher supervisor does not decide a direct-supervisor action.
        ->and($this->authority->decide($directorUser, $this->alice, SupervisorActions::FIELD_WORK_DECIDE)->granted)->toBeFalse()
        ->and($this->authority->decide($officerUser, $this->alice, SupervisorActions::FIELD_WORK_DECIDE)->granted)->toBeFalse()
        // Nobody acts on their own record; an account without an employee supervises nobody.
        ->and($this->authority->decide($leaderUser, $this->leader, SupervisorActions::VIEW_TEAM)->reason)->toBe('reporting-lines.authority.self')
        ->and($this->authority->decide($this->hr, $this->alice, SupervisorActions::VIEW_TEAM)->reason)->toBe('reporting-lines.authority.not_an_employee')
        ->and($this->authority->decide($leaderUser, $this->alice, 'made.up')->granted)->toBeFalse();

    // Delegation: the officer may decide field work for the leader's reports, and only that.
    $delegation = $this->delegations->delegate($leaderUser, $this->leader, [
        'delegate_employee_id' => $this->officer->id, 'actions' => [SupervisorActions::FIELD_WORK_DECIDE],
        'starts_on' => today()->toDateString(), 'ends_on' => today()->addDays(7)->toDateString(), 'reason' => 'Training week',
    ]);
    $decision = $this->authority->decide($officerUser, $this->alice, SupervisorActions::FIELD_WORK_DECIDE);
    expect($decision->basis)->toBe(AuthorityDecision::DELEGATED)
        ->and($decision->onBehalfOfEmployeeId)->toBe($this->leader->id)
        ->and($decision->delegationId)->toBe($delegation->id)
        ->and($this->authority->decide($officerUser, $this->alice, SupervisorActions::DAILY_ACTIVITY_REVIEW)->granted)->toBeFalse()
        // Never over the delegator's own record, never over someone the delegator does not supervise.
        ->and($this->authority->decide($officerUser, $this->leader, SupervisorActions::FIELD_WORK_DECIDE)->granted)->toBeFalse()
        ->and($this->authority->decide($officerUser, $this->director, SupervisorActions::FIELD_WORK_DECIDE)->granted)->toBeFalse()
        // Bounded by the delegator's live authority: once the leader stops supervising Alice, so does the delegate.
        ->and($this->authority->decide($officerUser, $this->alice, SupervisorActions::FIELD_WORK_DECIDE, null, today()->addDays(8))->granted)->toBeFalse();

    $this->leader->currentAssignment->update(['effective_to' => today()->subDay()->toDateString(), 'is_current' => false, 'assignment_status' => 'closed']);
    expect($this->authority->decide($officerUser, $this->alice, SupervisorActions::FIELD_WORK_DECIDE)->granted)->toBeFalse();
});

it('rejects unauthorised or conflicting delegations and applies revocation', function (): void {
    $leaderUser = $this->f->user($this->leader, ['reporting-lines.delegate']);
    $officerUser = $this->f->user($this->officer);
    $data = ['delegate_employee_id' => $this->officer->id, 'actions' => [SupervisorActions::FIELD_WORK_DECIDE], 'starts_on' => today()->toDateString(), 'ends_on' => null];

    expect(rlErrors(fn () => $this->delegations->delegate($leaderUser, $this->leader, [...$data, 'delegate_employee_id' => $this->leader->id])))->toHaveKey('delegate_employee_id')
        ->and(rlErrors(fn () => $this->delegations->delegate($leaderUser, $this->leader, [...$data, 'actions' => ['users.delete']])))->toHaveKey('actions')
        ->and(rlErrors(fn () => $this->delegations->delegate($leaderUser, $this->leader, [...$data, 'actions' => []])))->toHaveKey('actions')
        // Alice supervises nobody: nothing to delegate.
        ->and(rlErrors(fn () => $this->delegations->delegate($this->hr, $this->alice, [...$data, 'delegate_employee_id' => $this->bob->id])))->toHaveKey('delegator_employee_id');

    $first = $this->delegations->delegate($leaderUser, $this->leader, $data);
    expect(rlErrors(fn () => $this->delegations->delegate($leaderUser, $this->leader, $data)))->toHaveKey('starts_on')
        ->and(DB::table('audit_logs')->where('event_type', 'reporting_lines.delegation_created')->count())->toBe(1);

    // A different action for the same delegate is a separate, allowed delegation.
    $this->delegations->delegate($leaderUser, $this->leader, [...$data, 'actions' => [SupervisorActions::VIEW_TEAM]]);

    $this->delegations->revoke($leaderUser, $first, 'Back at work');
    expect($first->fresh()->status)->toBe(TemporaryAuthorityStatus::Revoked)
        ->and($this->authority->decide($officerUser, $this->alice, SupervisorActions::FIELD_WORK_DECIDE)->granted)->toBeFalse()
        ->and($this->authority->decide($officerUser, $this->alice, SupervisorActions::VIEW_TEAM)->granted)->toBeTrue()
        ->and(SupervisorDelegation::query()->count())->toBe(2);

    // A delegate cannot delegate what was lent to them (no chained delegation): the officer supervises nobody.
    expect(rlErrors(fn () => $this->delegations->delegate($this->hr, $this->officer, [...$data, 'delegate_employee_id' => $this->bob->id])))->toHaveKey('delegator_employee_id');
});

it('keeps authority inside the organization scope', function (): void {
    $other = ReportingHierarchyFixture::build('XX');
    $otherLeader = $other->person('Other Leader', $other->teamLeader);
    $otherMember = $other->person('Other Member', $other->member);
    $otherLeaderUser = $other->user($otherLeader, ['reporting-lines.view_team']);
    app(OrganizationScopeService::class)->clearCache();

    expect($this->authority->decide($otherLeaderUser, $otherMember, SupervisorActions::VIEW_TEAM)->granted)->toBeTrue()
        ->and($this->authority->decide($otherLeaderUser, $this->alice, SupervisorActions::VIEW_TEAM)->granted)->toBeFalse();

    // Even a forged delegation across organizations grants nothing: the delegator does not supervise the subject.
    SupervisorDelegation::query()->create([
        'organization_id' => $other->bureau->id, 'delegator_employee_id' => $this->leader->id, 'delegate_employee_id' => $otherLeader->id,
        'actions' => [SupervisorActions::VIEW_TEAM], 'starts_on' => today()->toDateString(), 'status' => 'active',
    ]);
    expect($this->authority->decide($otherLeaderUser, $this->alice, SupervisorActions::VIEW_TEAM)->granted)->toBeFalse();
});

it('reads acting appointments as of a date, not as they stand today', function (): void {
    ActingAppointment::query()->create([
        'organization_id' => $this->f->bureau->id, 'position_id' => $this->f->teamLeader->id, 'employee_id' => $this->officer->id,
        'starts_on' => '2026-03-01', 'ends_on' => '2026-03-31', 'status' => 'active',
    ]);

    expect($this->resolver->directSupervisorOf($this->alice, null, '2026-03-15')->supervisorEmployeeUuid)->toBe($this->officer->id)
        ->and($this->resolver->directSupervisorOf($this->alice, null, '2026-04-01')->supervisorEmployeeUuid)->toBe($this->leader->id);
});
