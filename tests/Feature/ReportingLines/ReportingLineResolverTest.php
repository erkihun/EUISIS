<?php

declare(strict_types=1);

use App\Enums\OccupantBasis;
use App\Enums\OrganizationDesignStatus;
use App\Enums\ReportingBasis;
use App\Enums\ReportingOutcome;
use App\Models\ActingAppointment;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Position;
use App\Models\PositionProfile;
use App\Services\ReportingLines\ReportingGraphRepository;
use App\Services\ReportingLines\ReportingLineResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ReportingHierarchyFixture;

/*
 * The authoritative reporting model (ADR 0025): explicit published profile
 * line, else unit leadership; the person is whoever holds the supervising
 * position on the date, an acting appointment first. Nothing is guessed:
 * every gap, vacancy, ambiguity and loop is its own outcome.
 */

beforeEach(function (): void {
    $this->f = ReportingHierarchyFixture::build();
    $this->resolver = app(ReportingLineResolver::class);
    app(ReportingGraphRepository::class)->forget();

    $this->head = $this->f->person('Bureau Head', $this->f->bureauHead);
    $this->sectorChief = $this->f->person('Sector Chief', $this->f->sectorHead);
    $this->director = $this->f->person('Director', $this->f->director);
    $this->leader = $this->f->person('Leader', $this->f->teamLeader);
    $this->alice = $this->f->person('Alice', $this->f->member);
    $this->officer = $this->f->person('Officer', $this->f->directorateOfficer);
});

function rlProfile(Position $position, ?string $reportsTo, string $status = 'published', string $from = '2026-01-01', ?string $to = null, int $version = 1, ?string $lineage = null): PositionProfile
{
    return PositionProfile::query()->create([
        'position_id' => $position->id, 'organization_id' => $position->organization_id, 'lineage_key' => $lineage ?? (string) Str::uuid(), 'version_no' => $version,
        'reports_to_position_id' => $reportsTo, 'status' => $status, 'effective_from' => $from, 'effective_to' => $to,
    ]);
}

it('resolves an ordinary member to the team leader', function (): void {
    $r = $this->resolver->directSupervisorOf($this->alice);

    expect($r->outcome)->toBe(ReportingOutcome::Resolved)
        ->and($r->basis)->toBe(ReportingBasis::UnitHead)
        ->and($r->supervisorEmployeeUuid)->toBe($this->leader->id)
        ->and($r->supervisorPositionUuid)->toBe($this->f->teamLeader->id)
        ->and($r->occupantBasis)->toBe(OccupantBasis::Substantive)
        ->and($r->toArray()['explanation_text'])->toContain('Team');
});

it('resolves a team leader to the director of the parent unit', function (): void {
    $r = $this->resolver->directSupervisorOf($this->leader);

    expect($r->outcome)->toBe(ReportingOutcome::Resolved)
        ->and($r->basis)->toBe(ReportingBasis::ParentUnitHead)
        ->and($r->supervisorEmployeeUuid)->toBe($this->director->id);
});

it('resolves an employee placed directly under a directorate to its director', function (): void {
    $r = $this->resolver->directSupervisorOf($this->officer);

    expect($r->basis)->toBe(ReportingBasis::UnitHead)
        ->and($r->supervisorEmployeeUuid)->toBe($this->director->id);
});

it('walks the chain up to the bureau head and ends at the top', function (): void {
    $chain = $this->resolver->chainOf($this->alice);
    $people = array_map(fn ($s) => $s->supervisorEmployeeUuid, $chain);

    expect(count($chain))->toBe(5)
        ->and(array_slice($people, 0, 4))->toBe([$this->leader->id, $this->director->id, $this->sectorChief->id, $this->head->id])
        ->and($chain[2]->basis)->toBe(ReportingBasis::ParentUnitHead)
        ->and($chain[3]->basis)->toBe(ReportingBasis::OrganizationHead)
        ->and($chain[4]->outcome)->toBe(ReportingOutcome::TopOfHierarchy)
        ->and($chain[4]->outcome->isLegitimateEnd())->toBeTrue()
        ->and($chain[4]->outcome->isProblem())->toBeFalse();
});

it('resolves organization-level staff with no unit to the organization head', function (): void {
    $advisor = $this->f->person('Advisor', $this->f->advisor);
    $r = $this->resolver->directSupervisorOf($advisor);

    expect($r->basis)->toBe(ReportingBasis::OrganizationHead)
        ->and($r->supervisorEmployeeUuid)->toBe($this->head->id);
});

it('connects the organization head to the head of the parent organization in the published hierarchy', function (): void {
    [$city, $mayorPosition] = $this->f->parentOrganization();
    $mayor = Employee::query()->create(['employee_number' => 'CITY-1', 'first_name' => 'Mayor', 'last_name' => 'Tester', 'full_name' => 'Mayor Tester', 'email' => 'mayor@rl.test', 'status' => 'active']);
    EmployeeAssignment::query()->create(['employee_id' => $mayor->id, 'organization_id' => $city->id, 'position_id' => $mayorPosition->id, 'assignment_status' => 'active', 'effective_from' => '2026-01-01', 'is_current' => true]);
    app(ReportingGraphRepository::class)->forget();

    $r = $this->resolver->directSupervisorOf($this->head);
    expect($r->outcome)->toBe(ReportingOutcome::Resolved)
        ->and($r->basis)->toBe(ReportingBasis::ParentOrganizationHead)
        ->and($r->supervisorEmployeeUuid)->toBe($mayor->id)
        ->and($r->supervisorOrganizationUuid)->toBe($city->id);

    // The mayor's direct reports include the bureau head across the organization boundary.
    $reports = $this->resolver->directReportsQuery($mayor)->pluck('id')->all();
    expect($reports)->toContain($this->head->id);
});

it('reports a missing parent organization head as configuration, not as the top', function (): void {
    $this->f->parentOrganization();
    app(ReportingGraphRepository::class)->forget();

    $r = $this->resolver->directSupervisorOf($this->head);
    expect($r->outcome)->toBe(ReportingOutcome::HeadVacant);
});

it('lets an approved, effective position profile override the inferred line', function (): void {
    rlProfile($this->f->member, $this->f->director->id);
    app(ReportingGraphRepository::class)->forget();

    $r = $this->resolver->directSupervisorOf($this->alice);
    expect($r->basis)->toBe(ReportingBasis::ExplicitProfile)
        ->and($r->supervisorEmployeeUuid)->toBe($this->director->id);
});

it('ignores draft, cancelled, future and expired profiles', function (): void {
    rlProfile($this->f->member, $this->f->director->id, OrganizationDesignStatus::Draft->value);
    rlProfile($this->f->member, $this->f->director->id, OrganizationDesignStatus::Cancelled->value);
    rlProfile($this->f->member, $this->f->director->id, OrganizationDesignStatus::Published->value, now()->addMonth()->toDateString());
    rlProfile($this->f->member, $this->f->director->id, OrganizationDesignStatus::Superseded->value, '2025-01-01', '2025-12-31');
    app(ReportingGraphRepository::class)->forget();

    $r = $this->resolver->directSupervisorOf($this->alice);
    expect($r->basis)->toBe(ReportingBasis::UnitHead)
        ->and($r->supervisorEmployeeUuid)->toBe($this->leader->id);

    // The future version becomes the line on its effective date.
    $future = $this->resolver->directSupervisorOf($this->alice, null, now()->addMonth()->addDay());
    expect($future->basis)->toBe(ReportingBasis::ExplicitProfile);
});

it('reports a vacant head position instead of escalating', function (): void {
    $this->leader->currentAssignment->update(['effective_to' => now()->subDay()->toDateString(), 'is_current' => false, 'assignment_status' => 'closed']);

    $r = $this->resolver->directSupervisorOf($this->alice);
    expect($r->outcome)->toBe(ReportingOutcome::HeadVacant)
        ->and($r->supervisorPositionUuid)->toBe($this->f->teamLeader->id)
        ->and($r->supervisorEmployeeUuid)->toBeNull()
        ->and($this->resolver->supervisesEmployee($this->director->id, $this->alice))->toBeFalse();

    // The chain stops at the vacancy; the director is never reached.
    $chain = $this->resolver->chainOf($this->alice);
    expect(count($chain))->toBe(1)->and($chain[0]->outcome)->toBe(ReportingOutcome::HeadVacant);
});

it('does not name an inactive employee as supervisor', function (): void {
    $this->leader->update(['status' => 'suspended']);

    $r = $this->resolver->directSupervisorOf($this->alice);
    expect($r->outcome)->toBe(ReportingOutcome::HeadVacant)
        ->and($r->explanation)->toBe('reporting-lines.explanations.holder_inactive');
});

it('honours assignment dates: future holders count only from their start', function (): void {
    $this->leader->currentAssignment->update(['effective_from' => now()->addWeek()->toDateString()]);

    expect($this->resolver->directSupervisorOf($this->alice)->outcome)->toBe(ReportingOutcome::HeadVacant)
        ->and($this->resolver->directSupervisorOf($this->alice, null, now()->addWeek())->supervisorEmployeeUuid)->toBe($this->leader->id);
});

it('flags two holders of the supervising position as ambiguous', function (): void {
    $this->f->person('Second Leader', $this->f->teamLeader);

    $r = $this->resolver->directSupervisorOf($this->alice);
    expect($r->outcome)->toBe(ReportingOutcome::AmbiguousOccupants)->and($r->supervisorEmployeeUuid)->toBeNull();
});

it('lets an acting appointment outrank the substantive holder while it runs', function (): void {
    $acting = ActingAppointment::query()->create([
        'organization_id' => $this->f->bureau->id, 'position_id' => $this->f->teamLeader->id, 'employee_id' => $this->officer->id,
        'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDay()->toDateString(), 'status' => 'active',
    ]);

    $r = $this->resolver->directSupervisorOf($this->alice);
    expect($r->supervisorEmployeeUuid)->toBe($this->officer->id)
        ->and($r->occupantBasis)->toBe(OccupantBasis::Acting)
        ->and($r->actingAppointmentUuid)->toBe($acting->id)
        ->and($r->basis)->toBe(ReportingBasis::UnitHead);

    // Expired: back to the substantive holder. Revoked: the same.
    expect($this->resolver->directSupervisorOf($this->alice, null, now()->addDays(3))->supervisorEmployeeUuid)->toBe($this->leader->id);
    $acting->update(['status' => 'revoked', 'revoked_at' => now()]);
    expect($this->resolver->directSupervisorOf($this->alice)->supervisorEmployeeUuid)->toBe($this->leader->id);

    // The acting person's own supervisor is still resolved from their own position, not from the one they fill.
    expect($this->resolver->directSupervisorOf($this->officer)->supervisorEmployeeUuid)->toBe($this->director->id);
});

it('refuses to make an employee their own supervisor', function (): void {
    // Alice also holds the leader seat through an older, non-current row that still covers today.
    $this->leader->currentAssignment->update(['effective_to' => now()->subDay()->toDateString(), 'is_current' => false]);
    $this->f->place($this->alice, $this->f->teamLeader, '2025-06-01', null, ['is_current' => false]);

    $r = $this->resolver->directSupervisorOf($this->alice);
    expect($r->outcome)->toBe(ReportingOutcome::SelfReporting)
        ->and($this->resolver->supervises($this->alice->id, $this->alice->id))->toBeFalse();
});

it('detects a loop through mixed explicit and inferred lines', function (): void {
    // Director's published profile reports to the Team Member position: member → leader → director → member.
    rlProfile($this->f->director, $this->f->member->id);
    app(ReportingGraphRepository::class)->forget();

    $chain = $this->resolver->chainOf($this->alice);
    $last = $chain[array_key_last($chain)];
    expect($last->outcome)->toBe(ReportingOutcome::Cycle)
        ->and(count($chain))->toBeLessThanOrEqual(4);
});

it('reports a position whose profile reports to itself', function (): void {
    rlProfile($this->f->member, $this->f->member->id);
    app(ReportingGraphRepository::class)->forget();

    expect($this->resolver->directSupervisorOf($this->alice)->outcome)->toBe(ReportingOutcome::SelfReporting);
});

it('distinguishes no assignment, pending transfer and several assignments', function (): void {
    $nobody = Employee::query()->create(['employee_number' => 'RL-NONE', 'first_name' => 'No', 'last_name' => 'Body', 'full_name' => 'No Body', 'email' => 'nobody@rl.test', 'status' => 'active']);
    expect($this->resolver->directSupervisorOf($nobody)->outcome)->toBe(ReportingOutcome::NoAssignment);

    $this->f->place($nobody, $this->f->member, '2026-01-01', null, ['assignment_status' => 'pending_transfer']);
    expect($this->resolver->directSupervisorOf($nobody)->outcome)->toBe(ReportingOutcome::NoAssignment);

    // The database keeps one current row per employee; a second row covering today that is
    // not current does not compete with the current one.
    $this->f->place($this->alice, $this->f->directorateOfficer, '2026-02-01', null, ['is_current' => false]);
    expect($this->resolver->directSupervisorOf($this->alice)->supervisorEmployeeUuid)->toBe($this->leader->id);

    // Two closed rows overlapping on a past date, neither current: nobody is picked for that date.
    $this->f->place($this->alice, $this->f->member, '2025-01-01', '2025-12-31', ['is_current' => false, 'assignment_status' => 'closed']);
    $this->f->place($this->alice, $this->f->directorateOfficer, '2025-06-01', '2025-12-31', ['is_current' => false, 'assignment_status' => 'closed']);
    expect($this->resolver->directSupervisorOf($this->alice, null, '2025-09-01')->outcome)->toBe(ReportingOutcome::AmbiguousAssignment);
});

it('reconstructs a past date from the assignment history after a transfer', function (): void {
    $old = $this->alice->currentAssignment;
    $old->update(['effective_to' => '2026-05-31', 'is_current' => false, 'assignment_status' => 'closed']);
    $this->f->place($this->alice, $this->f->position('RL-P-FIN-OFF', 'Finance Officer', $this->f->finance), '2026-06-01');
    $financeDirector = $this->f->person('Finance Director', $this->f->financeDirector);
    app(ReportingGraphRepository::class)->forget();

    expect($this->resolver->directSupervisorOf($this->alice, null, '2026-03-01')->supervisorEmployeeUuid)->toBe($this->leader->id)
        ->and($this->resolver->directSupervisorOf($this->alice, null, '2026-07-01')->supervisorEmployeeUuid)->toBe($financeDirector->id);
});

it('lists direct and indirect reports and answers supervises for both', function (): void {
    $direct = $this->resolver->directReportsQuery($this->director)->pluck('id')->all();
    $all = $this->resolver->allReportsQuery($this->director);

    expect($direct)->toEqualCanonicalizing([$this->leader->id, $this->officer->id])
        ->and($all['query']->pluck('id')->all())->toEqualCanonicalizing([$this->leader->id, $this->officer->id, $this->alice->id])
        ->and($all['truncated'])->toBeFalse()
        ->and($this->resolver->supervises($this->director->id, $this->leader->id, null, true))->toBeTrue()
        ->and($this->resolver->supervises($this->director->id, $this->alice->id, null, true))->toBeFalse()
        ->and($this->resolver->supervises($this->director->id, $this->alice->id))->toBeTrue()
        ->and($this->resolver->supervises($this->leader->id, $this->director->id))->toBeFalse()
        ->and($this->resolver->supervises($this->director->id, $this->director->id))->toBeFalse()
        ->and($this->resolver->directReportIds($this->alice->id))->toBe([]);
});

it('reports an inactive unit and a unit without a head as problems', function (): void {
    $orphan = $this->f->unit('RL-ORPHAN', 'Orphan', $this->f->sector);
    $clerk = $this->f->person('Clerk', $this->f->position('RL-P-CLERK', 'Clerk', $orphan));
    expect($this->resolver->directSupervisorOf($clerk)->outcome)->toBe(ReportingOutcome::UnitHeadNotConfigured);

    $this->f->team->update(['status' => 'inactive']);
    app(ReportingGraphRepository::class)->forget();
    expect($this->resolver->directSupervisorOf($this->alice)->outcome)->toBe(ReportingOutcome::UnitInactive);
});

it('reports a missing organization head as configuration, and an ended head position as inactive', function (): void {
    $this->f->bureauHead->delete();
    app(ReportingGraphRepository::class)->forget();
    expect($this->resolver->directSupervisorOf($this->sectorChief)->outcome)->toBe(ReportingOutcome::OrganizationHeadNotConfigured);

    $this->f->bureauHead->restore();
    $this->f->bureauHead->update(['effective_to' => now()->subDay()->toDateString()]);
    app(ReportingGraphRepository::class)->forget();
    expect($this->resolver->directSupervisorOf($this->sectorChief)->outcome)->toBe(ReportingOutcome::SupervisorPositionInactive);
});

it('resolves a large flat hierarchy with a bounded number of queries', function (): void {
    $big = $this->f->unit('RL-BIG', 'Big Directorate', $this->f->sector);
    $bigHead = $this->f->position('RL-P-BIGH', 'Big Director', $big, true);
    $bigDirector = $this->f->person('Big Director', $bigHead);
    $seat = $this->f->position('RL-P-BIGM', 'Big Member', $big, false);
    for ($i = 0; $i < 60; $i++) {
        $this->f->person("Big {$i}", $seat);
    }
    app(ReportingGraphRepository::class)->forget();

    DB::enableQueryLog();
    $page = $this->resolver->directReportsQuery($bigDirector)->paginate(25);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($page->total())->toBe(60)
        ->and($page->count())->toBe(25)
        ->and($count)->toBeLessThan(12);
});
