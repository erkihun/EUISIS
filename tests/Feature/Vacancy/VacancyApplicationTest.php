<?php

declare(strict_types=1);

use App\Enums\EstablishmentStatus;
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
use App\Models\VacancyAnnouncement;
use App\Models\VacancyAnnouncementPosition;
use App\Models\VacancyApplication;
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
