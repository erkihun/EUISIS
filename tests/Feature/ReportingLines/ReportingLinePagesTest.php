<?php

declare(strict_types=1);

use App\Models\ActingAppointment;
use App\Models\SupervisorDelegation;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\ReportingLines\ReportingGraphRepository;
use App\Services\ReportingLines\SupervisorActions;
use App\Support\Rbac\ReportingLineRoles;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReportingHierarchyFixture;

/*
 * The reporting-line pages (ADR 0025) enforce permission AND relationship on
 * the server: one's own line needs nothing, a supervisor sees only people
 * under them, HR sees its scope, and nobody sees another organization.
 */

beforeEach(function (): void {
    $this->withoutVite();
    $this->f = ReportingHierarchyFixture::build();
    app(ReportingGraphRepository::class)->forget();

    $this->director = $this->f->person('Director', $this->f->director);
    $this->leader = $this->f->person('Leader', $this->f->teamLeader);
    $this->alice = $this->f->person('Alice', $this->f->member);
    $this->officer = $this->f->person('Officer', $this->f->directorateOfficer);

    $this->aliceUser = $this->f->user($this->alice);
    $this->leaderUser = $this->f->user($this->leader, ReportingLineRoles::SUPERVISOR_PERMISSIONS, ReportingLineRoles::SUPERVISOR_ROLE);
    $this->officerUser = $this->f->user($this->officer);
    $this->hrUser = $this->f->user($this->f->person('HR', $this->f->advisor), ['reporting-lines.view_any', 'reporting-lines.manage', 'employees.view'], 'RL HR', scopeRow: true);

    $this->other = ReportingHierarchyFixture::build('XX');
    $this->strangerLeader = $this->other->person('Stranger Leader', $this->other->teamLeader);
    $this->stranger = $this->other->person('Stranger', $this->other->member);
    $this->strangerHr = $this->other->user($this->other->person('Other HR', $this->other->advisor), ['reporting-lines.view_any', 'reporting-lines.manage'], 'RL HR', scopeRow: true);
    app(OrganizationScopeService::class)->clearCache();
});

it('shows the overview and integrity report to readers of reporting lines, inside their scope only', function (): void {
    $this->actingAs($this->aliceUser)->get(route('reporting-lines.overview'))->assertForbidden();

    $this->actingAs($this->hrUser)->get(route('reporting-lines.overview'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ReportingLines/Overview')
            ->where('selected_organization_id', $this->f->bureau->id)
            ->has('organizations', 1)
            ->has('overview.units', 4)
            ->where('overview.organization_head.state', 'vacant')
            ->has('overview.diagnostics.findings'));

    $this->actingAs($this->hrUser)->get(route('reporting-lines.overview', ['organization_id' => $this->other->bureau->id]))->assertForbidden();
    $this->actingAs($this->hrUser)->get(route('reporting-lines.lookup.employees', ['organization_id' => $this->other->bureau->id]))->assertForbidden();
    $this->actingAs($this->hrUser)->get(route('reporting-lines.lookup.employees', ['organization_id' => $this->f->bureau->id, 'q' => 'Ali']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->alice->id)->assertJsonMissingPath('data.0.phone');
});

it('shows an employee line to the employee, their supervisors and HR in scope, and to nobody else', function (): void {
    $url = route('reporting-lines.employees.show', $this->alice->id);

    $this->actingAs($this->aliceUser)->get($url)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ReportingLines/Employee')
            ->where('supervisor.supervisor_employee_id', $this->leader->id)
            ->where('supervisor.basis', 'unit_head')
            ->where('can.view_team', false)
            ->where('reports', null));

    // The direct supervisor sees Alice and her team page; Alice has no reports.
    $this->actingAs($this->leaderUser)->get($url)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.view_team', false));
    // The director sees Alice too (indirect), the officer does not, nor does another organization's HR.
    $directorUser = $this->f->user($this->director, ReportingLineRoles::SUPERVISOR_PERMISSIONS, ReportingLineRoles::SUPERVISOR_ROLE);
    $this->actingAs($directorUser)->get($url)->assertOk();
    $this->actingAs($this->officerUser)->get($url)->assertForbidden();
    $this->actingAs($this->strangerHr)->get($url)->assertForbidden();
    $this->actingAs($this->hrUser)->get(route('reporting-lines.employees.show', $this->stranger->id))->assertForbidden();

    // HR in scope sees the line, the reports and the chain.
    $this->actingAs($this->hrUser)->get(route('reporting-lines.employees.show', $this->director->id))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.view_team', true)
            ->has('reports.data', 2)
            ->where('indirect.total', 1)
            ->has('chain'));
});

it('serves My Supervisor to every employee and My Team only to supervisors who hold the permission', function (): void {
    $this->actingAs($this->aliceUser)->get(route('employee.reporting-line'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Employee/ReportingLine')->where('supervisor.supervisor_employee_id', $this->leader->id)->where('can.view_team', false));

    // The permission alone shows nothing of anyone else; the relationship alone opens no page.
    $this->actingAs($this->aliceUser)->get(route('employee.my-team'))->assertForbidden();
    $directorWithoutRole = $this->f->user($this->director);
    $this->actingAs($directorWithoutRole)->get(route('employee.my-team'))->assertForbidden();

    $this->actingAs($this->leaderUser)->get(route('employee.my-team'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Employee/MyTeam')
            ->has('direct.data', 1)
            ->where('direct.data.0.id', $this->alice->id)
            ->where('direct.meta.total', 1)
            ->where('indirect_total', 0)
            ->where('can.delegate', true));

    // Pagination of a bigger team.
    $seat = $this->f->member;
    for ($i = 0; $i < 30; $i++) {
        $this->f->person("Member {$i}", $seat);
    }
    app(ReportingGraphRepository::class)->forget();
    $this->actingAs($this->leaderUser)->get(route('employee.my-team', ['page' => 2]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('direct.meta.total', 31)->where('direct.meta.last_page', 2)->has('direct.data', 6));
});

it('lets HR record and revoke acting appointments in scope, and refuses them elsewhere', function (): void {
    $payload = ['position_id' => $this->f->teamLeader->id, 'employee_id' => $this->officer->id, 'starts_on' => today()->toDateString(), 'ends_on' => today()->addDays(5)->toDateString(), 'reason' => 'Leave cover'];

    $this->actingAs($this->leaderUser)->post(route('reporting-lines.acting.store'), $payload)->assertForbidden();
    $this->actingAs($this->strangerHr)->post(route('reporting-lines.acting.store'), $payload)->assertForbidden();

    $this->actingAs($this->hrUser)->post(route('reporting-lines.acting.store'), $payload)->assertSessionHasNoErrors()->assertRedirect();
    $appointment = ActingAppointment::query()->firstOrFail();
    expect($appointment->employee_id)->toBe($this->officer->id)->and($appointment->authorized_by)->toBe($this->hrUser->id);

    // While it runs, Alice's supervisor is the officer on every page.
    $this->actingAs($this->aliceUser)->get(route('employee.reporting-line'))
        ->assertInertia(fn (Assert $page) => $page->where('supervisor.supervisor_employee_id', $this->officer->id)->where('supervisor.occupant_basis', 'acting'));

    $this->actingAs($this->hrUser)->get(route('reporting-lines.acting.index', ['organization_id' => $this->f->bureau->id]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ReportingLines/ActingAppointments')->has('appointments', 1)->where('appointments.0.in_force', true));

    $this->actingAs($this->strangerHr)->post(route('reporting-lines.acting.revoke', $appointment), ['reason' => 'nope'])->assertForbidden();
    $this->actingAs($this->hrUser)->post(route('reporting-lines.acting.revoke', $appointment), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->hrUser)->post(route('reporting-lines.acting.revoke', $appointment), ['reason' => 'Leader is back'])->assertSessionHasNoErrors();
    expect($appointment->fresh()->status->value)->toBe('revoked');
    $this->actingAs($this->aliceUser)->get(route('employee.reporting-line'))
        ->assertInertia(fn (Assert $page) => $page->where('supervisor.supervisor_employee_id', $this->leader->id));
});

it('lets a supervisor delegate their own actions from My Team and HR delegate for others, never the delegate themself', function (): void {
    $payload = ['delegate_employee_id' => $this->officer->id, 'actions' => [SupervisorActions::FIELD_WORK_DECIDE], 'starts_on' => today()->toDateString(), 'ends_on' => null, 'reason' => 'Training'];

    // Alice supervises nobody, so she may not delegate; the officer may not create a delegation to himself.
    $this->actingAs($this->aliceUser)->post(route('employee.my-team.delegations.store'), $payload)->assertForbidden();
    $this->actingAs($this->officerUser)->post(route('reporting-lines.delegations.store'), [...$payload, 'delegator_employee_id' => $this->leader->id])->assertForbidden();

    $this->actingAs($this->leaderUser)->post(route('employee.my-team.delegations.store'), [...$payload, 'delegator_employee_id' => $this->director->id])
        ->assertSessionHasNoErrors()->assertRedirect(route('employee.my-team'));
    $delegation = SupervisorDelegation::query()->firstOrFail();
    // The portal ignores a smuggled delegator: it is always the signed-in employee.
    expect($delegation->delegator_employee_id)->toBe($this->leader->id)->and($delegation->delegate_employee_id)->toBe($this->officer->id);

    $this->actingAs($this->leaderUser)->get(route('employee.my-team'))
        ->assertInertia(fn (Assert $page) => $page->has('delegations', 1)->where('delegations.0.in_force', true));
    $this->actingAs($this->officerUser)->get(route('employee.reporting-line'))->assertOk();

    // HR records one for the director, in scope only; the stranger HR cannot.
    $forDirector = [...$payload, 'delegator_employee_id' => $this->director->id, 'delegate_employee_id' => $this->leader->id];
    $this->actingAs($this->strangerHr)->post(route('reporting-lines.delegations.store'), $forDirector)->assertForbidden();
    $this->actingAs($this->hrUser)->post(route('reporting-lines.delegations.store'), $forDirector)->assertSessionHasNoErrors();
    expect(SupervisorDelegation::query()->count())->toBe(2);

    // Revocation: the delegator from My Team, HR from the administration page; the delegate never.
    $this->actingAs($this->officerUser)->post(route('employee.my-team.delegations.revoke', $delegation), ['reason' => 'I decide'])->assertForbidden();
    $this->actingAs($this->leaderUser)->post(route('employee.my-team.delegations.revoke', $delegation), ['reason' => 'Done'])->assertSessionHasNoErrors();
    expect($delegation->fresh()->status->value)->toBe('revoked');
    $this->actingAs($this->hrUser)->get(route('reporting-lines.delegations.index', ['organization_id' => $this->f->bureau->id, 'status' => 'active']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ReportingLines/Delegations')->has('delegations', 1));
});

it('adds the reporting line to the employee record for readers and keeps HR, admin and self-service pages working', function (): void {
    $this->actingAs($this->hrUser)->get(route('employees.show', $this->alice->id))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Employees/Show')->where('reportingLine.supervisor_employee_id', $this->leader->id));

    // The employee's own portal and employment pages are untouched by the new permissions.
    $this->actingAs($this->aliceUser)->get(route('employee.portal'))->assertOk();
    $this->actingAs($this->aliceUser)->get(route('employee.employment'))->assertOk();
});
