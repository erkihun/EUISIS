<?php

declare(strict_types=1);

use App\Enums\CommitteeType;
use App\Enums\Grievance\GrievanceCommitteeSelectionSource as Source;
use App\Models\Employee;
use App\Models\GrievanceCommittee;
use App\Models\GrievanceCommitteeMember;
use App\Services\Grievances\GrievanceCommitteeService;
use App\Support\Grievances\GrievanceRoles;
use Illuminate\Validation\ValidationException;
use Tests\Support\GrievanceScenario;

/** A user who may approve committees in the woreda (and is not the creator). */
function committeeApprover(GrievanceScenario $s): \App\Models\User
{
    $approver = GrievanceScenario::user(
        GrievanceScenario::employee($s->woreda, $s->woredaUnit),
        GrievanceRoles::ADMINISTRATOR_PERMISSIONS,
    );
    GrievanceScenario::scope($approver, $s->woreda);

    return $approver;
}

/**
 * Seat one member on $committee. $gender only matters for elected members
 * (the "at least one woman" rule).
 */
function seat(GrievanceCommittee $committee, GrievanceScenario $s, string $role, string $source, ?string $gender = null): void
{
    $employee = GrievanceScenario::employee($s->woreda, $s->woredaUnit);
    if ($gender !== null) {
        $employee->forceFill(['gender' => $gender])->save();
    }
    GrievanceCommitteeMember::query()->create([
        'committee_id' => $committee->id,
        'employee_id' => $employee->id,
        'role' => $role,
        'selection_source' => $source,
        'effective_from' => now()->subMonth()->toDateString(),
        'status' => 'active',
        'term_number' => 1,
    ]);
}

function pendingGrievanceCommittee(GrievanceScenario $s): GrievanceCommittee
{
    return GrievanceCommittee::query()->create([
        'organization_id' => $s->woreda->id,
        'committee_type' => CommitteeType::Grievance->value,
        'name_en' => 'Composition test committee',
        'status' => 'pending_approval',
        'created_by' => $s->intake->id,
        'effective_from' => now()->subMonth()->toDateString(),
    ]);
}

function seatStatutoryFive(GrievanceCommittee $committee, GrievanceScenario $s): void
{
    seat($committee, $s, 'chairperson', Source::AppointedByHead->value);
    seat($committee, $s, 'writer', Source::HrRepresentative->value);
    seat($committee, $s, 'member', Source::ElectedByEmployees->value, 'female');
    seat($committee, $s, 'member', Source::ElectedByEmployees->value, 'male');
    seat($committee, $s, 'member', Source::ElectedByEmployees->value, 'male');
}

it('refuses to approve a grievance committee without exactly five members', function () {
    $s = GrievanceScenario::build(withRoutes: false, withSla: false);
    $committee = pendingGrievanceCommittee($s);
    // Only four members.
    seat($committee, $s, 'chairperson', Source::AppointedByHead->value);
    seat($committee, $s, 'writer', Source::HrRepresentative->value);
    seat($committee, $s, 'member', Source::ElectedByEmployees->value, 'female');
    seat($committee, $s, 'member', Source::ElectedByEmployees->value, 'male');

    app(GrievanceCommitteeService::class)->approve($committee->refresh(), committeeApprover($s));
})->throws(ValidationException::class);

it('refuses to approve when the chair is not appointed by the head', function () {
    $s = GrievanceScenario::build(withRoutes: false, withSla: false);
    $committee = pendingGrievanceCommittee($s);
    seat($committee, $s, 'chairperson', Source::ElectedByEmployees->value); // wrong source
    seat($committee, $s, 'writer', Source::HrRepresentative->value);
    seat($committee, $s, 'member', Source::ElectedByEmployees->value, 'female');
    seat($committee, $s, 'member', Source::ElectedByEmployees->value, 'male');
    seat($committee, $s, 'member', Source::ElectedByEmployees->value, 'male');

    app(GrievanceCommitteeService::class)->approve($committee->refresh(), committeeApprover($s));
})->throws(ValidationException::class);

it('approves a statutorily complete grievance committee', function () {
    $s = GrievanceScenario::build(withRoutes: false, withSla: false);
    $committee = pendingGrievanceCommittee($s);
    seatStatutoryFive($committee, $s);

    $approved = app(GrievanceCommitteeService::class)->approve($committee->refresh(), committeeApprover($s));

    expect($approved->status)->toBe('active');
});
