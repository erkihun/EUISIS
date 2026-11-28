<?php

declare(strict_types=1);

use App\Contracts\EmployeeLeaveProvider;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Modules\DailyWork\Contracts\Data\DailyActivityRoles;
use Modules\DailyWork\Domain\Models\DailyActivityReviewerAssignment;
use Modules\Leave\Application\Notifications\LeaveNotification;
use Modules\Leave\Application\Queries\ApprovedLeaveProvider;
use Modules\Leave\Application\Services\LeaveBalanceService;
use Modules\Leave\Application\Services\LeaveCalendar;
use Modules\Leave\Application\Services\LeaveGrantService;
use Modules\Leave\Application\Services\LeaveRules;
use Modules\Leave\Application\Services\LeaveSettings;
use Modules\Leave\Contracts\Data\LeaveRoles;
use Modules\Leave\Contracts\Data\LeaveStatus;
use Modules\Leave\Contracts\EmployeeLeaveSummary;
use Modules\Leave\Domain\Enums\LeaveEntryType;
use Modules\Leave\Domain\Models\LeaveLedgerEntry;
use Modules\Leave\Domain\Models\LeavePlan;
use Modules\Leave\Domain\Models\LeaveRequest;
use Modules\Leave\Domain\Models\LeaveRequestDay;
use Modules\Leave\Domain\Models\LeaveRule;
use Modules\Leave\Domain\Models\LeaveRuleSet;
use Modules\Leave\Domain\Models\LeaveType;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Employee leave (ADR 0039, Modules/Leave/README.md)
|--------------------------------------------------------------------------
|
| "Now" is Monday 2 November 2026 in Addis Ababa: budget year 2019 (Hamle 1,
| 2018 E.C. = 8 July 2026, to Sene 30, 2019 E.C.). The standard request is
| Monday 9 to Friday 13 November 2026: five working days.
|
| The figures are those of the seeded rule set (Proclamation 1064/2010 E.C.):
| 20 days + 1 per year of service up to 30, after 11 months; 7 days of
| miscellaneous leave a year, never carried forward.
*/

const LV_TODAY = '2026-11-02';

function lvAt(string $date, string $time = '08:00'): void
{
    Carbon::setTestNow(Carbon::parse("{$date} {$time}:00", 'Africa/Addis_Ababa')->setTimezone(date_default_timezone_get()));
}

/** @param array<int, string> $permissions */
function lvUser(string $role, array $permissions, ?string $email = null): User
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

/** @param array<string, mixed> $attributes */
function lvEmployee(string $number, string $email, Organization $org, OrganizationUnit $unit, Position $position, array $attributes = []): Employee
{
    $employee = Employee::query()->create([
        'employee_number' => $number, 'first_name' => $number, 'last_name' => 'Employee', 'full_name' => "{$number} Employee",
        'email' => $email, 'status' => 'active', 'gender' => 'male', 'service_start_date' => '2020-01-01', ...$attributes,
    ]);
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id, 'organization_id' => $org->id, 'organization_unit_id' => $unit->id, 'position_id' => $position->id,
        'assignment_status' => 'active', 'effective_from' => '2020-01-01', 'is_current' => true,
    ]);
    $employee->update(['current_assignment_id' => $assignment->id]);

    return $employee->fresh();
}

function lvType(string $code): LeaveType
{
    return LeaveType::query()->where('code', $code)->firstOrFail();
}

/** @param array<string, mixed> $overrides */
function lvPayload(string $typeCode = 'annual', array $overrides = []): array
{
    return ['leave_type_id' => lvType($typeCode)->id, 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-13', 'reason' => 'Family visit', ...$overrides];
}

/** @param array<string, mixed> $overrides */
function lvAsk(User $user, string $typeCode = 'annual', array $overrides = []): LeaveRequest
{
    test()->actingAs($user)->post(route('employee.leave.store'), lvPayload($typeCode, $overrides))->assertSessionHasNoErrors();

    return LeaveRequest::query()->latest('created_at')->latest('id')->firstOrFail();
}

function lvApprove(LeaveRequest $request): LeaveRequest
{
    if ($request->status === LeaveStatus::PendingSupervisor) {
        test()->actingAs(test()->supervisor)->post(route('leave.requests.supervisor-decision', $request), ['decision' => 'approve'])->assertSessionHasNoErrors();
    }
    test()->actingAs(test()->hr)->post(route('leave.requests.hr-decision', $request), ['decision' => 'approve'])->assertSessionHasNoErrors();

    return $request->refresh();
}

/** The figures of one leave type for an employee, from the balance summary. */
function lvFigures(Employee $employee, string $typeCode): array
{
    app(LeaveRules::class)->forget();

    return collect(app(LeaveBalanceService::class)->summary($employee)['types'])->firstWhere('code', $typeCode);
}

beforeEach(function (): void {
    lvAt(LV_TODAY);
    Notification::fake();
    Storage::fake('local');

    $orgType = OrganizationType::query()->create(['code' => 'LV-TYPE', 'name_en' => 'Bureau']);
    $this->org = Organization::query()->create(['organization_type_id' => $orgType->id, 'code' => 'LV-ORG', 'name_en' => 'Civil Service Bureau', 'name_am' => 'ሲቪል ሰርቪስ ቢሮ', 'status' => 'active']);
    $this->otherOrg = Organization::query()->create(['organization_type_id' => $orgType->id, 'code' => 'LV-OTHER', 'name_en' => 'Health Bureau', 'status' => 'active']);
    $this->unit = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'code' => 'LV-HR', 'name_en' => 'HR Directorate', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->otherUnit = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'code' => 'LV-FIN', 'name_en' => 'Finance Directorate', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->foreignUnit = OrganizationUnit::query()->create(['organization_id' => $this->otherOrg->id, 'code' => 'LV-HB', 'name_en' => 'Health HR', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->position = Position::query()->create(['organization_id' => $this->org->id, 'organization_unit_id' => $this->unit->id, 'job_position_code' => 'LV-P1', 'title_en' => 'Officer', 'is_active' => true]);
    $this->foreignPosition = Position::query()->create(['organization_id' => $this->otherOrg->id, 'organization_unit_id' => $this->foreignUnit->id, 'job_position_code' => 'LV-P3', 'title_en' => 'Clerk', 'is_active' => true]);

    // Six whole years of service at the start of budget year 2019: 20 + 6 = 26 days of annual leave.
    $this->employeeA = lvEmployee('LV-A', 'a@lv.test', $this->org, $this->unit, $this->position);
    $this->employeeB = lvEmployee('LV-B', 'b@lv.test', $this->org, $this->unit, $this->position, ['gender' => 'female']);
    $this->employeeF = lvEmployee('LV-F', 'f@lv.test', $this->otherOrg, $this->foreignUnit, $this->foreignPosition);

    $this->userA = lvUser(DailyActivityRoles::EMPLOYEE_ROLE, LeaveRoles::EMPLOYEE_PERMISSIONS, 'a@lv.test');
    $this->userB = lvUser(DailyActivityRoles::EMPLOYEE_ROLE, LeaveRoles::EMPLOYEE_PERMISSIONS, 'b@lv.test');
    $this->userF = lvUser(DailyActivityRoles::EMPLOYEE_ROLE, LeaveRoles::EMPLOYEE_PERMISSIONS, 'f@lv.test');

    // Immediate supervisor of the HR directorate.
    $this->supervisor = lvUser(DailyActivityRoles::REVIEWER_ROLE, LeaveRoles::SUPERVISOR_PERMISSIONS);
    DailyActivityReviewerAssignment::query()->create(['reviewer_user_id' => $this->supervisor->id, 'organization_id' => $this->org->id, 'organization_unit_id' => $this->unit->id, 'include_sub_units' => true, 'is_active' => true]);
    // A line manager of another unit, with the same permissions.
    $this->otherManager = lvUser('LV Finance Manager', LeaveRoles::SUPERVISOR_PERMISSIONS);
    DailyActivityReviewerAssignment::query()->create(['reviewer_user_id' => $this->otherManager->id, 'organization_id' => $this->org->id, 'organization_unit_id' => $this->otherUnit->id, 'include_sub_units' => true, 'is_active' => true]);

    // HR officer of the Civil Service Bureau only; and one of the Health Bureau.
    $this->hr = lvUser('LV HR Officer', [...LeaveRoles::HR_PERMISSIONS, 'leave.postpone']);
    UserOrganizationScope::query()->create(['user_id' => $this->hr->id, 'organization_id' => $this->org->id, 'scope_type' => 'self', 'is_active' => true]);
    $this->foreignHr = lvUser('LV Foreign HR', LeaveRoles::HR_PERMISSIONS);
    UserOrganizationScope::query()->create(['user_id' => $this->foreignHr->id, 'organization_id' => $this->otherOrg->id, 'scope_type' => 'self', 'is_active' => true]);

    app(OrganizationScopeService::class)->clearCache();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

// ── The calendar and the yearly grant ───────────────────────────────────────

test('the budget year runs from Hamle 1 to Sene 30 of the Ethiopian calendar', function (): void {
    $calendar = app(LeaveCalendar::class);

    expect($calendar->budgetYearOf('2026-11-02'))->toBe(2019)
        ->and($calendar->budgetYearStart(2019))->toBe('2026-07-08')
        ->and($calendar->budgetYearEnd(2019))->toBe('2027-07-07')
        ->and($calendar->budgetYearOf('2026-07-07'))->toBe(2018)
        ->and($calendar->budgetYearOf('2026-07-08'))->toBe(2019);
});

test('annual leave is 20 days plus one per year of service, capped at 30, and is granted once however often it is settled', function (): void {
    $veteran = lvEmployee('LV-V', 'v@lv.test', $this->org, $this->unit, $this->position, ['service_start_date' => '2005-01-01']);

    expect(lvFigures($this->employeeA, 'annual')['granted'])->toBe(26.0)
        ->and(lvFigures($veteran, 'annual')['granted'])->toBe(30.0);

    app(LeaveGrantService::class)->settle($this->employeeA);
    app(LeaveGrantService::class)->settle($this->employeeA);
    expect(LeaveLedgerEntry::query()->where('employee_id', $this->employeeA->id)->where('leave_type_id', lvType('annual')->id)->where('entry_type', LeaveEntryType::Grant->value)->count())->toBe(1);
});

test('a new employee has no annual leave before eleven months of service, and no grant without a service start date', function (): void {
    $newcomer = lvEmployee('LV-N', 'n@lv.test', $this->org, $this->unit, $this->position, ['service_start_date' => '2026-09-01']);
    $undated = lvEmployee('LV-U', 'u@lv.test', $this->org, $this->unit, $this->position, ['service_start_date' => null]);

    expect(lvFigures($newcomer, 'annual'))->granted->toBe(0.0)->no_grant_reason->toBe('waiting_period')
        ->and(lvFigures($undated, 'annual'))->granted->toBe(0.0)->no_grant_reason->toBe('no_service_start_date')
        // The seven days need no waiting period.
        ->and(lvFigures($newcomer, 'miscellaneous')['granted'])->toBe(7.0);

    $user = lvUser(DailyActivityRoles::EMPLOYEE_ROLE, LeaveRoles::EMPLOYEE_PERMISSIONS, 'n@lv.test');
    $this->actingAs($user)->post(route('employee.leave.store'), lvPayload())->assertSessionHasErrors('ends_on');
    expect(LeaveRequest::query()->count())->toBe(0);
});

test('an employee who joined last budget year is granted that service in proportion once the eleven months are over', function (): void {
    // Joined 8 January 2026: six whole months in budget year 2018, eleven months complete on 8 December 2026.
    $employee = lvEmployee('LV-J', 'j@lv.test', $this->org, $this->unit, $this->position, ['service_start_date' => '2026-01-08']);
    expect(lvFigures($employee, 'annual')['granted'])->toBe(0.0);

    lvAt('2026-12-08');
    // 20 for the year in progress + 20 × 6/12 for the months served before it.
    expect(lvFigures($employee, 'annual')['granted'])->toBe(30.0);
});

// ── Request → supervisor → HR ───────────────────────────────────────────────

test('E2E: an annual leave request goes to the supervisor, then to HR, spends the balance and is reported as leave', function (): void {
    $request = lvAsk($this->userA);

    expect($request->status)->toBe(LeaveStatus::PendingSupervisor)
        ->and($request->employee_id)->toBe($this->employeeA->id)
        ->and($request->organization_id)->toBe($this->org->id)
        ->and($request->organization_unit_id)->toBe($this->unit->id)
        ->and($request->supervisor_user_id)->toBe($this->supervisor->id)
        ->and($request->days_counted)->toBe(5.0)
        ->and($request->budget_year)->toBe(2019)
        ->and($request->leave_rule_set_id)->not->toBeNull();
    Notification::assertSentTo($this->supervisor, LeaveNotification::class, fn (LeaveNotification $n): bool => $n->kind === 'approval_required');

    // Asked for, not yet spent: the days are spoken for.
    expect(lvFigures($this->employeeA, 'annual'))->available->toBe(26.0)->pending->toBe(5.0)->free->toBe(21.0);
    // Not leave yet for the other modules.
    expect(app(EmployeeLeaveProvider::class)->fullDayLeaveDates([$this->employeeA->id], Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')))->toBe([]);

    $this->actingAs($this->supervisor)->post(route('leave.requests.supervisor-decision', $request), ['decision' => 'approve', 'comment' => 'Agreed'])->assertSessionHasNoErrors();
    expect($request->refresh()->status)->toBe(LeaveStatus::PendingHr);
    Notification::assertSentTo($this->hr, LeaveNotification::class, fn (LeaveNotification $n): bool => $n->kind === 'confirmation_required');

    $this->actingAs($this->hr)->post(route('leave.requests.hr-decision', $request), ['decision' => 'approve'])->assertSessionHasNoErrors();
    expect($request->refresh()->status)->toBe(LeaveStatus::Approved)
        ->and($request->hr_decided_by)->toBe($this->hr->id);
    Notification::assertSentTo($this->userA, LeaveNotification::class, fn (LeaveNotification $n): bool => $n->kind === 'approved');

    expect(lvFigures($this->employeeA, 'annual'))->available->toBe(21.0)->pending->toBe(0.0)->taken->toBe(5.0);
    expect(app(EmployeeLeaveProvider::class))->toBeInstanceOf(ApprovedLeaveProvider::class);
    $dates = app(EmployeeLeaveProvider::class)->fullDayLeaveDates([$this->employeeA->id, $this->employeeB->id], Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
    expect(array_keys($dates))->toBe([$this->employeeA->id])
        ->and(array_keys($dates[$this->employeeA->id]))->toBe(['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13']);

    expect(AuditLog::query()->whereIn('event_type', ['leave.requested', 'leave.supervisor_decided', 'leave.approved'])->count())->toBe(3);

    $summary = app(EmployeeLeaveSummary::class)->forEmployee($this->employeeA->id, LV_TODAY);
    expect($summary['next_leave']['starts_on'])->toBe('2026-11-09')->and($summary['pending_requests'])->toBe(0);
});

test('a request for more days than are free, or for days already asked for, is refused', function (): void {
    $this->actingAs($this->userA)->post(route('employee.leave.store'), lvPayload('annual', ['starts_on' => '2026-11-09', 'ends_on' => '2027-01-29']))->assertSessionHasErrors('ends_on');

    lvAsk($this->userA);
    $this->actingAs($this->userA)->post(route('employee.leave.store'), lvPayload('miscellaneous', ['starts_on' => '2026-11-13', 'ends_on' => '2026-11-16']))->assertSessionHasErrors('starts_on');
    $this->actingAs($this->userA)->post(route('employee.leave.store'), lvPayload('annual', ['starts_on' => '2026-10-26', 'ends_on' => '2026-10-27']))->assertSessionHasErrors('starts_on');

    expect(LeaveRequest::query()->count())->toBe(1);
});

test('a weekend inside a working-day leave is not counted, and nothing else can be asked for it', function (): void {
    $request = lvAsk($this->userA, 'annual', ['starts_on' => '2026-11-12', 'ends_on' => '2026-11-17']);

    expect($request->days_counted)->toBe(4.0)
        ->and($request->days()->count())->toBe(6)
        ->and($request->days()->where('is_counted', false)->count())->toBe(2);
});

test('a refusal needs a reason, frees the dates and tells the employee', function (): void {
    $request = lvAsk($this->userA);

    $this->actingAs($this->supervisor)->post(route('leave.requests.supervisor-decision', $request), ['decision' => 'reject'])->assertSessionHasErrors('comment');
    $this->actingAs($this->supervisor)->post(route('leave.requests.supervisor-decision', $request), ['decision' => 'reject', 'comment' => 'Year-end closing'])->assertSessionHasNoErrors();

    expect($request->refresh()->status)->toBe(LeaveStatus::Rejected)
        ->and(LeaveRequestDay::query()->count())->toBe(0)
        ->and(lvFigures($this->employeeA, 'annual')['free'])->toBe(26.0);
    Notification::assertSentTo($this->userA, LeaveNotification::class, fn (LeaveNotification $n): bool => $n->kind === 'rejected');
    // The same days can be asked for again.
    lvAsk($this->userA);
});

test('when no supervisor can be named the request goes straight to HR', function (): void {
    DailyActivityReviewerAssignment::query()->delete();

    $request = lvAsk($this->userA);

    expect($request->status)->toBe(LeaveStatus::PendingHr)->and($request->supervisor_user_id)->toBeNull();
    Notification::assertSentTo($this->hr, LeaveNotification::class, fn (LeaveNotification $n): bool => $n->kind === 'confirmation_required');
});

// ── The owner's rules: half days, the seven days, leave without pay ─────────

test('a half day counts half a day and is not reported as a day of leave', function (): void {
    $request = lvApprove(lvAsk($this->userA, 'annual', ['starts_on' => '2026-11-09', 'ends_on' => null, 'is_half_day' => true, 'half_day_part' => 'afternoon']));

    expect($request->days_counted)->toBe(0.5)
        ->and($request->half_day_part)->toBe('afternoon')
        ->and(lvFigures($this->employeeA, 'annual')['available'])->toBe(25.5)
        ->and(app(EmployeeLeaveProvider::class)->fullDayLeaveDates([$this->employeeA->id], Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')))->toBe([]);

    // Sick leave is not taken by the half day.
    $this->actingAs($this->userA)->post(route('employee.leave.store'), lvPayload('sick', ['starts_on' => '2026-11-10', 'ends_on' => null, 'is_half_day' => true]))->assertSessionHasErrors('is_half_day');
});

test('the seven days of miscellaneous leave are granted every year and never carried forward', function (): void {
    lvApprove(lvAsk($this->userA, 'miscellaneous', ['starts_on' => '2026-11-09', 'ends_on' => '2026-11-10']));
    expect(lvFigures($this->employeeA, 'miscellaneous'))->granted->toBe(7.0)->available->toBe(5.0);

    $this->actingAs($this->userA)->post(route('employee.leave.store'), lvPayload('miscellaneous', ['starts_on' => '2026-11-16', 'ends_on' => '2026-11-23']))->assertSessionHasErrors('ends_on');

    // Next budget year: a fresh seven days; the five unused ones are closed, not added.
    lvAt('2027-07-20');
    $figures = lvFigures($this->employeeA, 'miscellaneous');
    expect($figures)->granted->toBe(7.0)->available->toBe(7.0)->carried->toBe(0.0)
        ->and($figures['waiting'])->toBe([]);
    $expired = LeaveLedgerEntry::query()->where('employee_id', $this->employeeA->id)->where('leave_type_id', lvType('miscellaneous')->id)->where('entry_type', LeaveEntryType::Expired->value)->get();
    expect($expired)->toHaveCount(1)->and($expired->first()->days)->toBe(-5.0)->and($expired->first()->entitlement_year)->toBe(2019);
});

test('leave without pay needs no balance and is decided like any other request', function (): void {
    $request = lvApprove(lvAsk($this->userA, 'unpaid', ['starts_on' => '2026-11-09', 'ends_on' => '2026-11-22']));

    expect($request->status)->toBe(LeaveStatus::Approved)
        // Calendar days: the weekends count.
        ->and($request->days_counted)->toBe(14.0)
        ->and(LeaveLedgerEntry::query()->where('leave_request_id', $request->id)->count())->toBe(0)
        ->and(lvFigures($this->employeeA, 'unpaid'))->taken->toBe(14.0)->pay_class->toBe('none');
});

test('a limit the institution sets for leave without pay is enforced per request and per year', function (): void {
    LeaveRule::query()->where('leave_type_id', lvType('unpaid')->id)->update(['max_days_per_request' => 10, 'max_days_per_year' => 15]);

    $this->actingAs($this->userA)->post(route('employee.leave.store'), lvPayload('unpaid', ['starts_on' => '2026-11-09', 'ends_on' => '2026-11-22']))->assertSessionHasErrors('ends_on');
    lvAsk($this->userA, 'unpaid', ['starts_on' => '2026-11-09', 'ends_on' => '2026-11-18']);
    $this->actingAs($this->userA)->post(route('employee.leave.store'), lvPayload('unpaid', ['starts_on' => '2026-12-01', 'ends_on' => '2026-12-06']))->assertSessionHasErrors('ends_on');
});

// ── Carry-over of annual leave ──────────────────────────────────────────────

test('unused annual leave waits for the head of the institution; once postponed it is used first', function (): void {
    lvFigures($this->employeeA, 'annual');
    lvAt('2027-07-20');

    $figures = lvFigures($this->employeeA, 'annual');
    expect($figures)->granted->toBe(27.0)->available->toBe(27.0)
        ->and($figures['waiting'])->toBe([['year' => 2019, 'days' => 26.0, 'last_year' => 2021]]);

    // Not the HR officer of another institution; not beyond the years the rule allows.
    $this->actingAs($this->foreignHr)->post(route('leave.balances.postpone', $this->employeeA), ['leave_type_id' => lvType('annual')->id, 'entitlement_year' => 2019, 'until_year' => 2020, 'reason' => 'Audit season'])->assertForbidden();
    $this->actingAs($this->hr)->post(route('leave.balances.postpone', $this->employeeA), ['leave_type_id' => lvType('annual')->id, 'entitlement_year' => 2019, 'until_year' => 2022, 'reason' => 'Audit season'])->assertSessionHasErrors('until_year');
    $this->actingAs($this->hr)->post(route('leave.balances.postpone', $this->employeeA), ['leave_type_id' => lvType('annual')->id, 'entitlement_year' => 2019, 'until_year' => 2020, 'reason' => 'Audit season'])->assertSessionHasNoErrors();

    expect(lvFigures($this->employeeA, 'annual'))->available->toBe(53.0)->carried->toBe(26.0);

    $request = lvApprove(lvAsk($this->userA, 'annual', ['starts_on' => '2027-08-02', 'ends_on' => '2027-08-06']));
    expect(LeaveLedgerEntry::query()->where('leave_request_id', $request->id)->pluck('entitlement_year')->all())->toBe([2019]);

    // The year after, the postponed days are closed.
    lvAt('2028-07-20');
    expect(array_column(lvFigures($this->employeeA, 'annual')['buckets'], 'year'))->not->toContain(2019);
});

// ── Entitlements registered from the evidence ───────────────────────────────

test('sick leave skips the supervisor, needs a certificate from three days, and its reason is hidden from everyone but the employee and HR', function (): void {
    $request = lvAsk($this->userA, 'sick', ['starts_on' => '2026-11-02', 'ends_on' => '2026-11-06', 'reason' => 'Malaria']);
    expect($request->status)->toBe(LeaveStatus::PendingHr)
        ->and($request->days_counted)->toBe(5.0)
        ->and($request->pay_breakdown)->toBe(['full' => 5, 'half' => 0]);

    $this->actingAs($this->hr)->post(route('leave.requests.hr-decision', $request), ['decision' => 'approve'])->assertSessionHasErrors('documents');

    $this->actingAs($this->userA)->post(route('leave.requests.documents.store', $request), ['documents' => [UploadedFile::fake()->create('certificate.pdf', 120, 'application/pdf')]])->assertSessionHasNoErrors();
    $document = $request->documents()->firstOrFail();
    Storage::disk('local')->assertExists($document->path);

    $this->actingAs($this->hr)->post(route('leave.requests.hr-decision', $request), ['decision' => 'approve'])->assertSessionHasNoErrors();
    expect($request->refresh()->status)->toBe(LeaveStatus::Approved);

    // HR reads the reason and the certificate; an HR officer of another institution does not.
    $this->actingAs($this->hr)->get(route('leave.requests.show', $request))->assertOk()
        ->assertInertia(fn ($page) => $page->where('leaveRequest.reason', 'Malaria')->has('leaveRequest.documents', 1));
    $this->actingAs($this->hr)->get(route('leave.documents.download', $document))->assertOk();
    $this->actingAs($this->foreignHr)->get(route('leave.requests.show', $request))->assertForbidden();
    $this->actingAs($this->foreignHr)->get(route('leave.documents.download', $document))->assertForbidden();
    $this->actingAs($this->userB)->get(route('leave.documents.download', $document))->assertForbidden();
    expect(AuditLog::query()->where('event_type', 'leave.document_downloaded')->count())->toBe(1);
});

test('a supervisor sees the dates of a sick leave of their team member but not its reason', function (): void {
    // A sick leave routed through the supervisor, as an institution may set it.
    lvType('sick')->update(['requires_supervisor_approval' => true]);
    $request = lvAsk($this->userA, 'sick', ['starts_on' => '2026-11-02', 'ends_on' => '2026-11-03', 'reason' => 'Malaria']);

    $this->actingAs($this->supervisor)->get(route('leave.requests.show', $request))->assertOk()
        ->assertInertia(fn ($page) => $page->where('leaveRequest.reason', null)->where('leaveRequest.reason_hidden', true)->where('leaveRequest.starts_on', '2026-11-02'));
});

test('sick leave is limited to eight months in a year, six of them at full pay', function (): void {
    // Seven months already taken in the past year.
    $reported = lvAsk($this->userA, 'sick', ['starts_on' => '2026-03-01', 'ends_on' => '2026-09-26']);
    $this->actingAs($this->userA)->post(route('leave.requests.documents.store', $reported), ['documents' => [UploadedFile::fake()->create('c.pdf', 50, 'application/pdf')]])->assertSessionHasNoErrors();
    lvApprove($reported);
    $first = LeaveRequest::query()->firstOrFail();
    expect($first->status)->toBe(LeaveStatus::Approved)->and($first->days_counted)->toBe(210.0)->and($first->pay_breakdown)->toBe(['full' => 180, 'half' => 30]);

    // Twenty more days: all at half pay. Forty more: over the eight months.
    $this->actingAs($this->userA)->post(route('employee.leave.store'), lvPayload('sick', ['starts_on' => '2026-11-02', 'ends_on' => '2026-12-11']))->assertSessionHasErrors('ends_on');
    $second = lvAsk($this->userA, 'sick', ['starts_on' => '2026-11-02', 'ends_on' => '2026-11-21']);
    expect($second->pay_breakdown)->toBe(['full' => 0, 'half' => 20]);
});

test('maternity leave runs from the chosen start to ninety days after the expected date, and is for women', function (): void {
    $this->actingAs($this->userA)->post(route('employee.leave.store'), lvPayload('maternity', ['starts_on' => '2026-11-09', 'expected_delivery_on' => '2026-12-09']))->assertSessionHasErrors('leave_type_id');
    $this->actingAs($this->userB)->post(route('employee.leave.store'), lvPayload('maternity', ['starts_on' => '2026-11-02', 'expected_delivery_on' => '2026-12-09']))->assertSessionHasErrors('starts_on');
    $this->actingAs($this->userB)->post(route('employee.leave.store'), lvPayload('maternity', ['starts_on' => '2026-11-09', 'ends_on' => '2027-02-09']))->assertSessionHasErrors('ends_on');

    $request = lvAsk($this->userB, 'maternity', ['starts_on' => '2026-11-09', 'ends_on' => null, 'expected_delivery_on' => '2026-12-09']);
    expect($request->status)->toBe(LeaveStatus::PendingHr)
        ->and($request->ends_on->toDateString())->toBe('2027-03-08')
        ->and($request->days_counted)->toBe(120.0);

    $this->actingAs($this->userB)->post(route('leave.requests.documents.store', $request), ['documents' => [UploadedFile::fake()->create('m.pdf', 50, 'application/pdf')]])->assertSessionHasNoErrors();
    lvApprove($request);

    // Delivered five days late (three of them working days): ninety days from the delivery, three days from annual leave.
    $this->actingAs($this->hr)->post(route('leave.requests.delivery', $request), ['delivered_on' => '2026-12-14'])->assertSessionHasNoErrors();
    expect($request->refresh()->ends_on->toDateString())->toBe('2027-03-13')
        ->and($request->actual_delivery_on->toDateString())->toBe('2026-12-14')
        ->and(LeaveLedgerEntry::query()->where('entry_type', LeaveEntryType::MaternityOverrun->value)->sum('days'))->toEqual(-3.0);
    $this->actingAs($this->hr)->post(route('leave.requests.delivery', $request), ['delivered_on' => '2026-12-15'])->assertSessionHasErrors('delivered_on');
});

test('paternity leave is at most ten working days and is for men', function (): void {
    $this->actingAs($this->userB)->post(route('employee.leave.store'), lvPayload('paternity'))->assertSessionHasErrors('leave_type_id');
    $this->actingAs($this->userA)->post(route('employee.leave.store'), lvPayload('paternity', ['starts_on' => '2026-11-09', 'ends_on' => '2026-11-23']))->assertSessionHasErrors('ends_on');

    $request = lvAsk($this->userA, 'paternity', ['starts_on' => '2026-11-09', 'ends_on' => '2026-11-20']);
    expect($request->days_counted)->toBe(10.0)->and($request->status)->toBe(LeaveStatus::PendingHr);
});

// ── Cancelling and ending early ─────────────────────────────────────────────

test('an approved leave that has not begun can be withdrawn and its days come back', function (): void {
    $request = lvApprove(lvAsk($this->userA));
    expect(lvFigures($this->employeeA, 'annual')['available'])->toBe(21.0);

    $this->actingAs($this->userB)->post(route('leave.requests.cancel', $request))->assertForbidden();
    $this->actingAs($this->userA)->post(route('leave.requests.cancel', $request), ['reason' => 'Plans changed'])->assertSessionHasNoErrors();

    expect($request->refresh()->status)->toBe(LeaveStatus::Cancelled)
        ->and(lvFigures($this->employeeA, 'annual')['available'])->toBe(26.0)
        ->and(LeaveRequestDay::query()->count())->toBe(0);

    // Once it has begun, only HR can end it.
    $second = lvApprove(lvAsk($this->userA));
    lvAt('2026-11-10');
    $this->actingAs($this->userA)->post(route('leave.requests.cancel', $second))->assertForbidden();
});

test('HR ends a leave early and the days after the last day come back', function (): void {
    $request = lvApprove(lvAsk($this->userA));
    lvAt('2026-11-10');

    $this->actingAs($this->userA)->post(route('leave.requests.end-early', $request), ['last_day_on' => '2026-11-10', 'reason' => 'Recalled'])->assertForbidden();
    $this->actingAs($this->hr)->post(route('leave.requests.end-early', $request), ['last_day_on' => '2026-11-13', 'reason' => 'Recalled'])->assertSessionHasErrors('last_day_on');
    $this->actingAs($this->hr)->post(route('leave.requests.end-early', $request), ['last_day_on' => '2026-11-10', 'reason' => 'Recalled for the audit'])->assertSessionHasNoErrors();

    expect($request->refresh()->ends_on->toDateString())->toBe('2026-11-10')
        ->and($request->days_counted)->toBe(2.0)
        ->and($request->ended_early_on->toDateString())->toBe('2026-11-10')
        ->and(lvFigures($this->employeeA, 'annual')['available'])->toBe(24.0);
    Notification::assertSentTo($this->userA, LeaveNotification::class, fn (LeaveNotification $n): bool => $n->kind === 'ended_early');
});

// ── Who may do what ─────────────────────────────────────────────────────────

test('nobody reads or decides a request that is not theirs to read or decide', function (): void {
    $request = lvAsk($this->userA);

    // Another employee, a manager of another unit, HR of another institution.
    $this->actingAs($this->userB)->get(route('employee.leave.show', $request))->assertForbidden();
    $this->actingAs($this->otherManager)->get(route('leave.requests.show', $request))->assertForbidden();
    $this->actingAs($this->otherManager)->post(route('leave.requests.supervisor-decision', $request), ['decision' => 'approve'])->assertForbidden();
    $this->actingAs($this->foreignHr)->get(route('leave.requests.show', $request))->assertForbidden();
    // HR cannot jump the supervisor, and the employee cannot decide their own request.
    $this->actingAs($this->hr)->post(route('leave.requests.hr-decision', $request), ['decision' => 'approve'])->assertForbidden();
    $this->actingAs($this->userA)->post(route('leave.requests.supervisor-decision', $request), ['decision' => 'approve'])->assertForbidden();

    expect($request->refresh()->status)->toBe(LeaveStatus::PendingSupervisor);

    // The registers show only what is in scope.
    $this->actingAs($this->hr)->get(route('leave.requests.index'))->assertOk()->assertInertia(fn ($page) => $page->has('leaveRequests.data', 1));
    $this->actingAs($this->foreignHr)->get(route('leave.requests.index'))->assertOk()->assertInertia(fn ($page) => $page->has('leaveRequests.data', 0));
    $this->actingAs($this->supervisor)->get(route('leave.approvals'))->assertOk()->assertInertia(fn ($page) => $page->has('leaveRequests.data', 1));
    $this->actingAs($this->otherManager)->get(route('leave.approvals'))->assertOk()->assertInertia(fn ($page) => $page->has('leaveRequests.data', 0));
    $this->actingAs($this->userA)->get(route('leave.requests.index'))->assertForbidden();
});

test('an HR officer cannot decide their own leave, and nobody enters a leave request for someone else', function (): void {
    $hrEmployee = lvEmployee('LV-H', 'h@lv.test', $this->org, $this->unit, $this->position);
    $officer = lvUser('LV HR Officer', [...LeaveRoles::HR_PERMISSIONS, ...LeaveRoles::EMPLOYEE_PERMISSIONS], 'h@lv.test');
    UserOrganizationScope::query()->create(['user_id' => $officer->id, 'organization_id' => $this->org->id, 'scope_type' => 'self', 'is_active' => true]);
    app(OrganizationScopeService::class)->clearCache();

    // A request is made by the employee, signed in with their own account: there is no route to enter one for another person.
    expect(Route::has('leave.register'))->toBeFalse()->and(Route::has('leave.register.store'))->toBeFalse()->and(Route::has('leave.lookup.employees'))->toBeFalse();
    $this->actingAs($officer)->post(route('employee.leave.store'), [...lvPayload(), 'employee_id' => $this->employeeB->id])->assertSessionHasNoErrors();
    expect(LeaveRequest::query()->sole()->employee_id)->toBe($hrEmployee->id);
    LeaveRequest::query()->delete();

    $request = lvAsk($officer);
    $this->actingAs($this->supervisor)->post(route('leave.requests.supervisor-decision', $request), ['decision' => 'approve'])->assertSessionHasNoErrors();
    $this->actingAs($officer)->post(route('leave.requests.hr-decision', $request), ['decision' => 'approve'])->assertForbidden();
});

// ── Balances: opening figures and corrections ───────────────────────────────

test('an opening balance and a correction are ledger lines with a reason, inside scope', function (): void {
    $payload = ['leave_type_id' => lvType('annual')->id, 'entitlement_year' => 2019, 'days' => 4.5, 'kind' => 'opening', 'reason' => 'Carried from the paper register'];

    $this->actingAs($this->foreignHr)->post(route('leave.balances.adjust', $this->employeeA), $payload)->assertForbidden();
    $this->actingAs($this->hr)->post(route('leave.balances.adjust', $this->employeeA), [...$payload, 'reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->hr)->post(route('leave.balances.adjust', $this->employeeA), $payload)->assertSessionHasNoErrors();
    $this->actingAs($this->hr)->post(route('leave.balances.adjust', $this->employeeA), [...$payload, 'days' => -1, 'kind' => 'adjustment', 'reason' => 'Counted twice'])->assertSessionHasNoErrors();

    expect(lvFigures($this->employeeA, 'annual')['available'])->toBe(29.5)
        ->and(AuditLog::query()->where('event_type', 'leave.balance_adjusted')->count())->toBe(2);

    $this->actingAs($this->hr)->get(route('leave.balances.show', $this->employeeA))->assertOk()
        ->assertInertia(fn ($page) => $page->component('modules/leave/EmployeeBalance', false)->has('ledger', 4)->where('can.adjust', true));
    $this->actingAs($this->hr)->get(route('leave.balances.show', $this->employeeF))->assertForbidden();
});

test('opening balances are imported from a file once, with the lines that could not be read reported', function (): void {
    $csv = "employee_number,leave_type_code,entitlement_year,days\nLV-A,annual,2019,6\nLV-F,annual,2019,3\nLV-B,sick,2019,3\nLV-X,annual,2019,3\nLV-B,annual,2019,2.5\n";
    $upload = fn () => UploadedFile::fake()->createWithContent('opening.csv', $csv);

    $this->actingAs($this->userA)->post(route('leave.balances.import'), ['file' => $upload()])->assertForbidden();
    $this->actingAs($this->hr)->post(route('leave.balances.import'), ['file' => $upload()])->assertSessionHasNoErrors()->assertSessionHas('warning');
    $this->actingAs($this->hr)->post(route('leave.balances.import'), ['file' => $upload()])->assertSessionHasNoErrors();

    $opening = LeaveLedgerEntry::query()->where('entry_type', LeaveEntryType::Opening->value)->get();
    expect($opening)->toHaveCount(2)
        ->and($opening->firstWhere('employee_id', $this->employeeA->id)->days)->toBe(6.0)
        ->and($opening->firstWhere('employee_id', $this->employeeB->id)->days)->toBe(2.5);
});

// ── The law as data ─────────────────────────────────────────────────────────

test('figures that requests were counted under are locked; a new rule set takes over from its date', function (): void {
    $admin = lvUser('LV Policy Admin', ['leave.settings.manage']);
    $set = LeaveRuleSet::query()->with('rules')->firstOrFail();
    $rules = fn (float $base): array => $set->rules->map(fn (LeaveRule $rule): array => [
        'leave_type_id' => $rule->leave_type_id, 'base_days' => $rule->leave_type_id === lvType('annual')->id ? $base : $rule->base_days,
        'increment_per_service_year' => $rule->increment_per_service_year, 'max_days' => $rule->max_days, 'waiting_months' => $rule->waiting_months,
        'carry_over_mode' => $rule->carry_over_mode, 'carry_over_years' => $rule->carry_over_years, 'max_days_per_request' => $rule->max_days_per_request,
        'max_days_per_year' => $rule->max_days_per_year, 'settings' => $rule->settings,
    ])->all();
    $update = fn (float $base, bool $confirmed = false): array => ['name' => $set->name, 'legal_reference' => $set->legal_reference, 'notes' => null, 'is_confirmed' => $confirmed, 'effective_from' => $set->effective_from->toDateString(), 'rules' => $rules($base)];

    $this->actingAs($this->hr)->get(route('leave.settings'))->assertForbidden();
    $this->actingAs($admin)->get(route('leave.settings'))->assertOk()->assertInertia(fn ($page) => $page->component('modules/leave/Settings', false)->has('leaveTypes', 7)->has('ruleSets', 1));

    // Nothing counted yet: the figures are open.
    $this->actingAs($admin)->put(route('leave.settings.rule-sets.update', $set), $update(21))->assertSessionHasNoErrors();
    expect(LeaveRule::query()->where('leave_type_id', lvType('annual')->id)->value('base_days'))->toEqual(21.0);
    $this->actingAs($admin)->put(route('leave.settings.rule-sets.update', $set), $update(20))->assertSessionHasNoErrors();

    lvAsk($this->userA);
    $this->actingAs($admin)->put(route('leave.settings.rule-sets.update', $set), $update(22))->assertSessionHasErrors('rules');
    // Confirming the figures against the law is still possible.
    $this->actingAs($admin)->put(route('leave.settings.rule-sets.update', $set), $update(20, true))->assertSessionHasNoErrors();
    expect($set->refresh()->is_confirmed)->toBeTrue();

    // A new law from 1 January 2027.
    $this->actingAs($admin)->post(route('leave.settings.rule-sets.store'), ['name' => 'New proclamation', 'effective_from' => '2027-01-01', 'copy_from' => $set->id])->assertSessionHasNoErrors();
    $draft = LeaveRuleSet::query()->where('status', 'draft')->with('rules')->firstOrFail();
    expect($draft->rules)->toHaveCount(7);
    $this->actingAs($admin)->post(route('leave.settings.rule-sets.activate', $draft))->assertSessionHasNoErrors();

    app(LeaveRules::class)->forget();
    expect($set->refresh()->effective_to->toDateString())->toBe('2026-12-31')
        ->and(app(LeaveRules::class)->ruleSetOn('2026-12-31')->id)->toBe($set->id)
        ->and(app(LeaveRules::class)->ruleSetOn('2027-01-01')->id)->toBe($draft->id);
    expect(AuditLog::query()->where('event_type', 'leave.rules_changed')->count())->toBeGreaterThanOrEqual(4);
});

// ── Pages and the feature flag ──────────────────────────────────────────────

test('every page opens for the people it is for', function (): void {
    $request = lvAsk($this->userA);
    $reporter = lvUser('LV Reporter', ['leave.reports.view', 'leave.view_org']);
    UserOrganizationScope::query()->create(['user_id' => $reporter->id, 'organization_id' => $this->org->id, 'scope_type' => 'self', 'is_active' => true]);
    app(OrganizationScopeService::class)->clearCache();

    $this->actingAs($this->userA)->get(route('employee.leave.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('modules/leave/MyLeave', false)->where('hasEmployee', true)->has('leaveRequests.data', 1)->where('leaveSummary.budget_year', 2019));
    $this->actingAs($this->userA)->get(route('employee.leave.create'))->assertOk()->assertInertia(fn ($page) => $page->component('modules/leave/RequestForm', false)->where('formMode', 'own'));
    $this->actingAs($this->userA)->get(route('employee.leave.show', $request))->assertOk()
        ->assertInertia(fn ($page) => $page->component('modules/leave/RequestShow', false)->where('leaveRequest.can.cancel', true)->where('leaveRequest.can.decide_as_supervisor', false));
    $this->actingAs($this->supervisor)->get(route('leave.requests.show', $request))->assertOk()
        ->assertInertia(fn ($page) => $page->where('leaveRequest.can.decide_as_supervisor', true)->where('leaveBalance.free', 21));
    $this->actingAs($this->hr)->get(route('leave.balances.index', ['search' => 'LV-']))->assertOk()->assertInertia(fn ($page) => $page->component('modules/leave/Balances', false)->has('leaveEmployees.data', 2));
    $this->actingAs($reporter)->get(route('leave.reports', ['date' => '2026-11-10']))->assertOk()->assertInertia(fn ($page) => $page->component('modules/leave/Reports', false)->where('waiting.supervisor', 1)->has('byType', 7));
    $this->actingAs($this->userA)->get(route('leave.reports'))->assertForbidden();

    // A user with no employee record sees the page, with nothing to ask for.
    $unlinked = lvUser(DailyActivityRoles::EMPLOYEE_ROLE, LeaveRoles::EMPLOYEE_PERMISSIONS, 'nobody@lv.test');
    $this->actingAs($unlinked)->get(route('employee.leave.index'))->assertOk()->assertInertia(fn ($page) => $page->where('hasEmployee', false)->where('can.request', false));
    $this->actingAs($unlinked)->get(route('employee.leave.create'))->assertForbidden();
});

test('with the feature flag off every leave route is a 404', function (): void {
    config(['features.leave' => false]);

    $this->actingAs($this->userA)->get(route('employee.leave.index'))->assertNotFound();
    $this->actingAs($this->hr)->get(route('leave.requests.index'))->assertNotFound();
});

test('the yearly grant command brings every active employee up to date and can be run again', function (): void {
    $this->artisan('leave:grant-year')->assertSuccessful();
    $this->artisan('leave:grant-year')->assertSuccessful();

    $grants = LeaveLedgerEntry::query()->where('entry_type', LeaveEntryType::Grant->value)->get();
    // Three employees, annual and miscellaneous leave each.
    expect($grants)->toHaveCount(6)->and($grants->where('leave_type_id', lvType('annual')->id)->sum('days'))->toEqual(78.0);
});

test('the balances page shows what is left of the last two budget years, each in its own column', function (): void {
    // Four days of budget year 2018 still on the books, beside this year's grant of 26.
    $this->actingAs($this->hr)->post(route('leave.balances.adjust', $this->employeeA), [
        'leave_type_id' => lvType('annual')->id, 'entitlement_year' => 2018, 'days' => 4, 'kind' => 'opening', 'reason' => 'Carried from the paper register',
    ])->assertSessionHasNoErrors();
    lvApprove(lvAsk($this->userA));

    $annual = lvType('annual')->id;
    $misc = lvType('miscellaneous')->id;
    $this->actingAs($this->hr)->get(route('leave.balances.index', ['search' => 'LV-A']))->assertOk()
        ->assertInertia(fn ($page) => $page->component('modules/leave/Balances', false)
            ->where('budgetYears.previous', 2018)->where('budgetYears.current', 2019)
            ->where('budgetYears.starts_on', '2026-07-08')->where('budgetYears.ends_on', '2027-07-07')
            // Annual leave can be carried: two columns. The seven days end with the year: this year only.
            ->where('leaveTypes.0.years', [2018, 2019])->where('leaveTypes.1.years', [2019])
            ->where("leaveEmployees.data.0.totals.{$annual}:2018", 4)
            ->where("leaveEmployees.data.0.totals.{$annual}:2019", 21)
            ->where("leaveEmployees.data.0.totals.{$misc}:2019", 7));
});

test('the balances page serves one employee to its popups', function (): void {
    $this->actingAs($this->hr)->get(route('leave.balances.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('leaveSelected', null)->where('can.adjust', true)->missing('can.register'));

    $this->actingAs($this->hr)->get(route('leave.balances.index', ['selected' => $this->employeeA->id]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('leaveSelected.employee.employee_number', 'LV-A')
            ->where('leaveSelected.summary.budget_year', 2019)->has('leaveSelected.ledger', 2)
            ->where('leaveSelected.can.adjust', true)->missing('leaveSelected.can.register'));
    // Out of scope, or not an officer at all.
    $this->actingAs($this->hr)->get(route('leave.balances.index', ['selected' => $this->employeeF->id]))->assertForbidden();
    $this->actingAs($this->userA)->get(route('leave.balances.index', ['selected' => $this->employeeA->id]))->assertForbidden();
});

test('every list opens a request in its popup for those who may read it, and an action there comes back to the list', function (): void {
    $request = lvAsk($this->userA);
    $reporter = lvUser('LV Reporter', ['leave.reports.view', 'leave.view_org']);
    UserOrganizationScope::query()->create(['user_id' => $reporter->id, 'organization_id' => $this->org->id, 'scope_type' => 'self', 'is_active' => true]);
    app(OrganizationScopeService::class)->clearCache();

    // My leave: the employee's own request only.
    $this->actingAs($this->userA)->get(route('employee.leave.index'))->assertOk()->assertInertia(fn ($page) => $page->where('leaveOpenRequest', null)->where('leaveEmployee.employee_number', 'LV-A'));
    $this->actingAs($this->userA)->get(route('employee.leave.index', ['request' => $request->id]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('leaveOpenRequest.detail.reference_number', $request->reference_number)->where('leaveOpenRequest.detail.can.cancel', true)->where('leaveOpenRequest.balance', null));
    $this->actingAs($this->userB)->get(route('employee.leave.index', ['request' => $request->id]))->assertForbidden();

    // The supervisor's inbox, the register and the reports: the same popup, the same rules as the request's own page.
    $this->actingAs($this->supervisor)->get(route('leave.approvals', ['request' => $request->id]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('leaveOpenRequest.detail.can.decide_as_supervisor', true)->where('leaveOpenRequest.balance.free', 21));
    $this->actingAs($this->otherManager)->get(route('leave.approvals', ['request' => $request->id]))->assertForbidden();
    $this->actingAs($this->hr)->get(route('leave.requests.index', ['request' => $request->id]))->assertOk()->assertInertia(fn ($page) => $page->where('leaveOpenRequest.detail.id', $request->id));
    $this->actingAs($this->foreignHr)->get(route('leave.requests.index', ['request' => $request->id]))->assertForbidden();
    $this->actingAs($reporter)->get(route('leave.reports', ['request' => $request->id]))->assertOk()->assertInertia(fn ($page) => $page->where('leaveOpenRequest.detail.id', $request->id));

    // The supervisor approves from the popup and lands on the inbox again, popup still on the request.
    $from = route('leave.approvals', ['request' => $request->id]);
    $this->actingAs($this->supervisor)->from($from)->post(route('leave.requests.supervisor-decision', $request), ['decision' => 'approve'])->assertRedirect($from);

    // The employee asks from the popup on My leave and stays there.
    $mine = route('employee.leave.index');
    $this->actingAs($this->userB)->from($mine)->post(route('employee.leave.store'), [...lvPayload(), 'stay' => 1])->assertSessionHasNoErrors()->assertRedirect($mine);
});

test('half days may be taken over several days, half a day for each working day of the period', function (): void {
    // Five mornings, Monday to Friday, and the weekend after them is not counted.
    $request = lvApprove(lvAsk($this->userA, 'annual', ['starts_on' => '2026-11-09', 'ends_on' => '2026-11-15', 'is_half_day' => true, 'half_day_part' => 'morning']));

    expect($request->is_half_day)->toBeTrue()
        ->and($request->starts_on->toDateString())->toBe('2026-11-09')
        ->and($request->ends_on->toDateString())->toBe('2026-11-15')
        ->and($request->days_counted)->toBe(2.5)
        ->and($request->days()->where('is_counted', true)->count())->toBe(5)
        ->and($request->days()->where('is_counted', true)->sum('portion'))->toEqual(2.5)
        ->and(lvFigures($this->employeeA, 'annual')['available'])->toBe(23.5)
        // The employee still works half of each of those days: none is reported as a day of leave.
        ->and(app(EmployeeLeaveProvider::class)->fullDayLeaveDates([$this->employeeA->id], Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')))->toBe([]);

    // Cut short after the second morning: the other three half days come back.
    lvAt('2026-11-10');
    $this->actingAs($this->hr)->post(route('leave.requests.end-early', $request), ['last_day_on' => '2026-11-10', 'reason' => 'Recalled'])->assertSessionHasNoErrors();
    expect($request->refresh()->days_counted)->toBe(1.0)
        ->and(lvFigures($this->employeeA, 'annual')['available'])->toBe(25.0);
});

test('an employee states the leave schedule for the budget year; the supervisor and HR see it, and a planned period becomes a request', function (): void {
    $annual = lvType('annual')->id;

    // Nothing planned yet.
    $this->actingAs($this->userA)->get(route('employee.leave.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('leaveSchedule.budget_year', 2019)->where('leaveSchedule.rows', [])->where('leaveSchedule.year_ends_on', '2027-07-07'));

    // Two periods; a plan takes nothing from the balance.
    $this->actingAs($this->userA)->post(route('employee.leave.plans.store'), ['starts_on' => '2026-12-07', 'ends_on' => '2026-12-11', 'note' => 'Family visit'])->assertSessionHasNoErrors();
    $this->actingAs($this->userA)->post(route('employee.leave.plans.store'), ['starts_on' => '2027-03-01', 'ends_on' => '2027-03-12'])->assertSessionHasNoErrors();
    expect(LeavePlan::query()->where('employee_id', $this->employeeA->id)->count())->toBe(2)
        ->and(LeavePlan::query()->orderBy('starts_on')->first())->days_counted->toBe(5.0)->organization_unit_id->toBe($this->unit->id)->leave_type_id->toBe($annual)->budget_year->toBe(2019)
        ->and(lvFigures($this->employeeA, 'annual')['free'])->toBe(26.0);

    // Not in the past, not across the end of the budget year, not twice for the same days.
    $this->actingAs($this->userA)->post(route('employee.leave.plans.store'), ['starts_on' => '2026-10-26', 'ends_on' => '2026-10-27'])->assertSessionHasErrors('starts_on');
    $this->actingAs($this->userA)->post(route('employee.leave.plans.store'), ['starts_on' => '2027-07-05', 'ends_on' => '2027-07-09'])->assertSessionHasErrors('ends_on');
    $this->actingAs($this->userA)->post(route('employee.leave.plans.store'), ['starts_on' => '2026-12-10', 'ends_on' => '2026-12-14'])->assertSessionHasErrors('starts_on');
    $this->actingAs($this->userA)->get(route('employee.leave.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('leaveSchedule.rows', 2)->where('leaveSchedule.planned_days', 15)->where('leaveSchedule.rows.0.request', null));

    // The supervisor sees the team's schedule; a manager of another unit and HR of another institution see nothing of it.
    $this->actingAs($this->userF)->post(route('employee.leave.plans.store'), ['starts_on' => '2026-12-07', 'ends_on' => '2026-12-08'])->assertSessionHasNoErrors();
    $this->actingAs($this->supervisor)->get(route('leave.plans.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('modules/leave/Plans', false)->has('leavePlans.data', 2)->where('planSummary.employees_with_plan', 1)->where('planSummary.employees_without_plan', null));
    $this->actingAs($this->otherManager)->get(route('leave.plans.index'))->assertOk()->assertInertia(fn ($page) => $page->has('leavePlans.data', 0));
    $this->actingAs($this->hr)->get(route('leave.plans.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('leavePlans.data', 2)->where('planSummary.employees_with_plan', 1)->where('planSummary.employees_without_plan', 1));
    $this->actingAs($this->foreignHr)->get(route('leave.plans.index'))->assertOk()->assertInertia(fn ($page) => $page->has('leavePlans.data', 1)->where('leavePlans.data.0.employee.employee_number', 'LV-F'));
    $this->actingAs($this->userA)->get(route('leave.plans.index'))->assertForbidden();

    // The institution approves the schedule: every period waits for the supervisor (or an HR officer in scope).
    $plan = LeavePlan::query()->where('employee_id', $this->employeeA->id)->orderBy('starts_on')->firstOrFail();
    $later = LeavePlan::query()->where('employee_id', $this->employeeA->id)->orderByDesc('starts_on')->firstOrFail();
    expect($plan->status)->toBe('pending');
    Notification::assertSentTo($this->supervisor, LeaveNotification::class, fn (LeaveNotification $n): bool => $n->kind === 'plan_submitted');
    $this->actingAs($this->supervisor)->get(route('leave.plans.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('planSummary.waiting', 2)->where('leavePlans.data.0.can_decide', true)->where('leavePlans.data.0.status', 'pending'));
    // Not the employee, not a manager of another unit, not HR of another institution.
    $this->actingAs($this->userA)->post(route('leave.plans.decide', $plan), ['decision' => 'approve'])->assertForbidden();
    $this->actingAs($this->otherManager)->post(route('leave.plans.decide', $plan), ['decision' => 'approve'])->assertForbidden();
    $this->actingAs($this->foreignHr)->post(route('leave.plans.decide', $plan), ['decision' => 'approve'])->assertForbidden();
    // A period not yet approved does not become "the request of the plan".
    $early = lvAsk($this->userA, 'miscellaneous', ['starts_on' => '2026-11-09', 'ends_on' => '2026-11-09', 'plan_id' => $plan->id]);
    expect($plan->refresh()->leave_request_id)->toBeNull()->and($early->status)->toBe(LeaveStatus::PendingSupervisor);

    $this->actingAs($this->supervisor)->post(route('leave.plans.decide', $plan), ['decision' => 'approve'])->assertSessionHasNoErrors();
    $this->actingAs($this->supervisor)->post(route('leave.plans.decide', $plan), ['decision' => 'approve'])->assertSessionHasErrors('plan');
    // Sending back needs the reason; HR of the institution may decide too.
    $this->actingAs($this->hr)->post(route('leave.plans.decide', $later), ['decision' => 'reject'])->assertSessionHasErrors('comment');
    $this->actingAs($this->hr)->post(route('leave.plans.decide', $later), ['decision' => 'reject', 'comment' => 'Budget closing in March'])->assertSessionHasNoErrors();
    expect($plan->refresh()->status)->toBe('approved')->and($plan->decided_by)->toBe($this->supervisor->id)
        ->and($later->refresh()->status)->toBe('rejected')->and($later->decision_comment)->toBe('Budget closing in March')
        ->and(AuditLog::query()->where('event_type', 'leave.plan_decided')->count())->toBe(2);
    Notification::assertSentTo($this->userA, LeaveNotification::class, fn (LeaveNotification $n): bool => $n->kind === 'plan_approved');
    Notification::assertSentTo($this->userA, LeaveNotification::class, fn (LeaveNotification $n): bool => $n->kind === 'plan_rejected');
    // The days of a period that was sent back can be planned again; they no longer count as planned.
    $this->actingAs($this->userA)->get(route('employee.leave.index'))->assertOk()->assertInertia(fn ($page) => $page->where('leaveSchedule.planned_days', 5)->where('leaveSchedule.rows.1.status', 'rejected'));
    $this->actingAs($this->userA)->post(route('employee.leave.plans.store'), ['starts_on' => '2027-03-08', 'ends_on' => '2027-03-12'])->assertSessionHasNoErrors();
    LeavePlan::query()->where('employee_id', $this->employeeA->id)->whereDate('starts_on', '2027-03-08')->delete();

    // Asking for the approved period links the plan to its request; it can then no longer be removed.
    $request = lvAsk($this->userA, 'annual', ['starts_on' => '2026-12-07', 'ends_on' => '2026-12-11', 'plan_id' => $plan->id]);
    expect($plan->refresh()->leave_request_id)->toBe($request->id);
    $this->actingAs($this->userA)->delete(route('employee.leave.plans.destroy', $plan))->assertSessionHasErrors('plan');

    // Another employee cannot remove my period, nor link theirs to it; I can remove one I have not asked for.
    $second = LeavePlan::query()->where('employee_id', $this->employeeA->id)->orderByDesc('starts_on')->firstOrFail();
    $this->actingAs($this->userB)->delete(route('employee.leave.plans.destroy', $second))->assertForbidden();
    lvAsk($this->userB, 'annual', ['starts_on' => '2027-03-01', 'ends_on' => '2027-03-02', 'plan_id' => $second->id]);
    expect($second->refresh()->leave_request_id)->toBeNull();
    $this->actingAs($this->userA)->delete(route('employee.leave.plans.destroy', $second))->assertSessionHasNoErrors();
    expect(LeavePlan::query()->where('employee_id', $this->employeeA->id)->count())->toBe(1);
});

test('an approved planned period that was not changed is counted as leave by the system on its first day', function (): void {
    $plan = function (User $user, string $from, string $to): LeavePlan {
        test()->actingAs($user)->post(route('employee.leave.plans.store'), ['starts_on' => $from, 'ends_on' => $to])->assertSessionHasNoErrors();

        return LeavePlan::query()->latest('created_at')->latest('id')->firstOrFail();
    };
    $approve = fn (LeavePlan $p) => test()->actingAs(test()->supervisor)->post(route('leave.plans.decide', $p), ['decision' => 'approve'])->assertSessionHasNoErrors();

    $december = $plan($this->userA, '2026-12-07', '2026-12-11');   // approved, left alone
    $january = $plan($this->userA, '2027-01-04', '2027-01-08');    // approved, then taken off the schedule
    $waiting = $plan($this->userB, '2026-12-07', '2026-12-08');    // never approved
    $approve($december);
    $approve($january);

    // Before the first day nothing is counted, and the employee may still change the schedule.
    $this->artisan('leave:start-planned')->assertSuccessful();
    expect(LeaveRequest::query()->count())->toBe(0);
    $this->actingAs($this->userA)->delete(route('employee.leave.plans.destroy', $january))->assertSessionHasNoErrors();

    // The first day comes.
    lvAt('2026-12-07', '00:10');
    $this->artisan('leave:start-planned')->assertSuccessful();
    $this->artisan('leave:start-planned')->assertSuccessful();

    $request = LeaveRequest::query()->sole();
    expect($request->employee_id)->toBe($this->employeeA->id)
        ->and($request->status)->toBe(LeaveStatus::Approved)
        ->and($request->starts_on->toDateString())->toBe('2026-12-07')
        ->and($request->ends_on->toDateString())->toBe('2026-12-11')
        ->and($request->days_counted)->toBe(5.0)
        ->and($request->requester_user_id)->toBeNull()
        ->and($request->supervisor_decided_by)->toBe($this->supervisor->id)
        ->and($december->refresh()->leave_request_id)->toBe($request->id)
        ->and($waiting->refresh()->leave_request_id)->toBeNull()
        ->and(lvFigures($this->employeeA, 'annual'))->available->toBe(21.0)->taken->toBe(5.0);
    Notification::assertSentTo($this->userA, LeaveNotification::class, fn (LeaveNotification $n): bool => $n->kind === 'schedule_counted');
    expect(array_keys(app(EmployeeLeaveProvider::class)->fullDayLeaveDates([$this->employeeA->id], Carbon::parse('2026-12-01'), Carbon::parse('2026-12-31'))[$this->employeeA->id]))
        ->toBe(['2026-12-07', '2026-12-08', '2026-12-09', '2026-12-10', '2026-12-11']);
    $this->actingAs($this->userA)->get(route('employee.leave.show', $request))->assertOk()
        ->assertInertia(fn ($page) => $page->where('leaveRequest.events.0.action', 'counted_from_schedule'));

    // From its first day an approved period can no longer be taken off the schedule.
    $late = $plan($this->userA, '2026-12-21', '2026-12-22');
    $approve($late);
    lvAt('2026-12-21', '08:00');
    $this->actingAs($this->userA)->delete(route('employee.leave.plans.destroy', $late))->assertSessionHasErrors('plan');
    // Opening My leave counts what is due for that employee, without waiting for the scheduled run.
    $this->actingAs($this->userA)->get(route('employee.leave.index'))->assertOk()->assertInertia(fn ($page) => $page->where('leaveSchedule.rows.1.request.status', 'approved'));
    expect($late->refresh()->leave_request_id)->not->toBeNull();
});

test('a planned period that cannot be counted keeps the reason and is counted once the cause is gone', function (): void {
    $this->actingAs($this->userA)->post(route('employee.leave.plans.store'), ['starts_on' => '2026-12-07', 'ends_on' => '2026-12-11'])->assertSessionHasNoErrors();
    $plan = LeavePlan::query()->sole();
    $this->actingAs($this->supervisor)->post(route('leave.plans.decide', $plan), ['decision' => 'approve'])->assertSessionHasNoErrors();
    // The balance is used up before the day comes.
    $this->actingAs($this->hr)->post(route('leave.balances.adjust', $this->employeeA), ['leave_type_id' => lvType('annual')->id, 'entitlement_year' => 2019, 'days' => -24, 'kind' => 'adjustment', 'reason' => 'Counted twice'])->assertSessionHasNoErrors();

    lvAt('2026-12-07', '00:10');
    $this->artisan('leave:start-planned')->assertSuccessful();
    expect(LeaveRequest::query()->count())->toBe(0)->and($plan->refresh()->auto_error)->not->toBeNull();
    $this->actingAs($this->hr)->get(route('leave.plans.index'))->assertOk()->assertInertia(fn ($page) => $page->where('leavePlans.data.0.auto_error', $plan->auto_error));

    // The officer corrects the balance; the next run counts the leave and clears the note.
    $this->actingAs($this->hr)->post(route('leave.balances.adjust', $this->employeeA), ['leave_type_id' => lvType('annual')->id, 'entitlement_year' => 2019, 'days' => 10, 'kind' => 'adjustment', 'reason' => 'Corrected'])->assertSessionHasNoErrors();
    $this->artisan('leave:start-planned')->assertSuccessful();
    expect(LeaveRequest::query()->count())->toBe(1)->and($plan->refresh()->auto_error)->toBeNull()->and($plan->leave_request_id)->not->toBeNull();
});

test('a request never starts before the day it is made, except for sickness and a birth', function (): void {
    foreach (['annual', 'miscellaneous', 'unpaid', 'special'] as $code) {
        $this->actingAs($this->userA)->post(route('employee.leave.store'), lvPayload($code, ['starts_on' => '2026-10-30', 'ends_on' => '2026-11-03']))->assertSessionHasErrors('starts_on');
    }
    // Today itself is allowed.
    lvAsk($this->userA, 'annual', ['starts_on' => LV_TODAY, 'ends_on' => LV_TODAY]);
    // What cannot be asked for beforehand is reported after it began.
    lvAsk($this->userA, 'paternity', ['starts_on' => '2026-10-26', 'ends_on' => '2026-10-30']);
    lvAsk($this->userB, 'sick', ['starts_on' => '2026-10-29', 'ends_on' => '2026-10-30']);

    expect(LeaveRequest::query()->count())->toBe(3)->and(LeaveRequest::query()->where('registered_by_hr', true)->count())->toBe(0);
});

// ── The settings page controls the module end to end ────────────────────────

test('the settings page shows and saves how leave works, and only its administrator may', function (): void {
    $admin = lvUser('LV Policy Admin', ['leave.settings.manage']);
    $general = ['hr_confirmation_required' => false, 'min_notice_days' => 3, 'schedule_enabled' => true, 'schedule_requires_approval' => false, 'schedule_auto_count' => false];

    $this->actingAs($admin)->get(route('leave.settings'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('leaveSettings.hr_confirmation_required', true)->where('leaveSettings.min_notice_days', 0)
            ->where('leaveSettings.schedule_requires_approval', true)->where('leaveSettings.schedule_auto_count', true)
            ->where('leaveTypes.0.allows_past_start', false)->where('leaveTypes.2.allows_past_start', true)->where('leaveTypes.0.in_use', false));
    $this->actingAs($this->hr)->put(route('leave.settings.general.update'), $general)->assertForbidden();
    $this->actingAs($admin)->put(route('leave.settings.general.update'), [...$general, 'min_notice_days' => 400])->assertSessionHasErrors('min_notice_days');
    $this->actingAs($admin)->put(route('leave.settings.general.update'), $general)->assertSessionHasNoErrors();
    $this->actingAs($admin)->get(route('leave.settings'))->assertInertia(fn ($page) => $page->where('leaveSettings.hr_confirmation_required', false)->where('leaveSettings.min_notice_days', 3));
    expect(AuditLog::query()->where('event_type', 'leave.rules_changed')->count())->toBe(1);
});

test('with HR confirmation switched off the supervisor\'s approval is final and takes the days', function (): void {
    app(LeaveSettings::class)->update(['hr_confirmation_required' => false], null);

    $request = lvAsk($this->userA);
    $this->actingAs($this->supervisor)->post(route('leave.requests.supervisor-decision', $request), ['decision' => 'approve'])->assertSessionHasNoErrors();

    expect($request->refresh()->status)->toBe(LeaveStatus::Approved)
        ->and(lvFigures($this->employeeA, 'annual'))->available->toBe(21.0)->taken->toBe(5.0)
        ->and($request->days()->where('is_approved', true)->count())->toBe(5);
    Notification::assertSentTo($this->userA, LeaveNotification::class, fn (LeaveNotification $n): bool => $n->kind === 'approved');
    Notification::assertNotSentTo($this->hr, LeaveNotification::class, fn (LeaveNotification $n): bool => $n->kind === 'confirmation_required');

    // A type that skips the supervisor still needs the HR office.
    expect(lvAsk($this->userB, 'sick', ['starts_on' => '2026-11-02', 'ends_on' => '2026-11-03'])->status)->toBe(LeaveStatus::PendingHr);
});

test('days of notice and the "may start in the past" switch of a leave type are obeyed', function (): void {
    app(LeaveSettings::class)->update(['min_notice_days' => 5], null);

    // Monday 2 November: five days of notice means Saturday 7 November at the earliest.
    $this->actingAs($this->userA)->post(route('employee.leave.store'), lvPayload('annual', ['starts_on' => '2026-11-05', 'ends_on' => '2026-11-06']))->assertSessionHasErrors('starts_on');
    lvAsk($this->userA);
    // Sickness is exempt: it may even start in the past.
    lvAsk($this->userB, 'sick', ['starts_on' => '2026-10-29', 'ends_on' => '2026-10-30']);

    // The administrator switches "may start in the past" off for sick leave and on for annual leave.
    lvType('sick')->update(['allows_past_start' => false]);
    lvType('annual')->update(['allows_past_start' => true]);
    $this->actingAs($this->userB)->post(route('employee.leave.store'), lvPayload('sick', ['starts_on' => '2026-10-26', 'ends_on' => '2026-10-27']))->assertSessionHasErrors('starts_on');
    lvAsk($this->userB, 'annual', ['starts_on' => '2026-10-26', 'ends_on' => '2026-10-27']);
});

test('the leave schedule follows its switches: off, without approval, without automatic counting', function (): void {
    $settings = app(LeaveSettings::class);
    $period = ['starts_on' => '2026-12-07', 'ends_on' => '2026-12-11'];

    // Without approval a planned period stands at once; without automatic counting nothing is counted for it.
    $settings->update(['schedule_requires_approval' => false, 'schedule_auto_count' => false], null);
    $this->actingAs($this->userA)->post(route('employee.leave.plans.store'), $period)->assertSessionHasNoErrors();
    $plan = LeavePlan::query()->sole();
    expect($plan->status)->toBe('approved');
    Notification::assertNotSentTo($this->supervisor, LeaveNotification::class, fn (LeaveNotification $n): bool => $n->kind === 'plan_submitted');
    $this->actingAs($this->userA)->get(route('employee.leave.index'))->assertInertia(fn ($page) => $page->where('leaveSchedule.auto_count', false));

    lvAt('2026-12-07', '00:10');
    $this->artisan('leave:start-planned')->assertSuccessful();
    expect(LeaveRequest::query()->count())->toBe(0);
    // The employee then asks for the planned leave as an ordinary request, and it is tied to the plan.
    $request = lvAsk($this->userA, 'annual', [...$period, 'plan_id' => $plan->id]);
    expect($plan->refresh()->leave_request_id)->toBe($request->id)->and($request->status)->toBe(LeaveStatus::PendingSupervisor);

    // Switched off altogether: nothing can be planned and My leave shows no schedule.
    $settings->update(['schedule_enabled' => false], null);
    $this->actingAs($this->userB)->post(route('employee.leave.plans.store'), ['starts_on' => '2026-12-14', 'ends_on' => '2026-12-15'])->assertSessionHasErrors('starts_on');
    $this->actingAs($this->userB)->get(route('employee.leave.index'))->assertInertia(fn ($page) => $page->where('leaveSchedule', null));
});

test('the administrator adds a leave type and changes every switch of one; the kind of a used type is fixed', function (): void {
    $admin = lvUser('LV Policy Admin', ['leave.settings.manage']);
    $new = [
        'code' => 'study', 'name_en' => 'Study leave', 'name_am' => 'የትምህርት ፈቃድ', 'category' => 'special', 'day_unit' => 'working', 'pay_class' => 'full',
        'uses_balance' => false, 'gender' => '', 'sort_order' => 80, 'allows_half_day' => false, 'allows_past_start' => false,
        'requires_supervisor_approval' => true, 'requires_document' => true, 'employee_can_request' => true, 'is_active' => true,
    ];

    $this->actingAs($this->hr)->post(route('leave.settings.types.store'), $new)->assertForbidden();
    $this->actingAs($admin)->post(route('leave.settings.types.store'), [...$new, 'code' => 'Study Leave'])->assertSessionHasErrors('code');
    $this->actingAs($admin)->post(route('leave.settings.types.store'), [...$new, 'code' => 'annual'])->assertSessionHasErrors('code');
    $this->actingAs($admin)->post(route('leave.settings.types.store'), $new)->assertSessionHasNoErrors();

    $study = lvType('study');
    expect($study->is_system)->toBeFalse()->and($study->requires_document)->toBeTrue()
        // The rule set in force has a line for it, so its limits can be entered and it can be asked for.
        ->and(LeaveRule::query()->where('leave_type_id', $study->id)->count())->toBe(1);
    $this->actingAs($admin)->get(route('leave.settings'))->assertInertia(fn ($page) => $page->has('leaveTypes', 8));
    app(LeaveRules::class)->forget();
    expect(lvAsk($this->userA, 'study')->status)->toBe(LeaveStatus::PendingSupervisor);

    // Every switch of an existing type can be changed; once used, its kind and its balance cannot.
    $edit = fn (LeaveType $type, array $changes) => [...$type->only(['name_en', 'name_am', 'day_unit', 'pay_class', 'sort_order', 'allows_half_day', 'allows_past_start', 'requires_supervisor_approval', 'requires_document', 'employee_can_request', 'is_active']), 'gender' => $type->gender ?? '', ...$changes];
    $this->actingAs($admin)->put(route('leave.settings.types.update', $study), $edit($study, ['category' => 'unpaid']))->assertSessionHasErrors('category');
    $this->actingAs($admin)->put(route('leave.settings.types.update', $study), $edit($study, ['pay_class' => 'none', 'gender' => 'female', 'is_active' => false, 'requires_supervisor_approval' => false]))->assertSessionHasNoErrors();
    expect($study->refresh())->pay_class->toBe('none')->gender->toBe('female')->is_active->toBeFalse()->requires_supervisor_approval->toBeFalse();
    // A type nobody has used yet may still change its kind.
    $this->actingAs($admin)->put(route('leave.settings.types.update', lvType('unpaid')), $edit(lvType('unpaid'), ['category' => 'special']))->assertSessionHasNoErrors();
    expect(lvType('unpaid')->category->value)->toBe('special');
});
