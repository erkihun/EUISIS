<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Modules\Grievance\Application\Services\GrievanceCommitteeService;
use Modules\Grievance\Contracts\Data\CommitteeType;
use Modules\Grievance\Contracts\Data\GrievanceCommitteeSelectionSource as Source;
use Modules\Grievance\Contracts\Data\GrievanceRoles;
use Modules\Grievance\Domain\Models\GrievanceCommittee;
use Modules\Grievance\Domain\Models\GrievanceCommitteeMember;
use Tests\Support\GrievanceScenario;

/** A user who may approve committees in the woreda (and is not the creator). */
function committeeApprover(GrievanceScenario $s): User
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
function seatGrievanceMember(GrievanceCommittee $committee, GrievanceScenario $s, string $role, string $source, ?string $gender = null, bool $withSignature = true): void
{
    $employee = GrievanceScenario::employee($s->woreda, $s->woredaUnit);
    if ($gender !== null) {
        $employee->forceFill(['gender' => $gender])->save();
    }
    if (! $withSignature) {
        $employee->forceFill(['signature_path' => null])->save();
    }
    GrievanceCommitteeMember::query()->create([
        'committee_id' => $committee->id,
        'employee_id' => $employee->id,
        'role' => $role,
        'selection_source' => $source,
        'effective_from' => now()->subMonth()->toDateString(),
        'status' => 'active',
        'term_number' => 1,
        // A member is registered together with their signature image.
        'signature_path' => $withSignature ? 'employee-signatures/test-'.$employee->id.'.png' : null,
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
    seatGrievanceMember($committee, $s, 'chairperson', Source::AppointedByHead->value);
    seatGrievanceMember($committee, $s, 'writer', Source::HrRepresentative->value);
    seatGrievanceMember($committee, $s, 'member', Source::ElectedByEmployees->value, 'female');
    seatGrievanceMember($committee, $s, 'member', Source::ElectedByEmployees->value, 'male');
    seatGrievanceMember($committee, $s, 'member', Source::ElectedByEmployees->value, 'male');
}

it('refuses to approve a grievance committee without exactly five members', function () {
    $s = GrievanceScenario::build(withRoutes: false, withSla: false);
    $committee = pendingGrievanceCommittee($s);
    // Only four members.
    seatGrievanceMember($committee, $s, 'chairperson', Source::AppointedByHead->value);
    seatGrievanceMember($committee, $s, 'writer', Source::HrRepresentative->value);
    seatGrievanceMember($committee, $s, 'member', Source::ElectedByEmployees->value, 'female');
    seatGrievanceMember($committee, $s, 'member', Source::ElectedByEmployees->value, 'male');

    app(GrievanceCommitteeService::class)->approve($committee->refresh(), committeeApprover($s));
})->throws(ValidationException::class);

it('refuses to approve when the chair is not appointed by the head', function () {
    $s = GrievanceScenario::build(withRoutes: false, withSla: false);
    $committee = pendingGrievanceCommittee($s);
    seatGrievanceMember($committee, $s, 'chairperson', Source::ElectedByEmployees->value); // wrong source
    seatGrievanceMember($committee, $s, 'writer', Source::HrRepresentative->value);
    seatGrievanceMember($committee, $s, 'member', Source::ElectedByEmployees->value, 'female');
    seatGrievanceMember($committee, $s, 'member', Source::ElectedByEmployees->value, 'male');
    seatGrievanceMember($committee, $s, 'member', Source::ElectedByEmployees->value, 'male');

    app(GrievanceCommitteeService::class)->approve($committee->refresh(), committeeApprover($s));
})->throws(ValidationException::class);

it('approves a statutorily complete grievance committee', function () {
    $s = GrievanceScenario::build(withRoutes: false, withSla: false);
    $committee = pendingGrievanceCommittee($s);
    seatStatutoryFive($committee, $s);

    $approved = app(GrievanceCommitteeService::class)->approve($committee->refresh(), committeeApprover($s));

    expect($approved->status)->toBe('active');
});

it('refuses approval when no elected member is a woman', function () {
    $s = GrievanceScenario::build(withRoutes: false, withSla: false);
    $committee = pendingGrievanceCommittee($s);
    seatGrievanceMember($committee, $s, 'chairperson', Source::AppointedByHead->value);
    seatGrievanceMember($committee, $s, 'writer', Source::HrRepresentative->value);
    seatGrievanceMember($committee, $s, 'member', Source::ElectedByEmployees->value, 'male');
    seatGrievanceMember($committee, $s, 'member', Source::ElectedByEmployees->value, 'male');
    seatGrievanceMember($committee, $s, 'member', Source::ElectedByEmployees->value, 'male');

    app(GrievanceCommitteeService::class)->approve($committee->refresh(), committeeApprover($s));
})->throws(ValidationException::class);

it('does not treat unknown gender as satisfying the woman requirement', function () {
    $s = GrievanceScenario::build(withRoutes: false, withSla: false);
    $committee = pendingGrievanceCommittee($s);
    seatGrievanceMember($committee, $s, 'chairperson', Source::AppointedByHead->value);
    seatGrievanceMember($committee, $s, 'writer', Source::HrRepresentative->value);
    // All three elected members have no recorded gender.
    seatGrievanceMember($committee, $s, 'member', Source::ElectedByEmployees->value, null);
    seatGrievanceMember($committee, $s, 'member', Source::ElectedByEmployees->value, null);
    seatGrievanceMember($committee, $s, 'member', Source::ElectedByEmployees->value, null);

    app(GrievanceCommitteeService::class)->approve($committee->refresh(), committeeApprover($s));
})->throws(ValidationException::class);

it('refuses approval while a serving member has no signature on file', function () {
    $s = GrievanceScenario::build(withRoutes: false, withSla: false);
    $committee = pendingGrievanceCommittee($s);
    seatGrievanceMember($committee, $s, 'chairperson', Source::AppointedByHead->value);
    seatGrievanceMember($committee, $s, 'writer', Source::HrRepresentative->value);
    seatGrievanceMember($committee, $s, 'member', Source::ElectedByEmployees->value, 'female');
    seatGrievanceMember($committee, $s, 'member', Source::ElectedByEmployees->value, 'male');
    seatGrievanceMember($committee, $s, 'member', Source::ElectedByEmployees->value, 'male', withSignature: false);

    try {
        app(GrievanceCommitteeService::class)->approve($committee->refresh(), committeeApprover($s));
        $this->fail('Approval should have been refused.');
    } catch (ValidationException $e) {
        expect(implode(' ', $e->errors()['committee']))->toContain(__('grievances.errors.committee_signatures_missing'));
    }
});

it('registers a member together with the signature image and serves it to committee viewers', function () {
    Storage::fake('local');
    $s = GrievanceScenario::build(withRoutes: false, withSla: false);
    $committee = pendingGrievanceCommittee($s);
    $admin = committeeApprover($s);
    $employee = GrievanceScenario::employee($s->woreda, $s->woredaUnit);
    $employee->forceFill(['signature_path' => null])->save(); // nothing on file yet
    $payload = ['employee_id' => $employee->id, 'role' => 'member', 'selection_source' => 'elected', 'term_number' => 1, 'effective_from' => now()->toDateString()];

    // Without the signature the member is not registered at all.
    $this->actingAs($admin)->from(route('grievances.committees.show', $committee))
        ->post(route('grievances.committees.members.store', $committee), $payload)
        ->assertRedirect(route('grievances.committees.show', $committee))
        ->assertSessionHasErrors('signature');
    expect(GrievanceCommitteeMember::query()->where('committee_id', $committee->id)->count())->toBe(0);

    $this->actingAs($admin)->post(route('grievances.committees.members.store', $committee), [
        ...$payload, 'signature' => UploadedFile::fake()->image('signature.png', 300, 120),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $member = GrievanceCommitteeMember::query()->where('committee_id', $committee->id)->firstOrFail();
    expect($member->signature_path)->toStartWith('employee-signatures/')
        ->and($member->signature_sha256)->toHaveLength(64)
        ->and($member->signature_recorded_by)->toBe($admin->id)
        ->and($employee->refresh()->signature_path)->toBe($member->signature_path);
    Storage::disk('local')->assertExists($member->signature_path);

    $this->actingAs($admin)->get(route('grievances.committees.members.signature', [$committee, $member]))
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs($admin)->get(route('grievances.committees.show', $committee))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('members.0.signature', true)
        ->where('members.0.signature_url', route('grievances.committees.members.signature', [$committee, $member])));
});
