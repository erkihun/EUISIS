<?php

declare(strict_types=1);

use App\Actions\Transfers\ApproveTransferApprovalAction;
use App\Actions\Transfers\CancelTransferAnnouncementAction;
use App\Actions\Transfers\CompleteTransferAction;
use App\Actions\Transfers\CreateTransferAnnouncementAction;
use App\Actions\Transfers\CreateTransferApplicationAction;
use App\Actions\Transfers\PublishTransferAnnouncementAction;
use App\Actions\Transfers\UpdateTransferSettingsAction;
use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Enums\EstablishmentStatus;
use App\Enums\OrganizationStatus;
use App\Enums\TransferAnnouncementStatus;
use App\Enums\TransferApplicationStatus;
use App\Enums\TransferApprovalStatus;
use App\Enums\TransferApprovalType;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeePerformanceAgreement;
use App\Models\EmployeeTransfer;
use App\Models\Kpi;
use App\Models\KpiActual;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\PerformanceCycle;
use App\Models\PerformancePlan;
use App\Models\PerformanceResult;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\TransferAnnouncement;
use App\Models\TransferApplication;
use App\Models\TransferApproval;
use App\Models\TransferSetting;
use App\Models\User;
use App\Services\Performance\EmployeeAgreementService;
use App\Services\Performance\KpiActualService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    foreach ([
        'transfers.view', 'transfers.settings.manage',
        'transfers.announcements.view', 'transfers.announcements.create',
        'transfers.announcements.publish', 'transfers.announcements.close',
        'transfers.applications.view', 'transfers.applications.create',
        'transfers.applications.screen', 'transfers.release.approve',
        'transfers.receiving.approve', 'transfers.final.approve',
        'transfers.complete',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
});

function makeTransferOrg(string $code): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'DEPT'], ['name_en' => 'Department']);

    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => $code,
        'name_en' => 'Organization '.$code,
        'status' => OrganizationStatus::Active,
    ]);
}

function makeTransferPos(string $orgId): Position
{
    static $counter = 0;
    $counter++;

    $position = Position::query()->create([
        'organization_id' => $orgId,
        'title_en' => 'Test Position '.$counter,
        'job_position_code' => 'POS-TM-'.$counter,
        'is_active' => true,
    ]);

    PositionEstablishment::query()->create([
        'organization_id' => $orgId,
        'position_id' => $position->id,
        'establishment_number' => 'EST-TM-'.$counter,
        'approved_slots' => 5,
        'status' => EstablishmentStatus::Approved,
        'effective_from' => now()->subYear()->toDateString(),
    ]);

    return $position;
}

function makeTransferEmployee(Organization $org, Position $pos): Employee
{
    $employee = Employee::query()->create([
        'employee_number' => 'EMP-'.uniqid(),
        'full_name' => 'Test Employee',
        'first_name' => 'Test',
        'last_name' => 'Employee',
        'gender' => 'M',
        'status' => EmployeeStatus::Active,
    ]);

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'assignment_status' => AssignmentStatus::Active,
        'effective_from' => now()->subMonths(6)->toDateString(),
        'is_current' => true,
    ]);

    $employee->update(['current_assignment_id' => $assignment->id]);

    return $employee->fresh(['currentAssignment']);
}

function makeTransferActor(string ...$permissions): User
{
    $user = User::factory()->create();
    foreach ($permissions as $perm) {
        $user->givePermissionTo($perm);
    }

    return $user;
}

// ── Settings ──────────────────────────────────────────────────────────────────

test('transfer settings can be retrieved', function (): void {
    $settings = TransferSetting::current();
    expect($settings)->toBeInstanceOf(TransferSetting::class);
    expect($settings->releasing_consent_required)->toBeBool();
});

test('transfer settings update is authorized', function (): void {
    $actor = makeTransferActor('transfers.settings.manage');

    $action = app(UpdateTransferSettingsAction::class);
    $action->execute(['releasing_consent_required' => false], $actor);

    $settings = TransferSetting::current()->fresh();
    expect($settings->releasing_consent_required)->toBeFalse();
});

// ── Announcements ─────────────────────────────────────────────────────────────

test('announcement can be created in draft status', function (): void {
    $org = makeTransferOrg('ANN-ORG');
    $pos = makeTransferPos($org->id);
    $actor = makeTransferActor('transfers.announcements.create');

    $action = app(CreateTransferAnnouncementAction::class);
    $announcement = $action->execute([
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'number_of_vacancies' => 2,
        'opening_date' => now()->toDateString(),
        'closing_date' => now()->addDays(30)->toDateString(),
    ], $actor);

    expect($announcement->status)->toBe(TransferAnnouncementStatus::Draft);
    expect($announcement->organization_id)->toBe($org->id);
});

test('announcement opening date must be before closing date', function (): void {
    $org = makeTransferOrg('DATE-ORG');
    $pos = makeTransferPos($org->id);
    $actor = makeTransferActor('transfers.announcements.create');

    $action = app(CreateTransferAnnouncementAction::class);

    expect(fn () => $action->execute([
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->toDateString(),
        'closing_date' => now()->subDay()->toDateString(),
    ], $actor))->toThrow(DomainException::class);
});

test('announcement can only be published when in draft status', function (): void {
    $org = makeTransferOrg('PUB-ORG');
    $pos = makeTransferPos($org->id);
    $actor = makeTransferActor('transfers.announcements.publish');

    // Create a published establishment so publish validation passes
    PositionEstablishment::query()->create([
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'status' => EstablishmentStatus::Approved->value,
        'approved_slots' => 1,
        'establishment_number' => 'EST-001',
        'effective_from' => now()->subYear()->toDateString(),
    ]);

    $announcement = TransferAnnouncement::query()->create([
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->toDateString(),
        'closing_date' => now()->addDays(30)->toDateString(),
        'status' => TransferAnnouncementStatus::Draft,
        'created_by' => makeTransferActor()->id,
    ]);

    $publishAction = app(PublishTransferAnnouncementAction::class);
    $published = $publishAction->execute($announcement, $actor);

    expect($published->status)->toBe(TransferAnnouncementStatus::Published);
    expect($published->published_at)->not->toBeNull();
});

// ── Applications ──────────────────────────────────────────────────────────────

test('active employee can apply for published announcement', function (): void {
    $fromOrg = makeTransferOrg('APP-FROM');
    $toOrg = makeTransferOrg('APP-TO');
    $position = makeTransferPos($toOrg->id);
    $employee = makeTransferEmployee($fromOrg, makeTransferPos($fromOrg->id));

    $announcement = TransferAnnouncement::query()->create([
        'organization_id' => $toOrg->id,
        'position_id' => $position->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->subDay()->toDateString(),
        'closing_date' => now()->addDays(30)->toDateString(),
        'status' => TransferAnnouncementStatus::Published,
        'created_by' => makeTransferActor()->id,
    ]);

    $actor = makeTransferActor('transfers.applications.create');
    $action = app(CreateTransferApplicationAction::class);

    $application = $action->execute($announcement, $employee, $actor);

    expect($application->status)->toBe(TransferApplicationStatus::Submitted);
    expect($application->employee_id)->toBe($employee->id);
    expect($application->releasing_organization_id)->toBe($fromOrg->id);
    expect($application->receiving_organization_id)->toBe($toOrg->id);
});

test('duplicate application for same announcement is blocked', function (): void {
    $fromOrg = makeTransferOrg('DUP-FROM');
    $toOrg = makeTransferOrg('DUP-TO');
    $position = makeTransferPos($toOrg->id);
    $employee = makeTransferEmployee($fromOrg, makeTransferPos($fromOrg->id));

    $announcement = TransferAnnouncement::query()->create([
        'organization_id' => $toOrg->id,
        'position_id' => $position->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->subDay()->toDateString(),
        'closing_date' => now()->addDays(30)->toDateString(),
        'status' => TransferAnnouncementStatus::Published,
        'created_by' => makeTransferActor()->id,
    ]);

    $actor = makeTransferActor('transfers.applications.create');
    $action = app(CreateTransferApplicationAction::class);
    $action->execute($announcement, $employee, $actor);

    expect(fn () => $action->execute($announcement, $employee, $actor))->toThrow(DomainException::class);
});

test('inactive announcement does not accept applications', function (): void {
    $fromOrg = makeTransferOrg('INACT-FROM');
    $toOrg = makeTransferOrg('INACT-TO');
    $position = makeTransferPos($toOrg->id);
    $employee = makeTransferEmployee($fromOrg, makeTransferPos($fromOrg->id));

    $closedAnnouncement = TransferAnnouncement::query()->create([
        'organization_id' => $toOrg->id,
        'position_id' => $position->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->subDays(60)->toDateString(),
        'closing_date' => now()->subDay()->toDateString(),
        'status' => TransferAnnouncementStatus::Published,
        'created_by' => makeTransferActor()->id,
    ]);

    $actor = makeTransferActor('transfers.applications.create');
    $action = app(CreateTransferApplicationAction::class);

    expect(fn () => $action->execute($closedAnnouncement, $employee, $actor))->toThrow(DomainException::class);
});

// ── Completion ────────────────────────────────────────────────────────────────

test('employee identity is unchanged after transfer completion', function (): void {
    $fromOrg = makeTransferOrg('COMP-FROM');
    $toOrg = makeTransferOrg('COMP-TO');
    $toPos = makeTransferPos($toOrg->id);
    $employee = makeTransferEmployee($fromOrg, makeTransferPos($fromOrg->id));

    $employeeIdBefore = $employee->id;
    $employeeNumberBefore = $employee->employee_number;

    $announcement = TransferAnnouncement::query()->create([
        'organization_id' => $toOrg->id,
        'position_id' => $toPos->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->subDay()->toDateString(),
        'closing_date' => now()->addDays(30)->toDateString(),
        'status' => TransferAnnouncementStatus::Published,
        'created_by' => makeTransferActor()->id,
    ]);

    $actor = makeTransferActor('transfers.applications.create', 'transfers.complete');
    $application = TransferApplication::query()->create([
        'announcement_id' => $announcement->id,
        'employee_id' => $employee->id,
        'current_assignment_id' => $employee->current_assignment_id,
        'releasing_organization_id' => $fromOrg->id,
        'receiving_organization_id' => $toOrg->id,
        'status' => TransferApplicationStatus::Approved,
        'submitted_at' => now(),
    ]);

    // Configure settings to skip all approvals
    $settings = TransferSetting::current();
    $settings->update([
        'releasing_consent_required' => false,
        'receiving_consent_required' => false,
        'final_approval_required' => false,
        'card_reprint_policy' => 'no_reprint',
        'service_recalculation_policy' => 'no_recalculation',
    ]);

    $completeAction = app(CompleteTransferAction::class);
    $completeAction->execute($application->fresh(), $actor);

    $employee->refresh();

    expect($employee->id)->toBe($employeeIdBefore);
    expect($employee->employee_number)->toBe($employeeNumberBefore);
});

test('old assignment is closed and new assignment is created after transfer', function (): void {
    $fromOrg = makeTransferOrg('ASG-FROM');
    $toOrg = makeTransferOrg('ASG-TO');
    $toPos = makeTransferPos($toOrg->id);
    $fromPos = makeTransferPos($fromOrg->id);
    $employee = makeTransferEmployee($fromOrg, $fromPos);

    $oldAssignmentId = $employee->current_assignment_id;

    $announcement = TransferAnnouncement::query()->create([
        'organization_id' => $toOrg->id,
        'position_id' => $toPos->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->subDay()->toDateString(),
        'closing_date' => now()->addDays(30)->toDateString(),
        'status' => TransferAnnouncementStatus::Published,
        'created_by' => makeTransferActor()->id,
    ]);

    $application = TransferApplication::query()->create([
        'announcement_id' => $announcement->id,
        'employee_id' => $employee->id,
        'current_assignment_id' => $oldAssignmentId,
        'releasing_organization_id' => $fromOrg->id,
        'receiving_organization_id' => $toOrg->id,
        'status' => TransferApplicationStatus::Approved,
        'submitted_at' => now(),
    ]);

    $settings = TransferSetting::current();
    $settings->update([
        'releasing_consent_required' => false,
        'receiving_consent_required' => false,
        'final_approval_required' => false,
        'card_reprint_policy' => 'no_reprint',
        'service_recalculation_policy' => 'no_recalculation',
    ]);

    $actor = makeTransferActor('transfers.complete');
    app(CompleteTransferAction::class)->execute($application->fresh(), $actor);

    $employee->refresh();

    // Old assignment closed
    $oldAssignment = EmployeeAssignment::find($oldAssignmentId);
    expect($oldAssignment->is_current)->toBeFalse();
    expect($oldAssignment->assignment_status)->toBe(AssignmentStatus::Closed);

    // New assignment in new organization
    expect($employee->current_assignment_id)->not->toBe($oldAssignmentId);
    $newAssignment = EmployeeAssignment::find($employee->current_assignment_id);
    expect($newAssignment->organization_id)->toBe($toOrg->id);
    expect($newAssignment->is_current)->toBeTrue();
    expect($newAssignment->assignment_status)->toBe(AssignmentStatus::Active);

    // Application marked as transferred
    $application->refresh();
    expect($application->status)->toBe(TransferApplicationStatus::Transferred);
});

function makeTransferPerformanceContext(Employee $employee, User $actor, bool $sameDay = false): array
{
    $assignment = $employee->currentAssignment;
    $cycle = PerformanceCycle::query()->create(['code' => 'TRANSFER-EP', 'name_en' => 'Transfer performance cycle', 'organization_id' => $assignment->organization_id, 'start_date' => now()->startOfYear()->toDateString(), 'end_date' => now()->endOfYear()->toDateString()]);
    $plan = new PerformancePlan(['cycle_id' => $cycle->id, 'organization_id' => $assignment->organization_id, 'position_id' => $assignment->position_id, 'plan_type' => 'POSITION', 'title' => 'Original position plan']);
    $plan->forceFill(['lineage_key' => 'transfer-history-plan', 'status' => 'PUBLISHED'])->save();
    $objective = $plan->objectives()->create(['code' => 'TRANSFER-O', 'title_en' => 'Complete requests', 'objective_type' => 'LOCAL', 'weight' => 100]);
    $kpi = Kpi::query()->create(['code' => 'TRANSFER-K', 'name_en' => 'Requests', 'measurement_type' => 'COUNT', 'direction' => 'HIGHER_IS_BETTER', 'aggregation_method' => 'SUM', 'data_source_type' => 'MANUAL', 'frequency' => 'ANNUAL']);
    $target = $objective->targets()->create(['performance_plan_id' => $plan->id, 'kpi_id' => $kpi->id, 'period_start' => $cycle->start_date->toDateString(), 'period_end' => $cycle->end_date->toDateString(), 'target_value' => 100, 'weight' => 100]);
    $agreement = EmployeePerformanceAgreement::query()->create(['cycle_id' => $cycle->id, 'employee_id' => $employee->id, 'employee_assignment_id' => $assignment->id, 'performance_plan_id' => $plan->id, 'organization_id' => $assignment->organization_id, 'position_id' => $assignment->position_id, 'manager_user_id' => $actor->id, 'effective_from' => ($sameDay ? now() : now()->subMonth())->toDateString(), 'effective_to' => $cycle->end_date->toDateString()]);
    $agreement->forceFill(['status' => 'ACTIVE', 'active_key' => $employee->id.':'.$assignment->id.':'.$cycle->id])->save();
    $item = $agreement->items()->create(['objective_id' => $objective->id, 'kpi_id' => $kpi->id, 'position_target_id' => $target->id, 'expected_output' => 'Complete requests', 'target_value' => 100, 'weight' => 100]);
    $actual = KpiActual::query()->create(['kpi_id' => $kpi->id, 'employee_performance_item_id' => $item->id, 'subject_key' => 'item:'.$item->id, 'agreement_id' => $agreement->id, 'performance_plan_id' => $plan->id, 'employee_id' => $employee->id, 'organization_id' => $assignment->organization_id, 'period_start' => $agreement->effective_from->toDateString(), 'period_end' => ($sameDay ? now() : now()->subDay())->toDateString(), 'actual_value' => 7, 'source_type' => 'MANUAL', 'source_key' => 'manual']);
    $result = new PerformanceResult(['employee_id' => $employee->id, 'cycle_id' => $cycle->id, 'agreement_id' => $agreement->id, 'organization_id' => $assignment->organization_id]);
    $result->forceFill(['results_score' => 7, 'results_weight' => 100, 'competency_weight' => 0, 'calculated_score' => 7, 'final_score' => 7, 'calculated_at' => now(), 'snapshot_json' => ['agreement_id' => $agreement->id, 'assignment_id' => $assignment->id, 'item_id' => $item->id, 'actual' => '7.0000']])->save();

    return compact('agreement', 'plan', 'item', 'actual', 'result');
}

test('HR transfer closes old performance responsibility and preserves its measurement history', function (): void {
    $this->travelTo(Carbon::parse('2026-07-01 10:00:00'));
    $from = makeTransferOrg('EP-CLOSE-FROM');
    $to = makeTransferOrg('EP-CLOSE-TO');
    $employee = makeTransferEmployee($from, makeTransferPos($from->id));
    $oldAssignment = $employee->currentAssignment;
    $actor = makeTransferActor('transfers.complete');
    $context = makeTransferPerformanceContext($employee, $actor);
    $announcement = TransferAnnouncement::query()->create(['organization_id' => $to->id, 'position_id' => makeTransferPos($to->id)->id, 'number_of_vacancies' => 1, 'opening_date' => now()->subDay()->toDateString(), 'closing_date' => now()->addDays(30)->toDateString(), 'status' => TransferAnnouncementStatus::Published, 'created_by' => $actor->id]);
    $application = TransferApplication::query()->create(['announcement_id' => $announcement->id, 'employee_id' => $employee->id, 'current_assignment_id' => $oldAssignment->id, 'releasing_organization_id' => $from->id, 'receiving_organization_id' => $to->id, 'status' => TransferApplicationStatus::Approved, 'submitted_at' => now()]);
    TransferSetting::current()->update(['card_reprint_policy' => 'no_reprint', 'service_recalculation_policy' => 'no_recalculation']);
    $oldActual = $context['actual']->fresh()->getRawOriginal();
    $oldResult = $context['result']->fresh()->getRawOriginal();
    $oldItem = $context['item']->fresh()->getRawOriginal();

    app(CompleteTransferAction::class)->execute($application, $actor);
    $agreement = $context['agreement']->fresh();

    expect($agreement->status->value)->toBe('CLOSED')
        ->and($agreement->effective_to->toDateString())->toBe('2026-06-30')
        ->and($agreement->active_key)->toBeNull()
        ->and($agreement->employee_assignment_id)->toBe($oldAssignment->id)
        ->and($agreement->performance_plan_id)->toBe($context['plan']->id)
        ->and($context['item']->fresh()->getRawOriginal())->toBe($oldItem)
        ->and($context['actual']->fresh()->getRawOriginal())->toBe($oldActual)
        ->and($context['result']->fresh()->getRawOriginal())->toBe($oldResult);
    $this->assertDatabaseCount('employee_performance_agreements', 1);

    foreach (['employee_performance_agreements.manage', 'kpi_actuals.enter'] as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    expect(fn () => app(KpiActualService::class)->recordForItem($context['item']->fresh(), ['period_start' => '2026-07-01', 'period_end' => '2026-07-02', 'actual_value' => 5], $actor))
        ->toThrow(ValidationException::class);
    expect(app(EmployeeAgreementService::class)->close($agreement, '2026-06-30', 'Repeated closure', $actor)->status->value)->toBe('CLOSED');
});

test('a same-day active agreement rolls back HR transfer rather than creating an invalid history period', function (): void {
    $this->travelTo(Carbon::parse('2026-07-01 10:00:00'));
    $from = makeTransferOrg('EP-ROLL-FROM');
    $to = makeTransferOrg('EP-ROLL-TO');
    $employee = makeTransferEmployee($from, makeTransferPos($from->id));
    $oldAssignment = $employee->currentAssignment;
    $actor = makeTransferActor('transfers.complete');
    $context = makeTransferPerformanceContext($employee, $actor, sameDay: true);
    $announcement = TransferAnnouncement::query()->create(['organization_id' => $to->id, 'position_id' => makeTransferPos($to->id)->id, 'number_of_vacancies' => 1, 'opening_date' => now()->subDay()->toDateString(), 'closing_date' => now()->addDays(30)->toDateString(), 'status' => TransferAnnouncementStatus::Published, 'created_by' => $actor->id]);
    $application = TransferApplication::query()->create(['announcement_id' => $announcement->id, 'employee_id' => $employee->id, 'current_assignment_id' => $oldAssignment->id, 'releasing_organization_id' => $from->id, 'receiving_organization_id' => $to->id, 'status' => TransferApplicationStatus::Approved, 'submitted_at' => now()]);
    TransferSetting::current()->update(['card_reprint_policy' => 'no_reprint', 'service_recalculation_policy' => 'no_recalculation']);

    expect(fn () => app(CompleteTransferAction::class)->execute($application, $actor))->toThrow(ValidationException::class);
    expect($employee->fresh()->current_assignment_id)->toBe($oldAssignment->id)
        ->and($oldAssignment->fresh()->assignment_status)->toBe(AssignmentStatus::Active)
        ->and($oldAssignment->fresh()->is_current)->toBeTrue()
        ->and($application->fresh()->status)->toBe(TransferApplicationStatus::Approved)
        ->and($context['agreement']->fresh()->status->value)->toBe('ACTIVE');
    $this->assertDatabaseCount('employee_assignments', 1);
});

test('transfer cannot complete without full approval when approvals are required', function (): void {
    $fromOrg = makeTransferOrg('NOAPR-FROM');
    $toOrg = makeTransferOrg('NOAPR-TO');
    $toPos = makeTransferPos($toOrg->id);
    $employee = makeTransferEmployee($fromOrg, makeTransferPos($fromOrg->id));

    $announcement = TransferAnnouncement::query()->create([
        'organization_id' => $toOrg->id,
        'position_id' => $toPos->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->subDay()->toDateString(),
        'closing_date' => now()->addDays(30)->toDateString(),
        'status' => TransferAnnouncementStatus::Published,
        'created_by' => makeTransferActor()->id,
    ]);

    // Application not yet approved
    $application = TransferApplication::query()->create([
        'announcement_id' => $announcement->id,
        'employee_id' => $employee->id,
        'current_assignment_id' => $employee->current_assignment_id,
        'releasing_organization_id' => $fromOrg->id,
        'receiving_organization_id' => $toOrg->id,
        'status' => TransferApplicationStatus::ReleasePending, // not Approved
        'submitted_at' => now(),
    ]);

    $actor = makeTransferActor('transfers.complete');

    expect(fn () => app(CompleteTransferAction::class)->execute($application->fresh(), $actor))
        ->toThrow(DomainException::class);
});

test('approved transfer waits for its effective date without changing the assignment', function (): void {
    $fromOrg = makeTransferOrg('FUTURE-FROM');
    $toOrg = makeTransferOrg('FUTURE-TO');
    $employee = makeTransferEmployee($fromOrg, makeTransferPos($fromOrg->id));
    $target = makeTransferPos($toOrg->id);
    $actor = makeTransferActor('transfers.complete');
    $announcement = TransferAnnouncement::query()->create([
        'organization_id' => $toOrg->id,
        'position_id' => $target->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->subDay()->toDateString(),
        'closing_date' => now()->addDays(30)->toDateString(),
        'status' => TransferAnnouncementStatus::Published,
        'created_by' => $actor->id,
    ]);
    $application = TransferApplication::query()->create([
        'announcement_id' => $announcement->id,
        'employee_id' => $employee->id,
        'current_assignment_id' => $employee->current_assignment_id,
        'releasing_organization_id' => $fromOrg->id,
        'receiving_organization_id' => $toOrg->id,
        'effective_date' => now()->addWeek()->toDateString(),
        'status' => TransferApplicationStatus::Approved,
        'submitted_at' => now(),
    ]);

    expect(fn () => app(CompleteTransferAction::class)->execute($application, $actor))->toThrow(DomainException::class);
    expect($employee->fresh()->current_assignment_id)->toBe($application->current_assignment_id)
        ->and($application->fresh()->status)->toBe(TransferApplicationStatus::Approved);
});

test('implementation rejects a destination with no approved capacity', function (): void {
    $fromOrg = makeTransferOrg('CAPACITY-FROM');
    $toOrg = makeTransferOrg('CAPACITY-TO');
    $employee = makeTransferEmployee($fromOrg, makeTransferPos($fromOrg->id));
    $target = makeTransferPos($toOrg->id);
    PositionEstablishment::query()->where('position_id', $target->id)->update(['approved_slots' => 0]);
    $actor = makeTransferActor('transfers.complete');
    $announcement = TransferAnnouncement::query()->create([
        'organization_id' => $toOrg->id,
        'position_id' => $target->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->subDay()->toDateString(),
        'closing_date' => now()->addDays(30)->toDateString(),
        'status' => TransferAnnouncementStatus::Published,
        'created_by' => $actor->id,
    ]);
    $application = TransferApplication::query()->create([
        'announcement_id' => $announcement->id,
        'employee_id' => $employee->id,
        'current_assignment_id' => $employee->current_assignment_id,
        'releasing_organization_id' => $fromOrg->id,
        'receiving_organization_id' => $toOrg->id,
        'status' => TransferApplicationStatus::Approved,
        'submitted_at' => now(),
    ]);

    expect(fn () => app(CompleteTransferAction::class)->execute($application, $actor))->toThrow(DomainException::class);
    expect($employee->fresh()->current_assignment_id)->toBe($application->current_assignment_id)
        ->and($application->fresh()->status)->toBe(TransferApplicationStatus::ImplementationFailed);
});

test('approval chain advances correctly from release to receiving to final', function (): void {
    $fromOrg = makeTransferOrg('CHAIN-FROM');
    $toOrg = makeTransferOrg('CHAIN-TO');
    $toPos = makeTransferPos($toOrg->id);
    $employee = makeTransferEmployee($fromOrg, makeTransferPos($fromOrg->id));

    $announcement = TransferAnnouncement::query()->create([
        'organization_id' => $toOrg->id,
        'position_id' => $toPos->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->subDay()->toDateString(),
        'closing_date' => now()->addDays(30)->toDateString(),
        'status' => TransferAnnouncementStatus::Published,
        'created_by' => makeTransferActor()->id,
    ]);

    $settings = TransferSetting::current();
    $settings->update([
        'releasing_consent_required' => true,
        'receiving_consent_required' => true,
        'final_approval_required' => false,
        'card_reprint_policy' => 'no_reprint',
        'service_recalculation_policy' => 'no_recalculation',
    ]);

    $application = TransferApplication::query()->create([
        'announcement_id' => $announcement->id,
        'employee_id' => $employee->id,
        'current_assignment_id' => $employee->current_assignment_id,
        'releasing_organization_id' => $fromOrg->id,
        'receiving_organization_id' => $toOrg->id,
        'status' => TransferApplicationStatus::ReleasePending,
        'submitted_at' => now(),
    ]);

    $releaseApproval = TransferApproval::query()->create([
        'transfer_application_id' => $application->id,
        'approval_type' => TransferApprovalType::Release->value,
        'status' => TransferApprovalStatus::Pending->value,
    ]);
    TransferApproval::query()->create([
        'transfer_application_id' => $application->id,
        'approval_type' => TransferApprovalType::Receiving->value,
        'status' => TransferApprovalStatus::Pending->value,
    ]);

    $actor = makeTransferActor('transfers.release.approve', 'transfers.receiving.approve', 'transfers.complete');
    $action = app(ApproveTransferApprovalAction::class);

    // Approve release
    $action->execute($releaseApproval, $actor);
    $application->refresh();
    expect($application->status)->toBe(TransferApplicationStatus::ReceivingPending);

    // Approve receiving — triggers auto-complete since final not required
    $receivingApproval = TransferApproval::query()
        ->where('transfer_application_id', $application->id)
        ->where('approval_type', 'receiving')
        ->first();

    $action->execute($receivingApproval, $actor);
    $application->refresh();

    // Approval must remain distinct from implementation.
    expect($application->status)->toBe(TransferApplicationStatus::Approved);
    $employee->refresh();
    expect($employee->current_assignment_id)->toBe($application->current_assignment_id);
    expect(EmployeeTransfer::query()->where('transfer_application_id', $application->id)->count())->toBe(1);
});

// ─── Announcement Action Tests ───────────────────────────────────────────────

test('publish action changes status from draft to published', function (): void {
    $org = makeTransferOrg('PUB-ORG');
    $pos = makeTransferPos($org->id);

    PositionEstablishment::query()->create([
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'establishment_number' => 'EST-TEST-'.uniqid(),
        'approved_slots' => 2,
        'status' => 'approved',
        'effective_from' => now()->subDay(),
    ]);

    $announcement = TransferAnnouncement::query()->create([
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'number_of_vacancies' => 2,
        'opening_date' => now()->addDay(),
        'closing_date' => now()->addDays(10),
        'status' => TransferAnnouncementStatus::Draft,
        'created_by' => User::factory()->create()->id,
    ]);

    $actor = User::factory()->create();
    $action = app(PublishTransferAnnouncementAction::class);
    $action->execute($announcement, $actor);

    $announcement->refresh();
    expect($announcement->status)->toBe(TransferAnnouncementStatus::Published);
    expect($announcement->published_by)->toBe($actor->id);
});

test('publish action throws for non-draft announcement', function (): void {
    $org = makeTransferOrg('PUB-ERR');
    $pos = makeTransferPos($org->id);
    $user = User::factory()->create();

    $announcement = TransferAnnouncement::query()->create([
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->addDay(),
        'closing_date' => now()->addDays(5),
        'status' => TransferAnnouncementStatus::Published,
        'created_by' => $user->id,
        'published_by' => $user->id,
        'published_at' => now(),
    ]);

    $action = app(PublishTransferAnnouncementAction::class);

    expect(fn () => $action->execute($announcement, $user))
        ->toThrow(DomainException::class);
});

test('publish action throws when closing date is not after opening date', function (): void {
    $org = makeTransferOrg('PUB-DATE');
    $pos = makeTransferPos($org->id);
    $user = User::factory()->create();

    $announcement = TransferAnnouncement::query()->create([
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->addDays(5),
        'closing_date' => now()->addDay(),  // before opening
        'status' => TransferAnnouncementStatus::Draft,
        'created_by' => $user->id,
    ]);

    $action = app(PublishTransferAnnouncementAction::class);

    expect(fn () => $action->execute($announcement, $user))
        ->toThrow(DomainException::class);
});

test('cancel action changes draft to cancelled', function (): void {
    $org = makeTransferOrg('CAN-DRAFT');
    $pos = makeTransferPos($org->id);
    $user = User::factory()->create();

    $announcement = TransferAnnouncement::query()->create([
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->addDay(),
        'closing_date' => now()->addDays(5),
        'status' => TransferAnnouncementStatus::Draft,
        'created_by' => $user->id,
    ]);

    $action = app(CancelTransferAnnouncementAction::class);
    $action->execute($announcement, $user);

    $announcement->refresh();
    expect($announcement->status)->toBe(TransferAnnouncementStatus::Cancelled);
});

test('cancel action throws for already-cancelled announcement', function (): void {
    $org = makeTransferOrg('CAN-FINAL');
    $pos = makeTransferPos($org->id);
    $user = User::factory()->create();

    $announcement = TransferAnnouncement::query()->create([
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->addDay(),
        'closing_date' => now()->addDays(5),
        'status' => TransferAnnouncementStatus::Cancelled,
        'created_by' => $user->id,
    ]);

    $action = app(CancelTransferAnnouncementAction::class);

    expect(fn () => $action->execute($announcement, $user))
        ->toThrow(DomainException::class);
});

test('close route rejects non-published announcements', function (): void {
    Permission::findOrCreate('transfers.announcements.update', 'web');

    $user = User::factory()->create();
    $user->givePermissionTo(['transfers.announcements.view', 'transfers.announcements.close']);

    $org = makeTransferOrg('CLOSE-DRAFT');
    $pos = makeTransferPos($org->id);

    $announcement = TransferAnnouncement::query()->create([
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->addDay(),
        'closing_date' => now()->addDays(5),
        'status' => TransferAnnouncementStatus::Draft,
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->post(route('transfer-announcements.close', $announcement))
        ->assertRedirect();

    $announcement->refresh();
    // Status should not have changed to closed since it was draft
    expect($announcement->status)->toBe(TransferAnnouncementStatus::Draft);
});

test('cancel route is protected by authorization', function (): void {
    $guest = User::factory()->create(); // no permissions

    $org = makeTransferOrg('CANCEL-AUTHZ');
    $pos = makeTransferPos($org->id);

    $announcement = TransferAnnouncement::query()->create([
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->addDay(),
        'closing_date' => now()->addDays(5),
        'status' => TransferAnnouncementStatus::Draft,
        'created_by' => $guest->id,
    ]);

    $this->actingAs($guest)
        ->post(route('transfer-announcements.cancel', $announcement))
        ->assertForbidden();
});
