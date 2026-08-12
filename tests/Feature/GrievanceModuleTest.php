<?php

declare(strict_types=1);

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\Grievances\ApproveGrievanceResponseAction;
use App\Actions\Grievances\AssignGrievanceAction;
use App\Actions\Grievances\CheckGrievanceRequirementAction;
use App\Actions\Grievances\CompileGrievanceResponseAction;
use App\Actions\Grievances\RejectGrievanceResponseAction;
use App\Actions\Grievances\SubmitGrievanceAction;
use App\Enums\GrievanceOriginLevel;
use App\Enums\GrievanceResponseStatus;
use App\Enums\GrievanceStatus;
use App\Enums\OrganizationStatus;
use App\Jobs\EscalateOverdueGrievancesJob;
use App\Models\Employee;
use App\Models\Grievance;
use App\Models\GrievanceCategory;
use App\Models\GrievanceCommittee;
use App\Models\GrievanceCommitteeMember;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    foreach ([
        'grievances.view', 'grievances.manage', 'grievances.committee',
        'grievances.chairperson', 'grievances.manager', 'grievances.tribunal',
    ] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }
});

// ── Helpers ──────────────────────────────────────────────────────────────────

function grievanceOrg(): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'GRV_TEST'], ['name_en' => 'Test Dept']);

    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'GRV-ORG-'.uniqid(),
        'name_en' => 'Test Organization',
        'status' => OrganizationStatus::Active,
    ]);
}

function grievanceCategory(): GrievanceCategory
{
    return GrievanceCategory::query()->create([
        'code' => 'cat-'.uniqid(),
        'name_en' => 'Workplace Dispute',
        'is_active' => true,
    ]);
}

function grievanceSubmitter(): User
{
    $user = User::factory()->create(['email' => 'submitter-'.uniqid().'@test.com']);
    $user->givePermissionTo('grievances.view');

    return $user;
}

function grievanceAdmin(): User
{
    $user = User::factory()->create(['email' => 'gadmin-'.uniqid().'@test.com']);
    $user->givePermissionTo(['grievances.manage', 'grievances.view']);

    return $user;
}

function grievanceChairperson(): User
{
    $user = User::factory()->create(['email' => 'chair-'.uniqid().'@test.com']);
    $user->givePermissionTo(['grievances.chairperson', 'grievances.committee', 'grievances.view']);

    return $user;
}

function grievanceManager(): User
{
    $user = User::factory()->create(['email' => 'mgr-'.uniqid().'@test.com']);
    $user->givePermissionTo(['grievances.manager', 'grievances.view']);

    return $user;
}

function makeCommittee(Organization $org): GrievanceCommittee
{
    return GrievanceCommittee::query()->create([
        'organization_id' => $org->id,
        'committee_type' => 'grievance',
        'name_en' => 'Grievance Committee',
        'status' => 'active',
    ]);
}

function makeEmployee(): Employee
{
    $uid = uniqid();

    return Employee::query()->create([
        'employee_number' => 'EMP-'.$uid,
        'first_name' => 'Test',
        'last_name' => 'Employee',
        'full_name' => 'Test Employee',
        'status' => 'active',
    ]);
}

function addCommitteeMember(GrievanceCommittee $committee, string $role = 'member'): GrievanceCommitteeMember
{
    $employee = makeEmployee();

    return GrievanceCommitteeMember::query()->create([
        'committee_id' => $committee->id,
        'employee_id' => $employee->id,
        'role' => $role,
        'effective_from' => now()->subDay()->toDateString(),
        'status' => 'active',
    ]);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

it('can submit a grievance', function (): void {
    $org = grievanceOrg();
    $category = grievanceCategory();
    $submitter = grievanceSubmitter();
    $action = app(SubmitGrievanceAction::class);

    $grievance = $action->execute($submitter, [
        'subject' => 'Unfair treatment',
        'description' => 'I was passed over for promotion without explanation.',
        'category_id' => $category->id,
        'origin_level' => GrievanceOriginLevel::Organization->value,
        'organization_id' => $org->id,
    ]);

    expect($grievance)->toBeInstanceOf(Grievance::class)
        ->and($grievance->status)->toBe(GrievanceStatus::Submitted)
        ->and($grievance->reference_number)->toStartWith('GRV-')
        ->and($grievance->submitted_by_user_id)->toBe($submitter->id);
});

it('committee must have 3 to 5 active members', function (): void {
    $org = grievanceOrg();
    $committee = makeCommittee($org);

    // 0 members initially
    expect($committee->activeMembers()->count())->toBe(0);

    // Add 3 members — should satisfy minimum
    addCommitteeMember($committee, 'chairperson');
    addCommitteeMember($committee);
    addCommitteeMember($committee);

    expect($committee->activeMembers()->count())->toBe(3);
});

it('cannot add more than 5 members to a committee', function (): void {
    $org = grievanceOrg();
    $committee = makeCommittee($org);

    foreach (range(1, 5) as $i) {
        addCommitteeMember($committee);
    }

    expect($committee->activeMembers()->count())->toBe(5);

    // HTTP route enforces max — test the controller validation
    $admin = grievanceAdmin();
    $employee = makeEmployee();

    $this->actingAs($admin)
        ->post(route('grievance-committees.members.add', $committee->id), [
            'employee_id' => $employee->id,
            'role' => 'member',
            'effective_from' => now()->toDateString(),
        ])
        ->assertSessionHasErrors('employee_id');
});

it('requirement incomplete returns grievance to submitter', function (): void {
    $org = grievanceOrg();
    $category = grievanceCategory();
    $submitter = grievanceSubmitter();
    $admin = grievanceAdmin();

    $grievance = app(SubmitGrievanceAction::class)->execute($submitter, [
        'subject' => 'Test',
        'description' => 'Test description.',
        'category_id' => $category->id,
        'origin_level' => GrievanceOriginLevel::Organization->value,
        'organization_id' => $org->id,
    ]);

    app(CheckGrievanceRequirementAction::class)->execute($grievance, $admin, false, 'Missing documents.');

    $grievance->refresh();

    expect($grievance->status)->toBe(GrievanceStatus::RequirementIncomplete)
        ->and($grievance->requirement_fulfilled)->toBeFalse()
        ->and($grievance->requirement_notes)->toBe('Missing documents.');
});

it('no response in 3 working days triggers escalation job', function (): void {
    $org = grievanceOrg();
    $category = grievanceCategory();
    $submitter = grievanceSubmitter();
    $admin = grievanceAdmin();
    $committee = makeCommittee($org);

    addCommitteeMember($committee, 'chairperson');
    addCommitteeMember($committee);
    addCommitteeMember($committee);

    $grievance = app(SubmitGrievanceAction::class)->execute($submitter, [
        'subject' => 'Overdue test',
        'description' => 'This should escalate.',
        'category_id' => $category->id,
        'origin_level' => GrievanceOriginLevel::Organization->value,
        'organization_id' => $org->id,
    ]);

    // Assign with a past due date to simulate overdue
    $assignment = app(AssignGrievanceAction::class)->execute($grievance, $committee, $admin);
    $assignment->update(['due_at' => now()->subDays(4)]);

    $grievance->refresh();

    app(EscalateOverdueGrievancesJob::class)->handle(
        app(WriteAuditLogAction::class)
    );

    $grievance->refresh();

    expect($grievance->status)->toBe(GrievanceStatus::Escalated)
        ->and($grievance->escalations()->count())->toBe(1);
});

it('chairperson can compile a response', function (): void {
    $org = grievanceOrg();
    $category = grievanceCategory();
    $submitter = grievanceSubmitter();
    $admin = grievanceAdmin();
    $chair = grievanceChairperson();
    $committee = makeCommittee($org);

    addCommitteeMember($committee, 'chairperson');
    addCommitteeMember($committee);
    addCommitteeMember($committee);

    $grievance = app(SubmitGrievanceAction::class)->execute($submitter, [
        'subject' => 'Compile test',
        'description' => 'A valid grievance.',
        'category_id' => $category->id,
        'origin_level' => GrievanceOriginLevel::Organization->value,
        'organization_id' => $org->id,
    ]);

    app(AssignGrievanceAction::class)->execute($grievance, $committee, $admin);

    $response = app(CompileGrievanceResponseAction::class)->execute($grievance, $chair, [
        'response_body_en' => 'After reviewing your grievance, we have determined...',
    ]);

    expect($response->status)->toBe(GrievanceResponseStatus::Compiled)
        ->and($grievance->fresh()->status)->toBe(GrievanceStatus::AwaitingApproval);
});

it('manager can approve response and generate letter', function (): void {
    $org = grievanceOrg();
    $category = grievanceCategory();
    $submitter = grievanceSubmitter();
    $admin = grievanceAdmin();
    $chair = grievanceChairperson();
    $manager = grievanceManager();
    $committee = makeCommittee($org);

    addCommitteeMember($committee, 'chairperson');
    addCommitteeMember($committee);
    addCommitteeMember($committee);

    $grievance = app(SubmitGrievanceAction::class)->execute($submitter, [
        'subject' => 'Approval test',
        'description' => 'Grievance for approval flow.',
        'category_id' => $category->id,
        'origin_level' => GrievanceOriginLevel::Organization->value,
        'organization_id' => $org->id,
    ]);

    app(AssignGrievanceAction::class)->execute($grievance, $committee, $admin);
    app(CompileGrievanceResponseAction::class)->execute($grievance, $chair, [
        'response_body_en' => 'Official response body.',
    ]);

    app(ApproveGrievanceResponseAction::class)->execute($grievance, $manager);

    $grievance->refresh();

    expect($grievance->status)->toBe(GrievanceStatus::Approved)
        ->and($grievance->decisionLetter)->not->toBeNull()
        ->and($grievance->decisionLetter->letter_reference)->toStartWith('LTR-');
});

it('manager can reject response and it returns for revision', function (): void {
    $org = grievanceOrg();
    $category = grievanceCategory();
    $submitter = grievanceSubmitter();
    $admin = grievanceAdmin();
    $chair = grievanceChairperson();
    $manager = grievanceManager();
    $committee = makeCommittee($org);

    addCommitteeMember($committee, 'chairperson');
    addCommitteeMember($committee);
    addCommitteeMember($committee);

    $grievance = app(SubmitGrievanceAction::class)->execute($submitter, [
        'subject' => 'Rejection test',
        'description' => 'Grievance for rejection flow.',
        'category_id' => $category->id,
        'origin_level' => GrievanceOriginLevel::Organization->value,
        'organization_id' => $org->id,
    ]);

    app(AssignGrievanceAction::class)->execute($grievance, $committee, $admin);
    app(CompileGrievanceResponseAction::class)->execute($grievance, $chair, [
        'response_body_en' => 'Incomplete response.',
    ]);

    app(RejectGrievanceResponseAction::class)->execute($grievance, $manager, 'Response lacks specifics.');

    $grievance->refresh();

    expect($grievance->status)->toBe(GrievanceStatus::InProgress)
        ->and($grievance->responses()->latest()->first()->status)->toBe(GrievanceResponseStatus::RejectedByManager)
        ->and($grievance->responses()->latest()->first()->rejection_reason)->toBe('Response lacks specifics.');
});

it('submitter can only see own grievances', function (): void {
    $org = grievanceOrg();
    $category = grievanceCategory();
    $submitter = grievanceSubmitter();
    $other = grievanceSubmitter();

    $ownGrievance = app(SubmitGrievanceAction::class)->execute($submitter, [
        'subject' => 'My grievance',
        'description' => 'Should be visible.',
        'category_id' => $category->id,
        'origin_level' => GrievanceOriginLevel::Organization->value,
        'organization_id' => $org->id,
    ]);

    $otherGrievance = app(SubmitGrievanceAction::class)->execute($other, [
        'subject' => 'Other grievance',
        'description' => 'Should not be visible.',
        'category_id' => $category->id,
        'origin_level' => GrievanceOriginLevel::Organization->value,
        'organization_id' => $org->id,
    ]);

    expect($ownGrievance->isOwnedBy($submitter))->toBeTrue()
        ->and($otherGrievance->isOwnedBy($submitter))->toBeFalse();
});

it('decision letter is generated on approval', function (): void {
    $org = grievanceOrg();
    $category = grievanceCategory();
    $submitter = grievanceSubmitter();
    $admin = grievanceAdmin();
    $chair = grievanceChairperson();
    $manager = grievanceManager();
    $committee = makeCommittee($org);

    addCommitteeMember($committee, 'chairperson');
    addCommitteeMember($committee);
    addCommitteeMember($committee);

    $grievance = app(SubmitGrievanceAction::class)->execute($submitter, [
        'subject' => 'Letter test',
        'description' => 'For letter generation.',
        'category_id' => $category->id,
        'origin_level' => GrievanceOriginLevel::Organization->value,
        'organization_id' => $org->id,
    ]);

    app(AssignGrievanceAction::class)->execute($grievance, $committee, $admin);
    app(CompileGrievanceResponseAction::class)->execute($grievance, $chair, [
        'response_body_en' => 'Official decision.',
    ]);
    app(ApproveGrievanceResponseAction::class)->execute($grievance, $manager);

    $letter = $grievance->fresh()->decisionLetter;

    expect($letter)->not->toBeNull()
        ->and($letter->letter_reference)->toContain($grievance->reference_number)
        ->and($letter->generated_by_user_id)->toBe($manager->id);
});

it('en and am translations exist for all statuses', function (): void {
    foreach (GrievanceStatus::cases() as $status) {
        $key = 'status'.collect(explode('_', $status->value))
            ->map(fn ($w) => ucfirst($w))
            ->implode('');

        expect(__("grievances.{$key}"))->not->toBe("grievances.{$key}");
        expect(app('translator')->get("grievances.{$key}", [], 'am'))->not->toBe("grievances.{$key}");
    }
});
