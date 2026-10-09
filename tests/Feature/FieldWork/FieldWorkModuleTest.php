<?php

declare(strict_types=1);

use App\Contracts\EmployeeLeaveProvider;
use App\Enums\FieldWorkLocationEventType;
use App\Enums\FieldWorkLocationValidation;
use App\Enums\FieldWorkStatus;
use App\Enums\FieldWorkSupervisorResolution;
use App\Models\AuditLog;
use App\Models\DailyActivityReviewerAssignment;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\FieldWorkLocationEvent;
use App\Models\FieldWorkParticipant;
use App\Models\FieldWorkRequest;
use App\Models\FieldWorkType;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Notifications\FieldWorkNotification;
use App\Services\FieldWork\FieldWorkAttendanceService;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Support\DailyActivity\DailyActivityRoles;
use App\Support\FieldWork\FieldWorkRoles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Field Work Management (docs/field-work-management.md)
|--------------------------------------------------------------------------
|
| "Now" is Monday 12 October 2026, 08:00 in Addis Ababa (05:00 UTC). The
| standard request runs 09:00–15:00 the same day (a partial day).
|
| The supervisor is whoever the explicit line-manager (reviewer) assignment
| resolves to — never "any user with a manager role".
*/

const FW_DAY = '2026-10-12';

/**
 * Freeze the clock at a local Addis Ababa time, expressed in the app's own
 * timezone: Carbon 3 parses with the test clock's timezone, so an Addis-zoned
 * test clock would re-read stored values as Addis wall time.
 */
function fwAt(string $time, string $date = FW_DAY): void
{
    Carbon::setTestNow(Carbon::parse("{$date} {$time}:00", 'Africa/Addis_Ababa')->setTimezone(date_default_timezone_get()));
}

/** @param array<int, string> $permissions */
function fwUser(string $role, array $permissions, ?string $email = null): User
{
    $roleModel = Role::findOrCreate($role, 'web');
    foreach ($permissions as $permission) {
        $roleModel->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $user = User::factory()->create(array_filter(['email' => $email, 'status' => 'active']));
    $user->assignRole($roleModel);
    $user->forgetCachedPermissions();

    return $user->fresh();
}

function fwEmployee(string $number, string $email, Organization $org, OrganizationUnit $unit, Position $position): Employee
{
    $employee = Employee::query()->create([
        'employee_number' => $number,
        'first_name' => $number,
        'last_name' => 'Employee',
        'full_name' => "{$number} Employee",
        'email' => $email,
        'status' => 'active',
    ]);

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $org->id,
        'organization_unit_id' => $unit->id,
        'position_id' => $position->id,
        'assignment_status' => 'active',
        'effective_from' => '2026-01-01',
        'is_current' => true,
    ]);

    $employee->update(['current_assignment_id' => $assignment->id]);

    return $employee->fresh();
}

/** @param array<string, mixed> $overrides */
function fwPayload(array $overrides = []): array
{
    return [
        'action' => 'draft',
        'field_work_type_id' => test()->type->id,
        'purpose' => 'Inspect the records office of the host bureau',
        'destination_type' => 'registered_organization',
        'destination_organization_id' => test()->otherOrg->id,
        'start_date' => FW_DAY,
        'start_time' => '09:00',
        'return_date' => FW_DAY,
        'return_time' => '15:00',
        ...$overrides,
    ];
}

function fwCreate(User $user, array $overrides = []): FieldWorkRequest
{
    test()->actingAs($user)->post(route('employee.field-work.store'), fwPayload($overrides))->assertSessionHasNoErrors();

    return FieldWorkRequest::query()->latest('created_at')->latest('id')->firstOrFail();
}

/** @return array<string, mixed> */
function fwGps(float $lat = 9.0300000, float $lng = 38.7400000, ?float $accuracy = 15.0): array
{
    return ['latitude' => $lat, 'longitude' => $lng, 'accuracy' => $accuracy, 'captured_at' => now()->toIso8601String()];
}

beforeEach(function (): void {
    fwAt('08:00');
    Notification::fake();

    $orgType = OrganizationType::query()->create(['code' => 'FW-TYPE', 'name_en' => 'Bureau']);
    $this->org = Organization::query()->create(['organization_type_id' => $orgType->id, 'code' => 'FW-ORG', 'name_en' => 'Civil Service Bureau', 'name_am' => 'ሲቪል ሰርቪስ ቢሮ', 'status' => 'active']);
    $this->otherOrg = Organization::query()->create(['organization_type_id' => $orgType->id, 'code' => 'FW-OTHER', 'name_en' => 'Health Bureau', 'status' => 'active']);

    $this->unit = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'code' => 'FW-HR', 'name_en' => 'HR Directorate', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->subUnit = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'parent_unit_id' => $this->unit->id, 'code' => 'FW-HR-REC', 'name_en' => 'Records Team', 'unit_type' => 'team', 'status' => 'active']);
    $this->otherUnit = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'code' => 'FW-FIN', 'name_en' => 'Finance Directorate', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->foreignUnit = OrganizationUnit::query()->create(['organization_id' => $this->otherOrg->id, 'code' => 'FW-HB', 'name_en' => 'Health HR', 'unit_type' => 'directorate', 'status' => 'active']);

    $this->position = Position::query()->create(['organization_id' => $this->org->id, 'organization_unit_id' => $this->subUnit->id, 'job_position_code' => 'FW-P1', 'title_en' => 'HR Officer', 'is_active' => true]);
    $this->financePosition = Position::query()->create(['organization_id' => $this->org->id, 'organization_unit_id' => $this->otherUnit->id, 'job_position_code' => 'FW-P2', 'title_en' => 'Accountant', 'is_active' => true]);
    $this->foreignPosition = Position::query()->create(['organization_id' => $this->otherOrg->id, 'organization_unit_id' => $this->foreignUnit->id, 'job_position_code' => 'FW-P3', 'title_en' => 'Clerk', 'is_active' => true]);

    $this->type = FieldWorkType::query()->where('code', 'INSPECTION')->firstOrFail();

    $this->employeeA = fwEmployee('FW-A', 'a@fw.test', $this->org, $this->subUnit, $this->position);
    $this->employeeB = fwEmployee('FW-B', 'b@fw.test', $this->org, $this->subUnit, $this->position);
    $this->employeeF = fwEmployee('FW-F', 'f@fw.test', $this->otherOrg, $this->foreignUnit, $this->foreignPosition);

    $this->userA = fwUser(DailyActivityRoles::EMPLOYEE_ROLE, FieldWorkRoles::EMPLOYEE_PERMISSIONS, 'a@fw.test');
    $this->userB = fwUser(DailyActivityRoles::EMPLOYEE_ROLE, FieldWorkRoles::EMPLOYEE_PERMISSIONS, 'b@fw.test');
    $this->userF = fwUser(DailyActivityRoles::EMPLOYEE_ROLE, FieldWorkRoles::EMPLOYEE_PERMISSIONS, 'f@fw.test');

    // Immediate supervisor of the HR directorate and everything beneath it.
    $this->supervisor = fwUser(DailyActivityRoles::REVIEWER_ROLE, FieldWorkRoles::SUPERVISOR_PERMISSIONS);
    DailyActivityReviewerAssignment::query()->create(['reviewer_user_id' => $this->supervisor->id, 'organization_id' => $this->org->id, 'organization_unit_id' => $this->unit->id, 'include_sub_units' => true, 'is_active' => true]);

    // A line manager of a different unit, with the same permissions.
    $this->financeManager = fwUser('FW Finance Manager', FieldWorkRoles::SUPERVISOR_PERMISSIONS);
    DailyActivityReviewerAssignment::query()->create(['reviewer_user_id' => $this->financeManager->id, 'organization_id' => $this->org->id, 'organization_unit_id' => $this->otherUnit->id, 'include_sub_units' => true, 'is_active' => true]);

    // Institution HR oversight of the Civil Service Bureau only.
    $this->orgHr = fwUser('FW Org HR', FieldWorkRoles::HR_OVERSIGHT_PERMISSIONS);
    UserOrganizationScope::query()->create(['user_id' => $this->orgHr->id, 'organization_id' => $this->org->id, 'scope_type' => 'self', 'is_active' => true]);

    // HR of the Health Bureau: the DESTINATION of the standard request.
    $this->destinationHr = fwUser('FW Destination HR', FieldWorkRoles::HR_OVERSIGHT_PERMISSIONS);
    UserOrganizationScope::query()->create(['user_id' => $this->destinationHr->id, 'organization_id' => $this->otherOrg->id, 'scope_type' => 'self', 'is_active' => true]);

    app(OrganizationScopeService::class)->clearCache();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

// ── End to end ──────────────────────────────────────────────────────────────

test('E2E: request → supervisor approval → GPS check-in → attendance → check-out → completion → dashboard and register, assignment untouched', function (): void {
    $assignmentsBefore = EmployeeAssignment::query()->orderBy('id')->get()->map->only(['id', 'organization_id', 'organization_unit_id', 'position_id', 'is_current', 'effective_to', 'updated_at'])->all();

    // 1. Employee creates the request; placement is captured server-side.
    $request = fwCreate($this->userA, ['expected_latitude' => '9.0300000', 'expected_longitude' => '38.7400000', 'geofence_radius_m' => '200']);
    expect($request->status)->toBe(FieldWorkStatus::Draft)
        ->and($request->requester_employee_id)->toBe($this->employeeA->id)
        ->and($request->employee_assignment_id)->toBe($this->employeeA->current_assignment_id)
        ->and($request->organization_id)->toBe($this->org->id)
        ->and($request->organization_unit_id)->toBe($this->subUnit->id)
        ->and($request->position_id)->toBe($this->position->id)
        ->and($request->context_snapshot['organization']['name_en'])->toBe('Civil Service Bureau')
        ->and($request->schedule_type->value)->toBe('partial_day')
        ->and($request->participants()->count())->toBe(1);

    // 2. Submit: the immediate supervisor is resolved and notified.
    $this->actingAs($this->userA)->post(route('employee.field-work.submit', $request))->assertSessionHasNoErrors();
    $request->refresh();
    expect($request->status)->toBe(FieldWorkStatus::PendingSupervisorApproval)
        ->and($request->supervisor_user_id)->toBe($this->supervisor->id)
        ->and($request->supervisor_resolution)->toBe(FieldWorkSupervisorResolution::Resolved);
    Notification::assertSentTo($this->supervisor, FieldWorkNotification::class, fn (FieldWorkNotification $n): bool => $n->kind === 'approval_required');

    // 3. Supervisor approves.
    $this->actingAs($this->supervisor)->post(route('field-work.requests.approve', $request), ['reason' => 'Go ahead'])->assertSessionHasNoErrors();
    expect($request->refresh()->status)->toBe(FieldWorkStatus::Approved)
        ->and($request->decided_by)->toBe($this->supervisor->id);
    Notification::assertSentTo($this->userA, FieldWorkNotification::class, fn (FieldWorkNotification $n): bool => $n->kind === 'approved');

    // 4. GPS check-in at 09:05 inside the geofence.
    fwAt('09:05');
    $this->actingAs($this->userA)->post(route('employee.field-work.check-in', $request), fwGps(9.0301, 38.7401))->assertSessionHasNoErrors();
    $request->refresh();
    $checkIn = FieldWorkLocationEvent::query()->where('event_type', FieldWorkLocationEventType::FieldCheckIn->value)->sole();
    expect($request->status)->toBe(FieldWorkStatus::InField)
        ->and($checkIn->validation_status)->toBe(FieldWorkLocationValidation::WithinExpectedArea)
        ->and($checkIn->distance_m)->toBeLessThan(200.0);

    // 5. Attendance sees official field work with the source reference, for the field hours only.
    fwAt('11:00');
    $interval = app(FieldWorkAttendanceService::class)->at($this->employeeA->id, now());
    expect($interval)->not->toBeNull()
        ->and($interval['status'])->toBe('OFFICIAL_FIELD_WORK')
        ->and($interval['reference_number'])->toBe($request->reference_number)
        ->and(app(FieldWorkAttendanceService::class)->at($this->employeeA->id, Carbon::parse(FW_DAY.' 16:30', 'Africa/Addis_Ababa')))->toBeNull();

    // 6. GPS check-out: a separate event; the check-in row is untouched.
    fwAt('14:40');
    $this->actingAs($this->userA)->post(route('employee.field-work.check-out', $request), fwGps(9.0302, 38.7402))->assertSessionHasNoErrors();
    expect(FieldWorkLocationEvent::query()->count())->toBe(2)
        ->and($checkIn->fresh()->latitude)->toBe(9.0301);

    // 7. Requester completes with the actual return.
    $this->actingAs($this->userA)->post(route('employee.field-work.complete', $request), [
        'actual_return_date' => FW_DAY, 'actual_return_time' => '14:40', 'completion_note' => 'Inspection done, report filed.', 'follow_up_required' => false,
    ])->assertSessionHasNoErrors();
    $request->refresh();
    expect($request->status)->toBe(FieldWorkStatus::Completed)
        ->and($request->actual_return_at->setTimezone('Africa/Addis_Ababa')->format('H:i'))->toBe('14:40');

    // 8. Dashboard and register reflect it.
    $this->actingAs($this->supervisor)->get(route('field-work.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('FieldWork/Dashboard')->where('figures.completed_today', 1)->where('figures.in_field', 0));
    $this->actingAs($this->orgHr)->get(route('field-work.requests.index', ['status' => 'completed']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('requests.meta.total', 1)->where('requests.data.0.reference_number', $request->reference_number));
    $this->actingAs($this->userA)->get(route('employee.field-work.history'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('requests.meta.total', 1));

    // 9. Field work never touched an employee assignment.
    expect(EmployeeAssignment::query()->orderBy('id')->get()->map->only(['id', 'organization_id', 'organization_unit_id', 'position_id', 'is_current', 'effective_to', 'updated_at'])->all())->toEqual($assignmentsBefore)
        ->and(AuditLog::query()->where('event_type', 'field_work.completed')->exists())->toBeTrue();
});

// ── Identity and forged input ───────────────────────────────────────────────

test('the requester, placement, status and supervisor come from the server, never the browser', function (): void {
    $request = fwCreate($this->userA, [
        'requester_employee_id' => $this->employeeB->id,
        'employee_id' => $this->employeeB->id,
        'organization_id' => $this->otherOrg->id,
        'employee_assignment_id' => $this->employeeF->current_assignment_id,
        'status' => 'approved',
        'supervisor_user_id' => $this->financeManager->id,
    ]);

    expect($request->requester_employee_id)->toBe($this->employeeA->id)
        ->and($request->organization_id)->toBe($this->org->id)
        ->and($request->employee_assignment_id)->toBe($this->employeeA->current_assignment_id)
        ->and($request->status)->toBe(FieldWorkStatus::Draft)
        ->and($request->supervisor_user_id)->toBeNull();
});

test('an account without an employee record cannot request field work', function (): void {
    $staff = fwUser('FW Staff', FieldWorkRoles::EMPLOYEE_PERMISSIONS);

    $this->actingAs($staff)->post(route('employee.field-work.store'), fwPayload())->assertSessionHasErrors('employee');
    expect(FieldWorkRequest::query()->count())->toBe(0);
});

// ── Supervisor resolution and approval ─────────────────────────────────────

test('without a line-manager assignment the request is flagged SUPERVISOR_NOT_RESOLVED and a random manager cannot approve it', function (): void {
    DailyActivityReviewerAssignment::query()->delete();
    $request = fwCreate($this->userA, ['action' => 'submit']);

    expect($request->refresh()->status)->toBe(FieldWorkStatus::PendingSupervisorApproval)
        ->and($request->supervisor_user_id)->toBeNull()
        ->and($request->supervisor_resolution)->toBe(FieldWorkSupervisorResolution::NotResolved);

    $this->actingAs($this->financeManager)->post(route('field-work.requests.approve', $request))->assertForbidden();
    expect($request->refresh()->status)->toBe(FieldWorkStatus::PendingSupervisorApproval);
});

test('only the resolved supervisor decides: not another unit\'s manager, not the employee, not Super Admin', function (): void {
    $request = fwCreate($this->userA, ['action' => 'submit']);

    $this->actingAs($this->financeManager)->post(route('field-work.requests.approve', $request))->assertForbidden();

    // An employee who also holds approval permission still cannot approve their own request.
    $this->userA->givePermissionTo(Permission::findOrCreate('field_work.approve', 'web'));
    $this->actingAs($this->userA->fresh())->post(route('field-work.requests.approve', $request))->assertForbidden();

    $superAdmin = fwUser('Super Admin', []);
    $this->actingAs($superAdmin)->post(route('field-work.requests.approve', $request))->assertForbidden();

    expect($request->refresh()->status)->toBe(FieldWorkStatus::PendingSupervisorApproval);
});

test('return and reject require a reason; a returned request can be corrected and resubmitted', function (): void {
    $request = fwCreate($this->userA, ['action' => 'submit']);

    $this->actingAs($this->supervisor)->post(route('field-work.requests.return', $request), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->supervisor)->post(route('field-work.requests.reject', $request), [])->assertSessionHasErrors('reason');

    $this->actingAs($this->supervisor)->post(route('field-work.requests.return', $request), ['reason' => 'Add the destination unit'])->assertSessionHasNoErrors();
    expect($request->refresh()->status)->toBe(FieldWorkStatus::ReturnedForCorrection);
    Notification::assertSentTo($this->userA, FieldWorkNotification::class, fn (FieldWorkNotification $n): bool => $n->kind === 'returned');

    $this->actingAs($this->userA)->put(route('employee.field-work.update', $request), fwPayload(['action' => 'submit', 'destination_organization_unit_id' => $this->foreignUnit->id]))->assertSessionHasNoErrors();
    expect($request->refresh()->status)->toBe(FieldWorkStatus::PendingSupervisorApproval)
        ->and($request->submission_count)->toBe(2)
        ->and($request->destination_organization_unit_id)->toBe($this->foreignUnit->id);
});

test('only one terminal decision commits: approve twice, or reject after approve, is refused', function (): void {
    $request = fwCreate($this->userA, ['action' => 'submit']);

    $this->actingAs($this->supervisor)->post(route('field-work.requests.approve', $request))->assertSessionHasNoErrors();
    $this->actingAs($this->supervisor)->post(route('field-work.requests.approve', $request))->assertForbidden();
    $this->actingAs($this->supervisor)->post(route('field-work.requests.reject', $request), ['reason' => 'Changed my mind'])->assertForbidden();

    expect($request->refresh()->status)->toBe(FieldWorkStatus::Approved)
        ->and($request->histories()->where('action', 'approved')->count())->toBe(1);
});

test('a double submit records one submission', function (): void {
    $request = fwCreate($this->userA);

    $this->actingAs($this->userA)->post(route('employee.field-work.submit', $request))->assertSessionHasNoErrors();
    $this->actingAs($this->userA)->post(route('employee.field-work.submit', $request))->assertForbidden();

    expect($request->refresh()->submission_count)->toBe(1);
});

// ── Validation ──────────────────────────────────────────────────────────────

test('destination-specific and schedule validation is enforced server-side', function (): void {
    $this->actingAs($this->userA)->post(route('employee.field-work.store'), fwPayload(['destination_organization_id' => null]))->assertSessionHasErrors('destination_organization_id');
    $this->actingAs($this->userA)->post(route('employee.field-work.store'), fwPayload(['destination_type' => 'external_organization', 'destination_organization_id' => null]))->assertSessionHasErrors(['external_organization_name', 'destination_address']);
    $this->actingAs($this->userA)->post(route('employee.field-work.store'), fwPayload(['destination_type' => 'field_site']))->assertSessionHasErrors(['site_name', 'destination_address']);
    $this->actingAs($this->userA)->post(route('employee.field-work.store'), fwPayload(['return_time' => '08:00']))->assertSessionHasErrors('return_time');
    // Hierarchy-valid: a unit of another organization is refused.
    $this->actingAs($this->userA)->post(route('employee.field-work.store'), fwPayload(['destination_organization_unit_id' => $this->unit->id]))->assertSessionHasErrors('destination_organization_unit_id');

    expect(FieldWorkRequest::query()->count())->toBe(0);
});

test('an external organization is stored on the request and never registered as master data', function (): void {
    $organizations = Organization::query()->count();
    $request = fwCreate($this->userA, ['destination_type' => 'external_organization', 'destination_organization_id' => null, 'external_organization_name' => 'Ethio Telecom Region 3', 'destination_address' => 'Bole, Addis Ababa']);

    expect($request->external_organization_name)->toBe('Ethio Telecom Region 3')
        ->and($request->destination_organization_id)->toBeNull()
        ->and(Organization::query()->count())->toBe($organizations);
});

test('a deactivated field work type cannot be chosen', function (): void {
    $this->type->update(['is_active' => false]);

    $this->actingAs($this->userA)->post(route('employee.field-work.store'), fwPayload())->assertSessionHasErrors('field_work_type_id');
});

// ── Team field work ─────────────────────────────────────────────────────────

test('team field work stores participants relationally, each with their own snapshot and check-in', function (): void {
    $request = fwCreate($this->userA, ['participant_employee_ids' => [$this->employeeB->id]]);

    expect($request->is_team)->toBeTrue()
        ->and(FieldWorkParticipant::query()->where('field_work_request_id', $request->id)->pluck('employee_id')->sort()->values()->all())
        ->toBe(collect([$this->employeeA->id, $this->employeeB->id])->sort()->values()->all());

    $this->actingAs($this->userA)->post(route('employee.field-work.submit', $request));
    $this->actingAs($this->supervisor)->post(route('field-work.requests.approve', $request));

    fwAt('09:00');
    $this->actingAs($this->userB)->post(route('employee.field-work.check-in', $request), fwGps())->assertSessionHasNoErrors();
    expect(FieldWorkParticipant::query()->where('employee_id', $this->employeeB->id)->value('checked_in_at'))->not->toBeNull()
        ->and(FieldWorkParticipant::query()->where('employee_id', $this->employeeA->id)->value('checked_in_at'))->toBeNull();

    // B sees the request in their own portal; F (not a participant) cannot reach it.
    $this->actingAs($this->userB)->get(route('employee.field-work.show', $request))->assertOk();
    $this->actingAs($this->userF)->post(route('employee.field-work.check-in', $request), fwGps())->assertForbidden();
});

test('participants must be active colleagues of the same organization, with no duplicates', function (): void {
    $this->actingAs($this->userA)->post(route('employee.field-work.store'), fwPayload(['participant_employee_ids' => [$this->employeeF->id]]))->assertSessionHasErrors('participant_employee_ids');
    $this->actingAs($this->userA)->post(route('employee.field-work.store'), fwPayload(['participant_employee_ids' => [$this->employeeB->id, $this->employeeB->id]]))->assertSessionHasErrors('participant_employee_ids.0');

    $this->employeeB->update(['status' => 'terminated']);
    $this->actingAs($this->userA)->post(route('employee.field-work.store'), fwPayload(['participant_employee_ids' => [$this->employeeB->id]]))->assertSessionHasErrors('participant_employee_ids');

    expect(FieldWorkRequest::query()->count())->toBe(0);
});

test('overlapping field work for the same employee is reported as a conflict, never overwritten', function (): void {
    fwCreate($this->userA, ['action' => 'submit']);

    $this->actingAs($this->userA)->post(route('employee.field-work.store'), fwPayload(['action' => 'submit', 'start_time' => '13:00', 'return_time' => '17:00']))
        ->assertSessionHasErrors('conflicts');

    expect(FieldWorkRequest::query()->where('status', 'pending_supervisor_approval')->count())->toBe(1);
});

test('approved full-day leave from the leave provider blocks submission', function (): void {
    app()->bind(EmployeeLeaveProvider::class, fn () => new class($this->employeeA->id) implements EmployeeLeaveProvider
    {
        public function __construct(private string $employeeId) {}

        public function fullDayLeaveDates(array $employeeIds, Carbon $from, Carbon $to): array
        {
            return in_array($this->employeeId, $employeeIds, true) ? [$this->employeeId => [FW_DAY => true]] : [];
        }
    });

    $this->actingAs($this->userA)->post(route('employee.field-work.store'), fwPayload(['action' => 'submit']))->assertSessionHasErrors('conflicts');
});

// ── GPS ─────────────────────────────────────────────────────────────────────

function fwApproved(): FieldWorkRequest
{
    $request = fwCreate(test()->userA, ['action' => 'submit', 'expected_latitude' => '9.0300000', 'expected_longitude' => '38.7400000', 'geofence_radius_m' => '200']);
    test()->actingAs(test()->supervisor)->post(route('field-work.requests.approve', $request))->assertSessionHasNoErrors();

    return $request->refresh();
}

test('a repeated check-in is idempotent: one immutable event, the second tap changes nothing', function (): void {
    $request = fwApproved();
    fwAt('09:00');

    $this->actingAs($this->userA)->post(route('employee.field-work.check-in', $request), fwGps(9.0301, 38.7401))->assertSessionHasNoErrors();
    // A retry (double tap, flaky network) is answered from the recorded event.
    $this->actingAs($this->userA)->post(route('employee.field-work.check-in', $request), fwGps(9.5, 39.0))
        ->assertSessionHasNoErrors()->assertSessionHas('success', __('field-work.flash.already_checked_in'));

    expect(FieldWorkLocationEvent::query()->count())->toBe(1)
        ->and(FieldWorkLocationEvent::query()->sole()->latitude)->toBe(9.0301);
    expect(fn () => FieldWorkLocationEvent::query()->sole()->forceFill(['latitude' => 1.0])->save())->toThrow(LogicException::class)
        ->and(fn () => FieldWorkLocationEvent::query()->sole()->delete())->toThrow(LogicException::class);
});

test('the server validates the GPS reading and decides the geofence status itself', function (): void {
    $request = fwApproved();
    fwAt('09:00');

    $this->actingAs($this->userA)->post(route('employee.field-work.check-in', $request), fwGps(95.0, 38.74))->assertSessionHasErrors('latitude');
    $this->actingAs($this->userA)->post(route('employee.field-work.check-in', $request), [...fwGps(), 'captured_at' => now()->subHour()->toIso8601String()])->assertSessionHasErrors('captured_at');

    // ~5.5 km away, with a browser-claimed verdict that is ignored.
    $this->actingAs($this->userA)->post(route('employee.field-work.check-in', $request), [...fwGps(9.08, 38.74), 'validation_status' => 'within_expected_area', 'distance_m' => 1])->assertSessionHasNoErrors();
    $event = FieldWorkLocationEvent::query()->sole();
    expect($event->validation_status)->toBe(FieldWorkLocationValidation::OutsideExpectedArea)
        ->and($event->distance_m)->toBeGreaterThan(5000.0);
});

test('a low-accuracy reading is flagged LOW_ACCURACY; without an expected point it is CANNOT_VALIDATE', function (): void {
    $request = fwApproved();
    fwAt('09:00');
    $this->actingAs($this->userA)->post(route('employee.field-work.check-in', $request), fwGps(9.0301, 38.7401, 800.0));
    expect(FieldWorkLocationEvent::query()->sole()->validation_status)->toBe(FieldWorkLocationValidation::LowAccuracy);

    $plain = fwCreate($this->userB, ['action' => 'submit']);
    $this->actingAs($this->supervisor)->post(route('field-work.requests.approve', $plain));
    $this->actingAs($this->userB)->post(route('employee.field-work.check-in', $plain), fwGps());
    expect(FieldWorkLocationEvent::query()->where('field_work_request_id', $plain->id)->sole()->validation_status)->toBe(FieldWorkLocationValidation::CannotValidate);
});

test('check-in is refused before approval, and check-out before check-in', function (): void {
    $draft = fwCreate($this->userA);
    $this->actingAs($this->userA)->post(route('employee.field-work.check-in', $draft), fwGps())->assertSessionHasErrors('status');

    $request = fwApproved();
    $this->actingAs($this->userA)->post(route('employee.field-work.check-out', $request), fwGps())->assertSessionHasErrors('status');
    expect(FieldWorkLocationEvent::query()->count())->toBe(0);
});

test('exact coordinates are hidden from supervisors and HR, shown only with view_precise, and that view is audited', function (): void {
    $request = fwApproved();
    fwAt('09:00');
    $this->actingAs($this->userA)->post(route('employee.field-work.check-in', $request), fwGps(9.0301, 38.7401));

    $this->actingAs($this->supervisor)->get(route('field-work.requests.show', $request))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('fieldWork.location_visibility', 'status')
            ->where('fieldWork.participants.0.events.0.validation_status', 'within_expected_area')
            ->missing('fieldWork.participants.0.events.0.latitude')
            ->where('fieldWork.destination_details.expected_point', null));

    $auditor = fwUser('FW Location Auditor', [...FieldWorkRoles::HR_OVERSIGHT_PERMISSIONS, 'field_work.location.view_precise']);
    UserOrganizationScope::query()->create(['user_id' => $auditor->id, 'organization_id' => $this->org->id, 'scope_type' => 'self', 'is_active' => true]);
    app(OrganizationScopeService::class)->clearCache();

    $this->actingAs($auditor)->get(route('field-work.requests.show', $request))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('fieldWork.location_visibility', 'precise')
            ->where('fieldWork.participants.0.events.0.latitude', 9.0301));
    expect(AuditLog::query()->where('event_type', 'field_work.precise_location_viewed')->where('actor_user_id', $auditor->id)->exists())->toBeTrue()
        // Coordinates never enter the general audit log.
        ->and(AuditLog::query()->where('event_type', 'field_work.checked_in')->sole()->new_values)->not->toHaveKey('latitude');
});

test('cancelling after someone checked in is refused; before, it is allowed', function (): void {
    $request = fwApproved();
    fwAt('09:00');
    $this->actingAs($this->userA)->post(route('employee.field-work.check-in', $request), fwGps());
    $this->actingAs($this->userA)->post(route('employee.field-work.cancel', $request))->assertForbidden();

    $other = fwCreate($this->userB, ['action' => 'submit']);
    $this->actingAs($this->userB)->post(route('employee.field-work.cancel', $other), ['reason' => 'Trip postponed'])->assertSessionHasNoErrors();
    expect($other->refresh()->status)->toBe(FieldWorkStatus::Cancelled);
});

// ── Monitoring ──────────────────────────────────────────────────────────────

test('approved work with no check-in and open work past its return are surfaced, without inventing a return', function (): void {
    $request = fwApproved();

    fwAt('10:00');
    expect($request->refresh()->monitoringFlag()?->value)->toBe('check_in_missing');

    $this->actingAs($this->userA)->post(route('employee.field-work.check-in', $request), fwGps());
    fwAt('16:00');
    expect($request->refresh()->monitoringFlag()?->value)->toBe('overdue')
        ->and($request->actual_return_at)->toBeNull();

    $this->actingAs($this->supervisor)->get(route('field-work.overdue.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('requests.meta.total', 1)->where('requests.data.0.monitoring_flag', 'overdue'));
    $this->actingAs($this->supervisor)->get(route('field-work.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('figures.overdue', 1)->where('figures.in_field', 1));
});

// ── Scope and IDOR ──────────────────────────────────────────────────────────

test('another employee cannot open, edit or act on someone else\'s request', function (): void {
    $request = fwCreate($this->userA);

    $this->actingAs($this->userB)->get(route('employee.field-work.show', $request))->assertForbidden();
    $this->actingAs($this->userB)->get(route('employee.field-work.edit', $request))->assertForbidden();
    $this->actingAs($this->userB)->put(route('employee.field-work.update', $request), fwPayload())->assertForbidden();
    $this->actingAs($this->userB)->post(route('employee.field-work.submit', $request))->assertForbidden();
    $this->actingAs($this->userB)->get(route('field-work.requests.show', $request))->assertForbidden();
});

test('HR oversight is scoped to the source organization; the destination organization gains no access', function (): void {
    $request = fwCreate($this->userA, ['action' => 'submit']);

    $this->actingAs($this->orgHr)->get(route('field-work.requests.show', $request))->assertOk();
    $this->actingAs($this->destinationHr)->get(route('field-work.requests.show', $request))->assertForbidden();
    $this->actingAs($this->destinationHr)->get(route('field-work.requests.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('requests.meta.total', 0));
    // HR oversight is read-only: it cannot decide.
    $this->actingAs($this->orgHr)->post(route('field-work.requests.approve', $request))->assertForbidden();
});

test('a manager sees only their own team in lists and the approval queue', function (): void {
    fwCreate($this->userA, ['action' => 'submit']);

    $this->actingAs($this->supervisor)->get(route('field-work.approvals.index'))
        ->assertInertia(fn (Assert $page) => $page->where('requests.meta.total', 1));
    $this->actingAs($this->financeManager)->get(route('field-work.approvals.index'))
        ->assertInertia(fn (Assert $page) => $page->where('requests.meta.total', 0));
    $this->actingAs($this->userA)->get(route('field-work.requests.index'))->assertForbidden();
});

test('management pages and type configuration require their own permissions', function (): void {
    foreach (['field-work.dashboard', 'field-work.requests.index', 'field-work.approvals.index', 'field-work.team.index', 'field-work.overdue.index', 'field-work.types.index'] as $name) {
        $this->actingAs($this->userA)->get(route($name))->assertForbidden();
    }

    $this->actingAs($this->supervisor)->post(route('field-work.types.store'), ['code' => 'X', 'name_en' => 'X', 'is_active' => true])->assertForbidden();

    $configurator = fwUser('FW Configurator', ['field_work.manage_types']);
    $this->actingAs($configurator)->get(route('field-work.types.index'))->assertOk();
    $this->actingAs($configurator)->post(route('field-work.types.store'), ['code' => 'audit-visit', 'name_en' => 'Audit Visit', 'name_am' => 'የኦዲት ጉብኝት', 'is_active' => true])->assertSessionHasNoErrors();
    expect(FieldWorkType::query()->where('code', 'AUDIT-VISIT')->exists())->toBeTrue();
});

// ── Historical integrity ────────────────────────────────────────────────────

test('a later transfer does not rewrite the request\'s placement, supervisor scope or history', function (): void {
    $request = fwCreate($this->userA, ['action' => 'submit']);

    // A moves to the Health Bureau after submitting.
    EmployeeAssignment::query()->whereKey($this->employeeA->current_assignment_id)->update(['is_current' => false, 'effective_to' => '2026-10-11']);
    $new = EmployeeAssignment::query()->create(['employee_id' => $this->employeeA->id, 'organization_id' => $this->otherOrg->id, 'organization_unit_id' => $this->foreignUnit->id, 'position_id' => $this->foreignPosition->id, 'assignment_status' => 'active', 'effective_from' => '2026-10-12', 'is_current' => true]);
    $this->employeeA->update(['current_assignment_id' => $new->id]);

    $request->refresh();
    expect($request->organization_id)->toBe($this->org->id)
        ->and($request->organization_unit_id)->toBe($this->subUnit->id)
        ->and($request->context_snapshot['organization']['name_en'])->toBe('Civil Service Bureau');

    // The supervisor of the placement it was requested from still decides it; HR of the source still sees it.
    $this->actingAs($this->supervisor)->post(route('field-work.requests.approve', $request))->assertSessionHasNoErrors();
    $this->actingAs($this->orgHr)->get(route('field-work.requests.show', $request))->assertOk();
});

// ── Sidebar (docs/field-work-navigation.md) ────────────────────────────────

test('the admin sidebar has exactly one correctly spelled Field Work Management category with permission-gated real routes', function (): void {
    $sidebar = (string) file_get_contents(resource_path('js/Components/AppSidebar.tsx'));
    $en = (string) file_get_contents(resource_path('js/i18n/en/navigation.ts'));
    $am = (string) file_get_contents(resource_path('js/i18n/am/navigation.ts'));

    expect($en)->toContain("groupFieldWorkManagement: 'Field Work Management'")
        ->and($am)->toContain("groupFieldWorkManagement: 'የመስክ ሥራ አስተዳደር'")
        ->and(substr_count($sidebar, "key: 'fieldWorkManagement'"))->toBe(1)
        ->and(substr_count($sidebar, "labelKey: 'nav.groupFieldWorkManagement'"))->toBe(1)
        ->and($sidebar)->toMatch("/'dailyActivity', 'fieldWorkManagement', 'transferManagement'/");

    foreach (['Fild Work', 'Field Work Amangment', 'Field Work Managment', 'Fieldwork Management'] as $typo) {
        expect($en.$am.$sidebar)->not->toContain($typo);
    }

    // The admin group: every child is a real route and carries a permission gate.
    $start = strpos($sidebar, "key: 'fieldWorkManagement'");
    $group = substr($sidebar, $start, strpos($sidebar, "key: 'transferManagement'") - $start);
    preg_match_all("/\\{ routeName: '([^']+)'[^}]*\\}/", $group, $items);
    expect($items[1])->toBe(['field-work.dashboard', 'field-work.requests.index', 'field-work.approvals.index', 'field-work.team.index', 'field-work.overdue.index', 'field-work.types.index']);
    foreach ($items[0] as $index => $item) {
        expect(Route::has($items[1][$index]))->toBeTrue()
            ->and($item)->toMatch('/(permission|anyPermission): /');
    }
    // My Portal field work is never listed in the admin group.
    expect($group)->not->toContain('employee.field-work.');

    // My Portal has its own group with the employee's own pages.
    expect(substr_count($sidebar, "routeName: 'employee.field-work.index'"))->toBe(1)
        ->and(substr_count($sidebar, "routeName: 'employee.field-work.create'"))->toBe(1)
        ->and(substr_count($sidebar, "routeName: 'employee.field-work.history'"))->toBe(1);
});

test('each sidebar entry opens for the users it is shown to and is refused to the rest', function (): void {
    // Supervisor: dashboard, requests, approvals, team, overdue — not types.
    foreach (['field-work.dashboard', 'field-work.requests.index', 'field-work.approvals.index', 'field-work.team.index', 'field-work.overdue.index'] as $name) {
        $this->actingAs($this->supervisor)->get(route($name))->assertOk();
    }
    $this->actingAs($this->supervisor)->get(route('field-work.types.index'))->assertForbidden();

    // HR oversight: no approvals or team pages (their gates are approve/view_team).
    $this->actingAs($this->orgHr)->get(route('field-work.dashboard'))->assertOk();
    $this->actingAs($this->orgHr)->get(route('field-work.approvals.index'))->assertForbidden();
    $this->actingAs($this->orgHr)->get(route('field-work.team.index'))->assertForbidden();

    // Employee: My Portal pages only.
    foreach (['employee.field-work.index', 'employee.field-work.create', 'employee.field-work.history'] as $name) {
        $this->actingAs($this->userA)->get(route($name))->assertOk();
    }
});

test('the English and Amharic module translations define the same keys', function (): void {
    $keys = static function (string $file): array {
        preg_match_all('/^\s*([a-zA-Z_]+):/m', (string) file_get_contents(resource_path("js/i18n/{$file}")), $matches);

        return $matches[1];
    };

    expect($keys('am/fieldWork.ts'))->toBe($keys('en/fieldWork.ts'))
        ->and(array_keys(require lang_path('am/field-work.php')))->toBe(array_keys(require lang_path('en/field-work.php')))
        ->and(array_keys((require lang_path('am/field-work.php'))['errors']))->toBe(array_keys((require lang_path('en/field-work.php'))['errors']));
});

test('Team Field Work lists only the user\'s own team, even when they also hold organization oversight', function (): void {
    fwEmployee('FW-C', 'c@fw.test', $this->org, $this->otherUnit, $this->financePosition);
    $financeUser = fwUser(DailyActivityRoles::EMPLOYEE_ROLE, FieldWorkRoles::EMPLOYEE_PERMISSIONS, 'c@fw.test');
    foreach ([[$this->userA, $this->supervisor], [$financeUser, $this->financeManager]] as [$employeeUser, $manager]) {
        $request = fwCreate($employeeUser, ['action' => 'submit']);
        $this->actingAs($manager)->post(route('field-work.requests.approve', $request))->assertSessionHasNoErrors();
    }

    // The HR directorate supervisor also oversees the whole organization.
    $this->supervisor->givePermissionTo(Permission::findOrCreate('field_work.view_org', 'web'));
    $supervisor = $this->supervisor->fresh();

    $this->actingAs($supervisor)->get(route('field-work.team.index'))
        ->assertInertia(fn (Assert $page) => $page->where('requests.meta.total', 1)->where('requests.data.0.requester.id', $this->employeeA->id));
    $this->actingAs($supervisor)->get(route('field-work.requests.index'))
        ->assertInertia(fn (Assert $page) => $page->where('requests.meta.total', 2));
});

test('the browser may ask for location on this origin only (GPS check-in), and nothing else is loosened', function (): void {
    $policy = (string) $this->actingAs($this->userA)->get(route('employee.field-work.index'))->headers->get('Permissions-Policy');

    expect($policy)->toContain('geolocation=(self)')
        ->toContain('microphone=()')
        ->toContain('payment=()')
        ->toContain('usb=()')
        ->not->toContain('geolocation=*');
});
