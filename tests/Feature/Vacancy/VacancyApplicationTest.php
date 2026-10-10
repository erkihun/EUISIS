<?php

declare(strict_types=1);

use App\Actions\Vacancy\CompleteVacancyTransferAction;
use App\Enums\EstablishmentStatus;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Enums\VacancyAnnouncementStatus;
use App\Enums\VacancyApplicationStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeTransfer;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Models\VacancyAnnouncement;
use App\Models\VacancyAnnouncementPosition;
use App\Models\VacancyApplication;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    app()->setLocale('en');

    foreach ([
        'position-establishments.viewAny',
        'position-establishments.view',
        'vacancy-announcements.viewAny',
        'vacancy-announcements.view',
        'vacancy-announcements.create',
        'vacancy-announcements.publish',
        'vacancy-announcements.close',
        'vacancy-applications.viewAny',
        'vacancy-applications.view',
        'vacancy-applications.submit',
        'vacancy-applications.withdraw',
        'vacancy-applications.screen',
        'vacancy-applications.select',
        'vacancy-applications.reject',
        'vacancy-applications.initiateTransfer',
    ] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
});

function makeTestOrgForApp(): Organization
{
    $orgType = OrganizationType::query()->firstOrCreate(
        ['code' => 'TEST-TYPE-APP'],
        ['name_en' => 'Test Type', 'is_active' => true],
    );

    return Organization::query()->create([
        'organization_type_id' => $orgType->id,
        'code' => 'ORG-APP-'.uniqid(),
        'name_en' => 'Test Organization',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);
}

function superAdminForApplications(): User
{
    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    return $user;
}

function makePublishedAnnouncementForApp(int $slots = 2): VacancyAnnouncement
{
    $org = makeTestOrgForApp();
    $pos = (new Position)->forceFill([
        'organization_id' => $org->id,
        'job_position_code' => 'JPC-'.uniqid(),
        'title_en' => 'Test Position',
        'is_active' => true,
    ]);
    $pos->save();

    $establishment = PositionEstablishment::create([
        'establishment_number' => 'EST-APP-'.uniqid(),
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'approved_slots' => $slots,
        'effective_from' => '2025-01-01',
        'status' => EstablishmentStatus::Approved->value,
    ]);

    $announcement = VacancyAnnouncement::create([
        'announcement_number' => 'VCY-APP-'.uniqid(),
        'title_en' => 'Open Vacancy',
        'status' => VacancyAnnouncementStatus::Published->value,
    ]);

    VacancyAnnouncementPosition::create([
        'vacancy_announcement_id' => $announcement->id,
        'position_establishment_id' => $establishment->id,
        'organization_id' => $org->id,
        'organization_unit_id' => $establishment->organization_unit_id,
        'position_id' => $pos->id,
        'vacancy_slots' => $slots,
    ]);

    return $announcement->load('positions');
}

function makeEmployeeWithAssignment(string $organizationId): array
{
    $employee = (new Employee)->forceFill([
        'employee_number' => 'EMP-'.uniqid(),
        'first_name' => 'Test',
        'last_name' => 'Employee',
        'full_name' => 'Test Employee',
        'status' => 'active',
    ]);
    $employee->save();

    $assignment = EmployeeAssignment::create([
        'employee_id' => $employee->id,
        'organization_id' => $organizationId,
        'assignment_status' => 'active',
        'effective_from' => '2025-01-01',
        'is_current' => true,
    ]);

    $employee->update(['current_assignment_id' => $assignment->id]);

    return [$employee->fresh(), $assignment];
}

function makeApplicationForTest(VacancyAnnouncement $announcement, string $employeeId, string $status): VacancyApplication
{
    $employee = Employee::query()->with('currentAssignment')->findOrFail($employeeId);
    $positionEntry = $announcement->positions()->firstOrFail();

    return VacancyApplication::create([
        'application_number' => 'APP-TEST-'.uniqid(),
        'vacancy_announcement_id' => $announcement->id,
        'vacancy_announcement_position_id' => $positionEntry->id,
        'employee_id' => $employeeId,
        'current_organization_id' => $employee->currentAssignment?->organization_id ?? $positionEntry->organization_id,
        'current_position_id' => $employee->currentAssignment?->position_id,
        'status' => $status,
        'applied_at' => now(),
    ]);
}

it('employee can submit application to open announcement', function (): void {
    $user = superAdminForApplications();
    $announcement = makePublishedAnnouncementForApp();
    $positionEntry = $announcement->positions->first();
    [$employee] = makeEmployeeWithAssignment($positionEntry->organization_id);

    $response = $this->actingAs($user)->post(route('vacancy-applications.store'), [
        'vacancy_announcement_position_id' => $positionEntry->id,
        'employee_id' => $employee->id,
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    expect(VacancyApplication::where('employee_id', $employee->id)->exists())->toBeTrue();
});

it('cannot apply twice to the same announcement', function (): void {
    $user = superAdminForApplications();
    $announcement = makePublishedAnnouncementForApp();
    $positionEntry = $announcement->positions->first();
    [$employee] = makeEmployeeWithAssignment($positionEntry->organization_id);

    $this->actingAs($user)->post(route('vacancy-applications.store'), [
        'vacancy_announcement_position_id' => $positionEntry->id,
        'employee_id' => $employee->id,
    ]);

    $response = $this->actingAs($user)->postJson(route('vacancy-applications.store'), [
        'vacancy_announcement_position_id' => $positionEntry->id,
        'employee_id' => $employee->id,
    ]);

    expect($response->status())->toBe(422);
    expect(VacancyApplication::where('employee_id', $employee->id)->count())->toBe(1);
});

it('cannot apply to a non-published announcement', function (): void {
    $user = superAdminForApplications();
    $announcement = makePublishedAnnouncementForApp();
    $positionEntry = $announcement->positions->first();
    [$employee] = makeEmployeeWithAssignment($positionEntry->organization_id);

    $announcement->update(['status' => VacancyAnnouncementStatus::Closed->value]);

    $response = $this->actingAs($user)->postJson(route('vacancy-applications.store'), [
        'vacancy_announcement_position_id' => $positionEntry->id,
        'employee_id' => $employee->id,
    ]);

    expect($response->status())->toBe(422);
    expect(VacancyApplication::where('employee_id', $employee->id)->exists())->toBeFalse();
});

it('HR can screen an application', function (): void {
    $user = superAdminForApplications();
    $announcement = makePublishedAnnouncementForApp();
    $positionEntry = $announcement->positions->first();
    [$employee] = makeEmployeeWithAssignment($positionEntry->organization_id);
    $application = makeApplicationForTest($announcement, $employee->id, VacancyApplicationStatus::Submitted->value);

    $response = $this->actingAs($user)->post(route('vacancy-applications.screen', $application->id), [
        'screening_score' => 85.5,
        'screening_notes' => 'Strong candidate.',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $app = $application->fresh();
    expect($app->status)->toBe(VacancyApplicationStatus::Screened);
    expect((float) $app->screening_score)->toBe(85.5);
});

it('HR can shortlist a screened application', function (): void {
    $user = superAdminForApplications();
    $announcement = makePublishedAnnouncementForApp();
    $positionEntry = $announcement->positions->first();
    [$employee] = makeEmployeeWithAssignment($positionEntry->organization_id);
    $application = makeApplicationForTest($announcement, $employee->id, VacancyApplicationStatus::Screened->value);

    $response = $this->actingAs($user)->post(route('vacancy-applications.shortlist', $application->id));

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    expect($application->fresh()->status)->toBe(VacancyApplicationStatus::Shortlisted);
});

it('HR can select a shortlisted application', function (): void {
    $user = superAdminForApplications();
    $announcement = makePublishedAnnouncementForApp();
    $positionEntry = $announcement->positions->first();
    [$employee] = makeEmployeeWithAssignment($positionEntry->organization_id);
    $application = makeApplicationForTest($announcement, $employee->id, VacancyApplicationStatus::Shortlisted->value);

    $response = $this->actingAs($user)->post(route('vacancy-applications.select', $application->id));

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    expect($application->fresh()->status)->toBe(VacancyApplicationStatus::Selected);
});

it('cannot select when all slots are already filled', function (): void {
    $user = superAdminForApplications();
    $announcement = makePublishedAnnouncementForApp(slots: 1);
    $positionEntry = $announcement->positions->first();
    [$emp1] = makeEmployeeWithAssignment($positionEntry->organization_id);
    [$emp2] = makeEmployeeWithAssignment($positionEntry->organization_id);

    makeApplicationForTest($announcement, $emp1->id, VacancyApplicationStatus::Selected->value);
    $app2 = makeApplicationForTest($announcement, $emp2->id, VacancyApplicationStatus::Shortlisted->value);

    $response = $this->actingAs($user)->postJson(route('vacancy-applications.select', $app2->id));

    expect($response->status())->toBe(422);
    expect($app2->fresh()->status)->toBe(VacancyApplicationStatus::Shortlisted);
});

it('HR can reject a submitted application', function (): void {
    $user = superAdminForApplications();
    $announcement = makePublishedAnnouncementForApp();
    $positionEntry = $announcement->positions->first();
    [$employee] = makeEmployeeWithAssignment($positionEntry->organization_id);
    $application = makeApplicationForTest($announcement, $employee->id, VacancyApplicationStatus::Submitted->value);

    $response = $this->actingAs($user)->post(route('vacancy-applications.reject', $application->id), [
        'rejection_reason' => 'Not qualified.',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $app = $application->fresh();
    expect($app->status)->toBe(VacancyApplicationStatus::Rejected);
    expect($app->rejection_reason)->toBe('Not qualified.');
});

it('HR can initiate a vacancy transfer for a selected application', function (): void {
    $user = superAdminForApplications();
    $announcement = makePublishedAnnouncementForApp(slots: 2);
    $positionEntry = $announcement->positions->first();

    $sourceOrg = makeTestOrgForApp();
    [$employee] = makeEmployeeWithAssignment($sourceOrg->id);

    $application = makeApplicationForTest($announcement, $employee->id, VacancyApplicationStatus::Selected->value);

    $response = $this->actingAs($user)->post(route('vacancy-applications.initiate-transfer', $application->id), [
        'effective_date' => '2026-06-01',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $transfer = EmployeeTransfer::where('employee_id', $employee->id)->first();

    expect($transfer)->not->toBeNull();
    expect($transfer->transfer_source)->toBe('vacancy');
    expect($transfer->vacancy_application_id)->toBe($application->id);
    expect($transfer->vacancy_announcement_id)->toBe($announcement->id);
    expect($transfer->to_organization_id)->toBe($positionEntry->organization_id);
    expect($application->fresh()->status)->toBe(VacancyApplicationStatus::Transferred);
});

it('vacancy transfer closes old assignment and creates new one', function (): void {
    $user = superAdminForApplications();
    $announcement = makePublishedAnnouncementForApp(slots: 2);
    $positionEntry = $announcement->positions->first();

    $sourceOrg = makeTestOrgForApp();
    [$employee, $oldAssignment] = makeEmployeeWithAssignment($sourceOrg->id);

    $application = makeApplicationForTest($announcement, $employee->id, VacancyApplicationStatus::Selected->value);

    $this->actingAs($user)->post(route('vacancy-applications.initiate-transfer', $application->id), [
        'effective_date' => '2026-06-01',
    ]);

    $oldAssignment->refresh();
    expect($oldAssignment->assignment_status->value)->toBe('closed');
    expect($oldAssignment->is_current)->toBeFalse();

    $employee->refresh();
    $newAssignment = EmployeeAssignment::find($employee->current_assignment_id);
    expect($newAssignment)->not->toBeNull();
    expect($newAssignment->organization_id)->toBe($positionEntry->organization_id);
    expect($newAssignment->is_current)->toBeTrue();
});

it('announcement auto-closes when all slots are filled by transfer', function (): void {
    $user = superAdminForApplications();
    $announcement = makePublishedAnnouncementForApp(slots: 1);

    $sourceOrg = makeTestOrgForApp();
    [$employee] = makeEmployeeWithAssignment($sourceOrg->id);

    $application = makeApplicationForTest($announcement, $employee->id, VacancyApplicationStatus::Selected->value);

    $this->actingAs($user)->post(route('vacancy-applications.initiate-transfer', $application->id), [
        'effective_date' => '2026-06-01',
    ]);

    expect($announcement->fresh()->status)->toBe(VacancyAnnouncementStatus::Closed);
});

it('sends the applications list as a plain paginator with snake_case relations', function (): void {
    $announcement = makePublishedAnnouncementForApp();
    [$employee] = makeEmployeeWithAssignment(makeTestOrgForApp()->id);
    makeApplicationForTest($announcement, $employee->id, 'submitted');

    // VacancyApplications/Index reads from/to/total/current_page/last_page at the top level and
    // position_entry (not meta.* or positionEntry): a mismatch crashed the page.
    $this->actingAs(superAdminForApplications())
        ->get(route('vacancy-applications.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('VacancyApplications/Index')
            ->where('applications.from', 1)->where('applications.to', 1)->where('applications.total', 1)
            ->where('applications.current_page', 1)->where('applications.last_page', 1)
            ->missing('applications.meta')
            ->has('applications.data.0.position_entry.position.title_en')
            ->has('applications.data.0.position_entry.organization.name_en'));
});

it('lists only applications touching the scoped officer organizations, without employee contact data', function (): void {
    $mineAnnouncement = makePublishedAnnouncementForApp();
    $mineOrgId = $mineAnnouncement->positions->first()->organization_id;
    [$mineEmployee] = makeEmployeeWithAssignment($mineOrgId);
    $mine = makeApplicationForTest($mineAnnouncement, $mineEmployee->id, VacancyApplicationStatus::Submitted->value);

    $otherAnnouncement = makePublishedAnnouncementForApp();
    [$otherEmployee] = makeEmployeeWithAssignment($otherAnnouncement->positions->first()->organization_id);
    makeApplicationForTest($otherAnnouncement, $otherEmployee->id, VacancyApplicationStatus::Submitted->value);

    $officer = User::factory()->create();
    $officer->givePermissionTo('vacancy-applications.viewAny');
    UserOrganizationScope::query()->create([
        'user_id' => $officer->id, 'organization_id' => $mineOrgId,
        'scope_type' => OrganizationScopeType::Self, 'is_active' => true,
        'effective_from' => now()->subDay()->toDateString(),
    ]);

    $this->actingAs($officer->fresh())
        ->get(route('vacancy-applications.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('applications.data', 1)
            ->where('applications.data.0.id', $mine->id)
            ->missing('applications.data.0.employee.phone')
            ->missing('applications.data.0.employee.date_of_birth'));
});

it('a double-submitted vacancy transfer is implemented once (stale copy re-checked under lock)', function (): void {
    $user = superAdminForApplications();
    $announcement = makePublishedAnnouncementForApp(slots: 2);
    [$employee] = makeEmployeeWithAssignment(makeTestOrgForApp()->id);
    $application = makeApplicationForTest($announcement, $employee->id, VacancyApplicationStatus::Selected->value);

    // Two requests loaded the same application before either committed.
    $first = VacancyApplication::query()->findOrFail($application->id);
    $stale = VacancyApplication::query()->findOrFail($application->id);
    $action = app(CompleteVacancyTransferAction::class);

    $action->execute($first, ['effective_date' => '2026-06-01'], $user);
    expect(fn () => $action->execute($stale, ['effective_date' => '2026-06-01'], $user))
        ->toThrow(ValidationException::class);

    expect(EmployeeTransfer::query()->where('vacancy_application_id', $application->id)->count())->toBe(1)
        ->and(EmployeeAssignment::query()->where('employee_id', $employee->id)->where('is_current', true)->count())->toBe(1);
});

it('two applications cannot both take the last vacancy slot', function (): void {
    $user = superAdminForApplications();
    $announcement = makePublishedAnnouncementForApp(slots: 1);
    [$a] = makeEmployeeWithAssignment(makeTestOrgForApp()->id);
    [$b] = makeEmployeeWithAssignment(makeTestOrgForApp()->id);
    $appA = makeApplicationForTest($announcement, $a->id, VacancyApplicationStatus::Selected->value);
    $appB = makeApplicationForTest($announcement, $b->id, VacancyApplicationStatus::Selected->value);
    $action = app(CompleteVacancyTransferAction::class);

    $action->execute($appA, ['effective_date' => '2026-06-01'], $user);
    expect(fn () => $action->execute($appB, ['effective_date' => '2026-06-01'], $user))
        ->toThrow(ValidationException::class);

    expect(VacancyApplication::query()->where('status', VacancyApplicationStatus::Transferred->value)->count())->toBe(1);
});

it('refuses a transfer into a position its registered occupants already fill', function (): void {
    // Capacity rule (CON-5): occupants are the current assignments, including
    // people registered directly, who leave no occupancy row behind.
    $user = superAdminForApplications();
    $announcement = makePublishedAnnouncementForApp(slots: 2);
    $entry = $announcement->positions()->firstOrFail();
    foreach ([1, 2] as $n) {
        [$sitting, $assignment] = makeEmployeeWithAssignment($entry->organization_id);
        $assignment->update(['position_id' => $entry->position_id]);
    }
    [$employee] = makeEmployeeWithAssignment(makeTestOrgForApp()->id);
    $application = makeApplicationForTest($announcement, $employee->id, VacancyApplicationStatus::Selected->value);

    expect(fn () => app(CompleteVacancyTransferAction::class)->execute($application, ['effective_date' => '2026-06-01'], $user))
        ->toThrow(ValidationException::class);

    expect(EmployeeAssignment::query()->where('position_id', $entry->position_id)->where('is_current', true)->count())->toBe(2);
});

it('the closed assignment ends the day before the new one starts', function (): void {
    $user = superAdminForApplications();
    $announcement = makePublishedAnnouncementForApp();
    [$employee, $old] = makeEmployeeWithAssignment(makeTestOrgForApp()->id);
    $application = makeApplicationForTest($announcement, $employee->id, VacancyApplicationStatus::Selected->value);

    app(CompleteVacancyTransferAction::class)->execute($application, ['effective_date' => '2026-06-01'], $user);

    expect($old->fresh()->effective_to->toDateString())->toBe('2026-05-31');
});
