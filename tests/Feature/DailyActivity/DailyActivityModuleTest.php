<?php

declare(strict_types=1);

use App\Contracts\EmployeeLeaveProvider;
use App\Enums\DailyActivityDayStatus;
use App\Enums\DailyActivityHistoryAction;
use App\Enums\DailyActivityStatus;
use App\Exports\DailyActivity\DailyActivityReportExport;
use App\Models\AuditLog;
use App\Models\DailyActivityLog;
use App\Models\DailyActivityReminder;
use App\Models\DailyActivityReviewerAssignment;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionService;
use App\Models\PublicHoliday;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\Calendar\CalendarService;
use App\Services\Calendar\LocalizedDateService;
use App\Services\DailyActivity\DailyActivityCalendarService;
use App\Services\DailyActivity\DailyActivityCoverage;
use App\Services\DailyActivity\DailyActivityReminderService;
use App\Services\DailyActivity\DailyActivityReportService;
use App\Services\DailyActivity\DailyActivityService;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use App\Support\DailyActivity\DailyActivityRoles;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Daily Activity Register
|--------------------------------------------------------------------------
|
| "Today" is Wednesday 23 September 2026, 10:00 in Addis Ababa. The week:
|   Fri 18  Sat 19  Sun 20  Mon 21  Tue 22  Wed 23  Thu 24
| Saturday and Sunday are non-working under the default Mon-Fri week.
| Tracking starts 1 September 2026.
|
| Daily activity is a work record: nothing here checks attendance or
| derives a performance score, and the tests assert neither exists.
*/

const DA_TODAY = '2026-09-23';

function daSetting(string $key, mixed $value): void
{
    $definition = SystemSettingsRegistry::definition(SystemSettingsRegistry::GROUP_DAILY_ACTIVITY, $key);

    SystemSetting::query()->updateOrCreate(
        ['group' => SystemSettingsRegistry::GROUP_DAILY_ACTIVITY, 'key' => $key],
        [
            'value' => is_array($value) ? json_encode($value) : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value),
            'type' => $definition['type'],
            'label_en' => $definition['label_en'],
        ],
    );

    app(SystemSettingsService::class)->clearCache();
}

function daAt(string $time, string $date = DA_TODAY): void
{
    Carbon::setTestNow(Carbon::parse("{$date} {$time}:00", 'Africa/Addis_Ababa'));
}

/** @param array<int, string> $permissions */
function daUser(string $role, array $permissions, ?string $email = null): User
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

function daEmployee(string $number, string $email, Organization $org, OrganizationUnit $unit, Position $position, string $from = '2026-01-01'): Employee
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
        'effective_from' => $from,
        'is_current' => true,
    ]);

    $employee->update(['current_assignment_id' => $assignment->id]);

    return $employee->fresh();
}

/** @return array<int, array<string, string>> */
function daItems(int $count = 1): array
{
    return array_map(static fn (int $i): array => [
        'title' => "Activity {$i}",
        'description' => "Work performed {$i}",
        'output_result' => "Output {$i}",
        'progress_status' => 'completed',
    ], range(1, $count));
}

/** @param array<string, mixed> $extra */
function daPost(User $user, string $date, array $items, string $action = 'draft', array $extra = [])
{
    return test()->actingAs($user)->post(route('employee.daily-activity.save', $date), [
        'items' => $items,
        'action' => $action,
        ...$extra,
    ]);
}

function daLog(Employee $employee, string $date): ?DailyActivityLog
{
    return DailyActivityLog::query()->where('employee_id', $employee->id)->onDate($date)->first();
}

/** Test double: named employees on approved full-day leave. */
function daFakeLeave(array $leave): void
{
    app()->bind(EmployeeLeaveProvider::class, fn () => new class($leave) implements EmployeeLeaveProvider
    {
        public function __construct(private array $leave) {}

        public function fullDayLeaveDates(array $employeeIds, Carbon $from, Carbon $to): array
        {
            return array_intersect_key($this->leave, array_flip($employeeIds));
        }
    });
}

beforeEach(function (): void {
    daAt('10:00');
    daSetting('tracking_start_date', '2026-09-01');

    $type = OrganizationType::query()->create(['code' => 'DA-TYPE', 'name_en' => 'Bureau']);
    $this->org = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'DA-ORG', 'name_en' => 'Civil Service Bureau', 'name_am' => 'ሲቪል ሰርቪስ ቢሮ', 'status' => 'active']);
    $this->otherOrg = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'DA-OTHER', 'name_en' => 'Health Bureau', 'status' => 'active']);

    $this->unit = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'code' => 'DA-HR', 'name_en' => 'HR Directorate', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->subUnit = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'parent_unit_id' => $this->unit->id, 'code' => 'DA-HR-REC', 'name_en' => 'Records Team', 'unit_type' => 'team', 'status' => 'active']);
    $this->otherUnit = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'code' => 'DA-FIN', 'name_en' => 'Finance Directorate', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->foreignUnit = OrganizationUnit::query()->create(['organization_id' => $this->otherOrg->id, 'code' => 'DA-HB', 'name_en' => 'Health HR', 'unit_type' => 'directorate', 'status' => 'active']);

    $this->position = Position::query()->create(['organization_id' => $this->org->id, 'organization_unit_id' => $this->subUnit->id, 'job_position_code' => 'DA-P1', 'title_en' => 'HR Officer', 'is_active' => true]);
    $this->financePosition = Position::query()->create(['organization_id' => $this->org->id, 'organization_unit_id' => $this->otherUnit->id, 'job_position_code' => 'DA-P2', 'title_en' => 'Accountant', 'is_active' => true]);
    $this->foreignPosition = Position::query()->create(['organization_id' => $this->otherOrg->id, 'organization_unit_id' => $this->foreignUnit->id, 'job_position_code' => 'DA-P3', 'title_en' => 'Clerk', 'is_active' => true]);

    $this->task = PositionService::query()->create(['organization_id' => $this->org->id, 'position_id' => $this->position->id, 'service_no' => 1, 'name_en' => 'Employee transfer processing', 'is_active' => true]);

    $this->employeeA = daEmployee('DA-A', 'a@da.test', $this->org, $this->subUnit, $this->position);
    $this->employeeB = daEmployee('DA-B', 'b@da.test', $this->org, $this->subUnit, $this->position);
    $this->employeeF = daEmployee('DA-F', 'f@da.test', $this->otherOrg, $this->foreignUnit, $this->foreignPosition);

    $this->userA = daUser(DailyActivityRoles::EMPLOYEE_ROLE, [], 'a@da.test');
    $this->userB = daUser(DailyActivityRoles::EMPLOYEE_ROLE, [], 'b@da.test');
    $this->userF = daUser(DailyActivityRoles::EMPLOYEE_ROLE, [], 'f@da.test');

    // Reviewer of the HR directorate and everything beneath it.
    $this->reviewer = daUser(DailyActivityRoles::REVIEWER_ROLE, []);
    DailyActivityReviewerAssignment::query()->create(['reviewer_user_id' => $this->reviewer->id, 'organization_id' => $this->org->id, 'organization_unit_id' => $this->unit->id, 'include_sub_units' => true, 'is_active' => true]);

    // Reviewer of a different unit of the same organization.
    $this->financeReviewer = daUser('DA Finance Reviewer', DailyActivityRoles::REVIEWER_PERMISSIONS);
    DailyActivityReviewerAssignment::query()->create(['reviewer_user_id' => $this->financeReviewer->id, 'organization_id' => $this->org->id, 'organization_unit_id' => $this->otherUnit->id, 'include_sub_units' => true, 'is_active' => true]);

    // Organization-scoped oversight of the Civil Service Bureau only.
    $this->orgAdmin = daUser('DA Org Oversight', DailyActivityRoles::ORGANIZATIONAL_ADMIN_PERMISSIONS);
    UserOrganizationScope::query()->create(['user_id' => $this->orgAdmin->id, 'organization_id' => $this->org->id, 'scope_type' => 'self', 'is_active' => true]);
    app(OrganizationScopeService::class)->clearCache();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

// ── Employee ────────────────────────────────────────────────────────────────

test('1. an active employee can register today\'s activity with the work context snapshot', function (): void {
    daPost($this->userA, DA_TODAY, daItems())->assertRedirect()->assertSessionHasNoErrors();

    $log = daLog($this->employeeA, DA_TODAY);

    expect($log)->not->toBeNull()
        ->and($log->status)->toBe(DailyActivityStatus::Draft)
        ->and($log->organization_id)->toBe($this->org->id)
        ->and($log->organization_unit_id)->toBe($this->subUnit->id)
        ->and($log->position_id)->toBe($this->position->id)
        ->and(AuditLog::query()->where('event_type', 'daily_activity.created')->exists())->toBeTrue();
});

test('2. an employee can add multiple activity items to one daily log, including a related task', function (): void {
    $items = daItems(3);
    $items[0]['position_service_id'] = $this->task->id;
    $items[1]['activity_category'] = 'meeting';

    daPost($this->userA, DA_TODAY, $items, 'submit')->assertSessionHasNoErrors();

    $log = daLog($this->employeeA, DA_TODAY);
    expect($log->status)->toBe(DailyActivityStatus::Submitted)
        ->and($log->items)->toHaveCount(3)
        ->and($log->items[0]->position_service_id)->toBe($this->task->id)
        ->and(DailyActivityLog::query()->where('employee_id', $this->employeeA->id)->count())->toBe(1);
});

test('2b. a task that is not a service of the employee\'s position is refused', function (): void {
    $foreignTask = PositionService::query()->create(['organization_id' => $this->otherOrg->id, 'position_id' => $this->foreignPosition->id, 'service_no' => 9, 'name_en' => 'Foreign service', 'is_active' => true]);
    $items = daItems();
    $items[0]['position_service_id'] = $foreignTask->id;

    daPost($this->userA, DA_TODAY, $items)->assertSessionHasErrors('items');
});

test('3. an employee cannot create a duplicate daily log for the same date', function (): void {
    daPost($this->userA, DA_TODAY, daItems(1));
    daPost($this->userA, DA_TODAY, daItems(2));

    expect(DailyActivityLog::query()->where('employee_id', $this->employeeA->id)->count())->toBe(1)
        ->and(daLog($this->employeeA, DA_TODAY)->items)->toHaveCount(2);

    // The database itself refuses a second header.
    $log = daLog($this->employeeA, DA_TODAY);
    expect(fn () => DB::table('daily_activity_logs')->insert([
        'id' => (string) Str::uuid7(),
        'employee_id' => $this->employeeA->id,
        'organization_id' => $this->org->id,
        'activity_date' => $log->getRawOriginal('activity_date'),
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('4. an employee cannot view, submit or attach evidence to another employee\'s activity', function (): void {
    daPost($this->userB, DA_TODAY, daItems());
    $logB = daLog($this->employeeB, DA_TODAY);

    $this->actingAs($this->userA)->get(route('daily-activities.show', $logB))->assertForbidden();
    $this->actingAs($this->userA)
        ->post(route('employee.daily-activity.attachments.store', $logB), ['file' => UploadedFile::fake()->create('e.pdf', 10, 'application/pdf')])
        ->assertForbidden();

    // Saving always targets the signed-in employee, so B's log is untouched.
    daPost($this->userA, DA_TODAY, daItems(2), 'submit');
    expect($logB->fresh()->status)->toBe(DailyActivityStatus::Draft)
        ->and($logB->fresh()->items)->toHaveCount(1);
});

test('5. a future date is rejected', function (): void {
    daPost($this->userA, '2026-09-24', daItems())->assertSessionHasErrors('date');

    expect(DailyActivityLog::query()->count())->toBe(0);
});

test('6. backdated registration follows the settings', function (): void {
    daSetting('max_backdate_days', 3);

    // Monday, two days back: allowed.
    daPost($this->userA, '2026-09-21', daItems())->assertSessionHasNoErrors();
    // Friday, five days back: outside the window.
    daPost($this->userA, '2026-09-18', daItems())->assertSessionHasErrors('date');

    daSetting('allow_backdated_submission', false);
    daPost($this->userA, '2026-09-22', daItems())->assertSessionHasErrors('date');

    expect(DailyActivityLog::query()->count())->toBe(1);
});

test('7. a submission after the deadline is marked late and needs a reason', function (): void {
    daAt('09:30');
    daPost($this->userA, DA_TODAY, daItems(), 'submit')->assertSessionHasNoErrors();
    expect(daLog($this->employeeA, DA_TODAY)->is_late)->toBeFalse();

    daAt('19:15');
    daPost($this->userB, DA_TODAY, daItems(), 'submit')->assertSessionHasErrors('late_reason');
    // The failed submit is atomic: nothing is half-saved.
    expect(daLog($this->employeeB, DA_TODAY))->toBeNull();

    daPost($this->userB, DA_TODAY, daItems(), 'submit', ['late_reason' => 'Field visit ran late.'])->assertSessionHasNoErrors();
    expect(daLog($this->employeeB, DA_TODAY)->is_late)->toBeTrue()
        ->and(daLog($this->employeeB, DA_TODAY)->status)->toBe(DailyActivityStatus::Submitted);
});

test('7b. late submissions are refused only when the setting explicitly says so', function (): void {
    daSetting('reject_late_submission', true);
    daAt('19:15');

    daPost($this->userA, DA_TODAY, daItems(), 'submit', ['late_reason' => 'Late'])->assertSessionHasErrors('late_reason');
});

test('8. an approved full-day leave day does not require submission', function (): void {
    daFakeLeave([$this->employeeA->id => ['2026-09-22' => true]]);
    $calendar = app(DailyActivityCalendarService::class);

    expect($calendar->dayStatus($this->employeeA, Carbon::parse('2026-09-22')))->toBe(DailyActivityDayStatus::Leave);

    daPost($this->userA, '2026-09-22', daItems())->assertSessionHasErrors('date');

    $summary = $calendar->summaries(collect([$this->employeeA]), Carbon::parse('2026-09-22'), Carbon::parse('2026-09-22'))[$this->employeeA->id];
    expect($summary['missing'])->toBe(0)->and($summary['leave'])->toBe(1)->and($summary['required'])->toBe(0);
});

test('9. a public holiday does not require submission, including recurring Ethiopian holidays', function (): void {
    PublicHoliday::query()->create(['name_en' => 'Test Holiday', 'holiday_date' => '2026-09-21', 'is_recurring' => false, 'is_active' => true]);
    // Meskel is stored once, in an earlier year, as an Ethiopian recurring
    // holiday (Meskerem 17 = 27 Sep 2025); it must fall on 27 Sep 2026 too.
    PublicHoliday::query()->create(['name_en' => 'Meskel', 'holiday_date' => '2025-09-27', 'is_recurring' => true, 'recurrence_type' => 'ethiopian', 'is_active' => true]);

    $calendar = app(DailyActivityCalendarService::class);

    expect($calendar->dayStatus($this->employeeA, Carbon::parse('2026-09-21')))->toBe(DailyActivityDayStatus::PublicHoliday);
    daPost($this->userA, '2026-09-21', daItems())->assertSessionHasErrors('date');

    $days = collect($calendar->days($this->employeeA, Carbon::parse('2026-09-27'), Carbon::parse('2026-09-27')));
    expect($days->first()['holiday_name_en'])->toBe('Meskel');
});

test('10. a configured non-working weekend day does not require submission', function (): void {
    expect(app(DailyActivityCalendarService::class)->dayStatus($this->employeeA, Carbon::parse('2026-09-20')))
        ->toBe(DailyActivityDayStatus::Weekend);

    daPost($this->userA, '2026-09-20', daItems())->assertSessionHasErrors('date');

    // Make Sunday a working day and it becomes registrable.
    daSetting('work_week_days', ['1', '2', '3', '4', '5', '7']);
    daPost($this->userA, '2026-09-20', daItems())->assertSessionHasNoErrors();
});

test('11. a draft does not count as submitted and is missing once its day has passed', function (): void {
    daPost($this->userA, '2026-09-22', daItems());

    $summary = app(DailyActivityCalendarService::class)
        ->summaries(collect([$this->employeeA]), Carbon::parse('2026-09-22'), Carbon::parse('2026-09-22'))[$this->employeeA->id];

    expect($summary['submitted'])->toBe(0)
        ->and($summary['draft'])->toBe(1)
        ->and($summary['missing'])->toBe(1)
        ->and($summary['missing_dates'])->toBe(['2026-09-22']);
});

test('12. a submitted activity cannot be edited by the employee', function (): void {
    daPost($this->userA, DA_TODAY, daItems(1), 'submit');

    daPost($this->userA, DA_TODAY, daItems(3))->assertSessionHasErrors('status');

    expect(daLog($this->employeeA, DA_TODAY)->items)->toHaveCount(1);
});

test('12b. submitting twice is idempotent', function (): void {
    daPost($this->userA, DA_TODAY, daItems(1), 'submit');
    daPost($this->userA, DA_TODAY, daItems(1), 'submit')->assertSessionHasNoErrors();

    $log = daLog($this->employeeA, DA_TODAY);
    expect($log->submission_count)->toBe(1)
        ->and($log->histories()->where('action', DailyActivityHistoryAction::Submitted->value)->count())->toBe(1);
});

test('13. a returned activity can be corrected and resubmitted without losing the original submission', function (): void {
    daPost($this->userA, DA_TODAY, daItems(1), 'submit');
    $log = daLog($this->employeeA, DA_TODAY);

    $this->actingAs($this->reviewer)
        ->post(route('daily-activities.return', $log), ['comment' => 'Please clarify the output of activity 1.'])
        ->assertSessionHasNoErrors();
    expect($log->fresh()->status)->toBe(DailyActivityStatus::ReturnedForCorrection);

    $corrected = daItems(1);
    $corrected[0]['output_result'] = 'Twelve transfer files cleared and filed.';
    daPost($this->userA, DA_TODAY, $corrected, 'submit')->assertSessionHasNoErrors();

    $log = $log->fresh();
    $history = $log->histories()->get();
    $original = $history->firstWhere('action', DailyActivityHistoryAction::Submitted);
    $resubmission = $history->firstWhere('action', DailyActivityHistoryAction::Resubmitted);

    expect($log->status)->toBe(DailyActivityStatus::Resubmitted)
        ->and($log->submission_count)->toBe(2)
        ->and($original->snapshot[0]['output_result'])->toBe('Output 1')
        ->and($resubmission->snapshot[0]['output_result'])->toBe('Twelve transfer files cleared and filed.')
        ->and($history->firstWhere('action', DailyActivityHistoryAction::Returned)->comment)->toBe('Please clarify the output of activity 1.');
});

test('13b. correcting a returned day is allowed even when it is outside the backdate window', function (): void {
    daAt('10:00', '2026-09-21');
    daPost($this->userA, '2026-09-21', daItems(), 'submit');
    $log = daLog($this->employeeA, '2026-09-21');
    $this->actingAs($this->reviewer)->post(route('daily-activities.return', $log), ['comment' => 'Add the output please.']);

    daAt('10:00', '2026-10-05');
    daPost($this->userA, '2026-09-21', daItems(), 'submit')->assertSessionHasNoErrors();
    expect($log->fresh()->status)->toBe(DailyActivityStatus::Resubmitted);
});

test('14. an approved activity is immutable until formally reopened with a reason', function (): void {
    daPost($this->userA, DA_TODAY, daItems(1), 'submit');
    $log = daLog($this->employeeA, DA_TODAY);
    $this->actingAs($this->reviewer)->post(route('daily-activities.approve', $log))->assertSessionHasNoErrors();

    daPost($this->userA, DA_TODAY, daItems(2))->assertSessionHasErrors('status');
    expect($log->fresh()->items)->toHaveCount(1);

    // Reopen needs a reason and the reopen permission.
    $this->actingAs($this->orgAdmin)->post(route('daily-activities.reopen', $log), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->reviewer)->post(route('daily-activities.reopen', $log), ['reason' => 'Wrong unit recorded.'])->assertForbidden();

    $this->actingAs($this->orgAdmin)->post(route('daily-activities.reopen', $log), ['reason' => 'Output figures need correcting.'])->assertSessionHasNoErrors();

    $log = $log->fresh();
    expect($log->status)->toBe(DailyActivityStatus::ReturnedForCorrection)
        ->and($log->reopened_by)->toBe($this->orgAdmin->id)
        ->and($log->reopen_reason)->toBe('Output figures need correcting.')
        ->and(AuditLog::query()->where('event_type', 'daily_activity.reopened')->where('reason', 'Output figures need correcting.')->exists())->toBeTrue()
        ->and($log->histories()->where('action', DailyActivityHistoryAction::Approved->value)->exists())->toBeTrue();
});

// ── Manager ─────────────────────────────────────────────────────────────────

test('15. an assigned reviewer sees the team\'s submissions in the review queue', function (): void {
    daPost($this->userA, DA_TODAY, daItems(), 'submit');

    $this->actingAs($this->reviewer)
        ->get(route('daily-activities.review-queue'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('DailyActivities/ReviewQueue')
            ->where('logs.meta.total', 1)
            ->where('logs.data.0.employee.employee_number', 'DA-A'));
});

test('16. a reviewer cannot see or review outside their assigned units', function (): void {
    daPost($this->userA, DA_TODAY, daItems(), 'submit');
    $log = daLog($this->employeeA, DA_TODAY);

    $this->actingAs($this->financeReviewer)
        ->get(route('daily-activities.review-queue'))
        ->assertInertia(fn (Assert $page) => $page->where('logs.meta.total', 0));

    $this->actingAs($this->financeReviewer)->get(route('daily-activities.show', $log))->assertForbidden();
    $this->actingAs($this->financeReviewer)->post(route('daily-activities.approve', $log))->assertForbidden();

    expect($log->fresh()->status)->toBe(DailyActivityStatus::Submitted);
});

test('16b. nobody reviews their own daily activity', function (): void {
    // The HR reviewer is also an employee of the unit they review.
    daEmployee('DA-R', $this->reviewer->email, $this->org, $this->subUnit, $this->position);
    $this->reviewer->assignRole(DailyActivityRoles::EMPLOYEE_ROLE);
    $this->reviewer->forgetCachedPermissions();

    daPost($this->reviewer, DA_TODAY, daItems(), 'submit');
    $own = DailyActivityLog::query()->whereHas('employee', fn ($q) => $q->where('employee_number', 'DA-R'))->firstOrFail();

    $this->actingAs($this->reviewer)->post(route('daily-activities.approve', $own))->assertForbidden();
    expect($own->fresh()->status)->toBe(DailyActivityStatus::Submitted);
});

test('17. an assigned reviewer can approve', function (): void {
    daPost($this->userA, DA_TODAY, daItems(), 'submit');
    $log = daLog($this->employeeA, DA_TODAY);

    $this->actingAs($this->reviewer)
        ->post(route('daily-activities.approve', $log), ['comment' => 'Good.'])
        ->assertRedirect(route('daily-activities.review-queue'));

    $log = $log->fresh();
    expect($log->status)->toBe(DailyActivityStatus::Approved)
        ->and($log->reviewed_by)->toBe($this->reviewer->id)
        ->and(AuditLog::query()->where('event_type', 'daily_activity.approved')->exists())->toBeTrue();
});

test('18. returning for correction requires a comment and notifies the employee', function (): void {
    daPost($this->userA, DA_TODAY, daItems(), 'submit');
    $log = daLog($this->employeeA, DA_TODAY);

    $this->actingAs($this->reviewer)->post(route('daily-activities.return', $log), ['comment' => ''])->assertSessionHasErrors('comment');
    expect($log->fresh()->status)->toBe(DailyActivityStatus::Submitted);

    $this->actingAs($this->reviewer)->post(route('daily-activities.return', $log), [
        'comment' => 'Please clarify the output produced for activity #1.',
        'item_notes' => [$log->items[0]->id => 'Which files?'],
    ])->assertSessionHasNoErrors();

    expect($log->fresh()->status)->toBe(DailyActivityStatus::ReturnedForCorrection)
        ->and($log->items()->first()->reviewer_note)->toBe('Which files?')
        ->and($this->userA->notifications()->count())->toBe(1)
        ->and($this->userA->notifications()->first()->data['kind'])->toBe('returned');
});

// ── Scope ───────────────────────────────────────────────────────────────────

test('19. an organization user sees only employees inside their organization scope', function (): void {
    daPost($this->userA, DA_TODAY, daItems(), 'submit');
    daPost($this->userF, DA_TODAY, daItems(), 'submit');

    $this->actingAs($this->orgAdmin)
        ->get(route('daily-activities.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('logs.meta.total', 1)
            ->where('logs.data.0.employee.employee_number', 'DA-A'));

    // A filter pointing at the other organization narrows to nothing.
    $this->actingAs($this->orgAdmin)
        ->get(route('daily-activities.index', ['organization_id' => $this->otherOrg->id]))
        ->assertInertia(fn (Assert $page) => $page->where('logs.meta.total', 0));
});

test('20. out-of-scope activity is refused by id (IDOR)', function (): void {
    daPost($this->userF, DA_TODAY, daItems(), 'submit');
    daPost($this->userB, DA_TODAY, daItems(), 'submit');
    $foreign = daLog($this->employeeF, DA_TODAY);
    $colleague = daLog($this->employeeB, DA_TODAY);

    $this->actingAs($this->orgAdmin)->get(route('daily-activities.show', $foreign))->assertForbidden();
    $this->actingAs($this->userA)->get(route('daily-activities.show', $colleague))->assertForbidden();

    $foreign->attachments()->create(['original_name' => 'x.pdf', 'file_path' => 'daily-activity/x.pdf', 'mime_type' => 'application/pdf', 'file_size' => 1]);
    $this->actingAs($this->orgAdmin)->get(route('daily-activities.attachments.download', $foreign->attachments()->first()))->assertForbidden();
});

// ── Calendar ────────────────────────────────────────────────────────────────

test('21. calendar states are calculated for every kind of day', function (): void {
    PublicHoliday::query()->create(['name_en' => 'Test Holiday', 'holiday_date' => '2026-09-21', 'is_active' => true]);
    daFakeLeave([$this->employeeA->id => ['2026-09-22' => true]]);
    daAt('10:00', '2026-09-17');
    daPost($this->userA, '2026-09-17', daItems(), 'submit');
    daAt('10:00');

    $this->actingAs($this->userA)
        ->get(route('employee.daily-activity.calendar', ['from' => '2026-08-31', 'to' => '2026-09-24']))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $page->component('Employee/DailyActivity/Calendar');
            $days = collect($page->toArray()['props']['days'])->keyBy('date');

            expect($days['2026-08-31']['status'])->toBe('not_tracked')
                ->and($days['2026-09-17']['status'])->toBe('submitted')
                ->and($days['2026-09-18']['status'])->toBe('missing')
                ->and($days['2026-09-19']['status'])->toBe('weekend')
                ->and($days['2026-09-20']['status'])->toBe('weekend')
                ->and($days['2026-09-21']['status'])->toBe('public_holiday')
                ->and($days['2026-09-22']['status'])->toBe('leave')
                ->and($days['2026-09-23']['status'])->toBe('required')
                ->and($days['2026-09-24']['status'])->toBe('future');
        });
});

test('21b. days outside employment or assignment are not required', function (): void {
    $late = daEmployee('DA-NEW', 'new@da.test', $this->org, $this->subUnit, $this->position, '2026-09-22');
    $calendar = app(DailyActivityCalendarService::class);

    expect($calendar->dayStatus($late, Carbon::parse('2026-09-21')))->toBe(DailyActivityDayStatus::NotAssigned)
        ->and($calendar->dayStatus($late, Carbon::parse('2026-09-22')))->toBe(DailyActivityDayStatus::Missing);

    $this->employeeB->update(['status' => 'terminated']);
    expect($calendar->dayStatus($this->employeeB->fresh(), Carbon::parse('2026-09-22')))->toBe(DailyActivityDayStatus::NotEmployed);
});

test('22. English displays an activity date in the Gregorian calendar', function (): void {
    expect(app(CalendarService::class)->formatDate(DA_TODAY, 'en'))->toBe('September 23, 2026');
});

test('23. Amharic displays the same activity date in the Ethiopian calendar, including in exports', function (): void {
    $amharic = app(CalendarService::class)->formatDate(DA_TODAY, 'am');
    expect($amharic)->toContain('2019')->not->toContain('2026');

    app()->setLocale('am');
    $export = new DailyActivityReportExport(['columns' => ['date'], 'rows' => [['date' => DA_TODAY]]], app(LocalizedDateService::class));
    expect($export->array()[0][0])->toBe($amharic);
    app()->setLocale('en');
});

test('24. switching locale never creates a second record for the same date', function (): void {
    daPost($this->userA, DA_TODAY, daItems(1));

    $this->withSession(['locale' => 'am']);
    daPost($this->userA, DA_TODAY, daItems(2));

    expect(DailyActivityLog::query()->where('employee_id', $this->employeeA->id)->count())->toBe(1)
        ->and(daLog($this->employeeA, DA_TODAY)->activityDateString())->toBe(DA_TODAY);
});

// ── Reports ─────────────────────────────────────────────────────────────────

test('25. the missing report excludes leave, holidays and non-working days', function (): void {
    PublicHoliday::query()->create(['name_en' => 'Test Holiday', 'holiday_date' => '2026-09-21', 'is_active' => true]);
    daFakeLeave([$this->employeeA->id => ['2026-09-22' => true]]);

    $report = app(DailyActivityReportService::class)->build(
        'missing',
        new DailyActivityCoverage(organizationIds: [$this->org->id]),
        ['date_from' => '2026-09-14', 'date_to' => DA_TODAY, 'employee_id' => $this->employeeA->id],
        5000,
    );

    $dates = collect($report['rows'])->where('employee_number', 'DA-A')->pluck('date')->sort()->values()->all();

    // 14-18 are missing; 19/20 weekend, 21 holiday, 22 leave, 23 is today (still open).
    expect($dates)->toBe(['2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18']);
});

test('26. exports respect organization scope and are audited', function (): void {
    daPost($this->userA, DA_TODAY, daItems(), 'submit');
    daPost($this->userF, DA_TODAY, daItems(), 'submit');

    $response = $this->actingAs($this->orgAdmin)
        ->get(route('daily-activities.reports.export', ['type' => 'review_status', 'format' => 'csv', 'date_from' => DA_TODAY, 'date_to' => DA_TODAY]))
        ->assertOk();

    $csv = $response->streamedContent() ?: file_get_contents($response->baseResponse->getFile()->getPathname());

    expect($csv)->toContain('DA-A')->not->toContain('DA-F')
        ->and(AuditLog::query()->where('event_type', 'export_performed')->exists())->toBeTrue();

    // An employee with no reporting permission cannot export at all.
    $this->actingAs($this->userA)
        ->get(route('daily-activities.reports.export', ['type' => 'review_status', 'format' => 'csv']))
        ->assertForbidden();
});

// ── Security ────────────────────────────────────────────────────────────────

test('27. employee_id tampering is rejected', function (): void {
    daPost($this->userA, DA_TODAY, daItems(), 'draft', ['employee_id' => $this->employeeB->id])
        ->assertSessionHasErrors('employee_id');

    expect(DailyActivityLog::query()->count())->toBe(0);
});

test('28. organization, unit and position tampering is rejected', function (): void {
    daPost($this->userA, DA_TODAY, daItems(), 'draft', ['organization_id' => $this->otherOrg->id])->assertSessionHasErrors('organization_id');
    daPost($this->userA, DA_TODAY, daItems(), 'draft', ['organization_unit_id' => $this->foreignUnit->id])->assertSessionHasErrors('organization_unit_id');
    daPost($this->userA, DA_TODAY, daItems(), 'draft', ['position_id' => $this->foreignPosition->id])->assertSessionHasErrors('position_id');

    expect(DailyActivityLog::query()->count())->toBe(0);
});

test('29. status and reviewer fields cannot be mass assigned', function (): void {
    daPost($this->userA, DA_TODAY, daItems(), 'draft', ['status' => 'approved', 'reviewed_by' => $this->reviewer->id])
        ->assertSessionHasErrors(['status', 'reviewed_by']);

    $items = daItems();
    $items[0]['status'] = 'approved';
    $items[0]['reviewer_note'] = 'self-approved';
    daPost($this->userA, DA_TODAY, $items)->assertSessionHasErrors(['items.0.status', 'items.0.reviewer_note']);

    // And at the model layer, fill() cannot reach workflow columns.
    $log = (new DailyActivityLog)->fill(['status' => 'approved', 'reviewed_by' => 1, 'organization_id' => 'x', 'employee_id' => 'y']);
    expect($log->getAttributes())->toBe([]);
});

// ── Concurrency ─────────────────────────────────────────────────────────────

test('30. racing creations for the same employee and date produce one daily record', function (): void {
    // Simulate the other request winning the race: after this request has
    // checked that no header exists, and just before it inserts one, a
    // concurrent request inserts the same employee + date.
    $employee = $this->employeeA;
    $org = $this->org;
    $raced = false;
    DB::connection()->beforeExecuting(function (string $sql) use (&$raced, $employee, $org): void {
        if ($raced || ! str_contains($sql, 'insert') || ! str_contains($sql, 'daily_activity_logs')) {
            return;
        }
        $raced = true;
        DB::table('daily_activity_logs')->insert([
            'id' => (string) Str::uuid7(),
            'employee_id' => $employee->id,
            'organization_id' => $org->id,
            'activity_date' => (new DailyActivityLog)->fromDateTime(Carbon::parse(DA_TODAY)),
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $log = app(DailyActivityService::class)->save($this->userA, $employee, Carbon::parse(DA_TODAY), ['items' => daItems(2)], true);

    expect($raced)->toBeTrue()
        ->and(DailyActivityLog::query()->where('employee_id', $employee->id)->count())->toBe(1)
        ->and($log->status)->toBe(DailyActivityStatus::Submitted)
        ->and($log->items)->toHaveCount(2);
});

// ── Reminders ───────────────────────────────────────────────────────────────

test('31. reminders go out once for missing days and never for leave or holidays', function (): void {
    daFakeLeave([$this->employeeB->id => [DA_TODAY => true]]);
    daPost($this->userA, DA_TODAY, daItems(), 'submit');

    $reminders = app(DailyActivityReminderService::class);
    $sent = $reminders->sendDue(Carbon::parse(DA_TODAY.' 17:30:00', 'Africa/Addis_Ababa'));

    $endOfDay = fn (User $user): int => $user->notifications()->get()->where('data.kind', 'end_of_day')->count();

    // A submitted today, B is on leave today, F has not submitted.
    expect($sent['end_of_day'])->toBe(1)
        ->and($endOfDay($this->userF))->toBe(1)
        ->and($endOfDay($this->userA))->toBe(0)
        ->and($endOfDay($this->userB))->toBe(0);

    $again = $reminders->sendDue(Carbon::parse(DA_TODAY.' 17:45:00', 'Africa/Addis_Ababa'));
    expect($again['end_of_day'])->toBe(0)
        ->and(DailyActivityReminder::query()->where('reminder_type', 'end_of_day')->count())->toBe(1);

    // On a public holiday nothing is sent at all.
    PublicHoliday::query()->create(['name_en' => 'Holiday', 'holiday_date' => '2026-09-24', 'is_active' => true]);
    $holiday = app(DailyActivityReminderService::class)->sendDue(Carbon::parse('2026-09-24 17:30:00', 'Africa/Addis_Ababa'));
    expect($holiday['end_of_day'])->toBe(0);
});

// ── Pages ───────────────────────────────────────────────────────────────────

test('the employee entry, calendar and history pages render', function (): void {
    daPost($this->userA, DA_TODAY, daItems(2));

    $this->actingAs($this->userA)->get(route('employee.daily-activity.entry'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employee/DailyActivity/Entry')
            ->where('date', DA_TODAY)
            ->where('day_status', 'draft')
            ->where('can.edit', true)
            ->has('log.items', 2)
            ->has('tasks', 1));

    $this->actingAs($this->userA)->get(route('employee.daily-activity.history'))->assertOk();
});

test('the management pages render for authorised users and refuse employees', function (): void {
    daPost($this->userA, DA_TODAY, daItems(), 'submit');

    foreach (['daily-activities.dashboard', 'daily-activities.index', 'daily-activities.missing', 'daily-activities.reports', 'daily-activities.settings'] as $route) {
        $this->actingAs($this->orgAdmin)->get(route($route))->assertOk();
        $this->actingAs($this->userA)->get(route($route))->assertForbidden();
    }

    foreach (DailyActivityReportService::TYPES as $type) {
        $this->actingAs($this->orgAdmin)->get(route('daily-activities.reports', ['type' => $type]))->assertOk();
    }
});

test('calendar opens around today and preserves an explicit day across calendar realignment', function (): void {
    $this->actingAs($this->userA)->get(route('employee.daily-activity.calendar'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('anchor', DA_TODAY));

    $this->get(route('employee.daily-activity.calendar', [
        'from' => '2026-09-11', 'to' => '2026-10-10', 'anchor' => DA_TODAY,
    ]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('anchor', DA_TODAY)->where('from', '2026-09-11')->where('to', '2026-10-10'));

    $this->get(route('employee.daily-activity.calendar', ['from' => '2026-08-01']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('anchor', '2026-08-01'));
});

test('unknown save actions are rejected without creating a draft', function (): void {
    daPost($this->userA, DA_TODAY, daItems(), 'publish')->assertSessionHasErrors('action');
    expect(daLog($this->employeeA, DA_TODAY))->toBeNull();
});

test('editing saved time details recomputes duration when the form clears its derived value', function (): void {
    $items = daItems();
    $items[0] = [...$items[0], 'started_at' => '09:00', 'ended_at' => '10:00'];
    daPost($this->userA, DA_TODAY, $items)->assertSessionHasNoErrors();
    $item = daLog($this->employeeA, DA_TODAY)->items()->first();
    expect($item->duration_minutes)->toBe(60);

    $items[0] = [...$items[0], 'id' => $item->id, 'ended_at' => '11:00', 'duration_minutes' => null];
    daPost($this->userA, DA_TODAY, $items)->assertSessionHasNoErrors();
    expect($item->fresh()->duration_minutes)->toBe(120);
});

test('reviewer assignments cannot be created outside the actor\'s organization scope', function (): void {
    $this->actingAs($this->orgAdmin)
        ->post(route('daily-activities.settings.reviewers.store'), [
            'reviewer_user_id' => $this->financeReviewer->id,
            'organization_id' => $this->otherOrg->id,
        ])
        ->assertSessionHasErrors('organization_id');

    $this->actingAs($this->orgAdmin)
        ->post(route('daily-activities.settings.reviewers.store'), [
            'reviewer_user_id' => $this->financeReviewer->id,
            'organization_id' => $this->org->id,
            'organization_unit_id' => $this->foreignUnit->id,
        ])
        ->assertSessionHasErrors('organization_unit_id');

    expect(DailyActivityReviewerAssignment::query()->count())->toBe(2);
});
