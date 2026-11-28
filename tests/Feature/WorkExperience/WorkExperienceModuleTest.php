<?php

declare(strict_types=1);

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
use App\Support\Rbac\DefaultRoleMatrix;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\DailyWork\Contracts\Data\DailyActivityRoles;
use Modules\WorkExperience\Application\Notifications\WorkExperienceNotification;
use Modules\WorkExperience\Application\Services\WorkExperienceTimeline;
use Modules\WorkExperience\Contracts\Data\WorkExperienceRoles;
use Modules\WorkExperience\Contracts\Data\WorkExperienceStatus;
use Modules\WorkExperience\Domain\Models\WorkExperienceRecord;
use Modules\WorkExperience\Domain\Models\WorkExperienceRequest;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Work experience (ADR 0040, Modules/WorkExperience/README.md)
|--------------------------------------------------------------------------
|
| "Now" is Monday 2 November 2026 in Addis Ababa, Ethiopian year 2019.
| Employee A has served the Civil Service Bureau since 1 January 2020 in one
| assignment the system holds: 6 years, 10 months and 2 days to date.
*/

function wxAt(string $date): void
{
    Carbon::setTestNow(Carbon::parse("{$date} 08:00:00", 'Africa/Addis_Ababa')->setTimezone(date_default_timezone_get()));
}

/** @param array<int, string> $permissions */
function wxUser(string $role, array $permissions, ?string $email = null): User
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
function wxEmployee(string $number, string $email, Organization $org, OrganizationUnit $unit, Position $position, array $attributes = []): Employee
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

/** @param array<string, mixed> $overrides */
function wxAsk(User $user, array $overrides = []): WorkExperienceRequest
{
    test()->actingAs($user)->post(route('employee.work-experience.store'), ['addressee' => 'Addis Ababa University', 'purpose' => 'Application for education', 'language' => 'am', ...$overrides])
        ->assertSessionHasNoErrors();

    return WorkExperienceRequest::query()->latest('created_at')->latest('id')->firstOrFail();
}

function wxSignatory(Organization $organization, array $overrides = []): void
{
    test()->actingAs(test()->hr)->put(route('work-experience.settings.update', $organization), [
        'signatory_name_am' => 'አቶ ከበደ አለሙ', 'signatory_name_en' => 'Kebede Alemu', 'signatory_title_am' => 'የሰው ሀብት አስተዳደር ዳይሬክተር', 'signatory_title_en' => 'HR Director',
        'include_salary' => true, 'include_external' => false, ...$overrides,
    ])->assertSessionHasNoErrors();
}

beforeEach(function (): void {
    wxAt('2026-11-02');
    Notification::fake();
    Storage::fake('local');

    $orgType = OrganizationType::query()->create(['code' => 'WX-TYPE', 'name_en' => 'Bureau']);
    $this->org = Organization::query()->create(['organization_type_id' => $orgType->id, 'code' => 'WX-ORG', 'name_en' => 'Civil Service Bureau', 'name_am' => 'ሲቪል ሰርቪስ ቢሮ', 'status' => 'active']);
    $this->otherOrg = Organization::query()->create(['organization_type_id' => $orgType->id, 'code' => 'WX-OTHER', 'name_en' => 'Health Bureau', 'status' => 'active']);
    $this->unit = OrganizationUnit::query()->create(['organization_id' => $this->org->id, 'code' => 'WX-HR', 'name_en' => 'HR Directorate', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->foreignUnit = OrganizationUnit::query()->create(['organization_id' => $this->otherOrg->id, 'code' => 'WX-HB', 'name_en' => 'Health HR', 'unit_type' => 'directorate', 'status' => 'active']);
    $this->position = Position::query()->create(['organization_id' => $this->org->id, 'organization_unit_id' => $this->unit->id, 'job_position_code' => 'WX-P1', 'title_en' => 'Officer', 'title_am' => 'ባለሙያ', 'is_active' => true]);
    $this->foreignPosition = Position::query()->create(['organization_id' => $this->otherOrg->id, 'organization_unit_id' => $this->foreignUnit->id, 'job_position_code' => 'WX-P3', 'title_en' => 'Clerk', 'is_active' => true]);

    $this->employeeA = wxEmployee('WX-A', 'a@wx.test', $this->org, $this->unit, $this->position);
    $this->employeeB = wxEmployee('WX-B', 'b@wx.test', $this->org, $this->unit, $this->position);
    $this->employeeF = wxEmployee('WX-F', 'f@wx.test', $this->otherOrg, $this->foreignUnit, $this->foreignPosition);
    // Left the service: no account, the office asks for them.
    $this->former = wxEmployee('WX-Z', 'z@wx.test', $this->org, $this->unit, $this->position, ['status' => 'terminated']);

    $this->userA = wxUser(DailyActivityRoles::EMPLOYEE_ROLE, WorkExperienceRoles::EMPLOYEE_PERMISSIONS, 'a@wx.test');
    $this->userB = wxUser(DailyActivityRoles::EMPLOYEE_ROLE, WorkExperienceRoles::EMPLOYEE_PERMISSIONS, 'b@wx.test');

    $this->hr = wxUser('WX HR Officer', WorkExperienceRoles::HR_PERMISSIONS);
    UserOrganizationScope::query()->create(['user_id' => $this->hr->id, 'organization_id' => $this->org->id, 'scope_type' => 'self', 'is_active' => true]);
    $this->foreignHr = wxUser('WX Foreign HR', WorkExperienceRoles::HR_PERMISSIONS);
    UserOrganizationScope::query()->create(['user_id' => $this->foreignHr->id, 'organization_id' => $this->otherOrg->id, 'scope_type' => 'self', 'is_active' => true]);

    app(OrganizationScopeService::class)->clearCache();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

// ── The record ──────────────────────────────────────────────────────────────

test('assignments come by themselves and the office adds salary, earlier service and service elsewhere', function (): void {
    $timeline = app(WorkExperienceTimeline::class);
    $lines = $timeline->linesFor($this->employeeA, $this->org->id);

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->source->toBe('assignment')->kind->toBe('internal')->position_am->toBe('ባለሙያ')->position_en->toBe('Officer')
        ->started_on->toBe('2020-01-01')->ended_on->toBeNull()->monthly_salary->toBeNull()
        ->and($lines[0]['duration'])->toBe(['years' => 6, 'months' => 10, 'days' => 2]);

    // The salary of the assignment.
    $this->actingAs($this->hr)->post(route('work-experience.records.store', $this->employeeA), [
        'employee_assignment_id' => $this->employeeA->current_assignment_id, 'kind' => 'external', 'position_title' => 'ባለሙያ', 'started_on' => '2020-01-01', 'monthly_salary' => '12500',
    ])->assertSessionHasNoErrors();
    // The same assignment cannot be given details twice.
    $this->actingAs($this->hr)->post(route('work-experience.records.store', $this->employeeA), [
        'employee_assignment_id' => $this->employeeA->current_assignment_id, 'kind' => 'internal', 'position_title' => 'ባለሙያ', 'started_on' => '2020-01-01',
    ])->assertSessionHasErrors('employee_assignment_id');
    // Service elsewhere, taken from a paper; the institution must be named.
    $external = ['kind' => 'external', 'position_title' => 'Teacher', 'started_on' => '2015-09-01', 'ended_on' => '2019-12-31', 'monthly_salary' => '4000'];
    $this->actingAs($this->hr)->post(route('work-experience.records.store', $this->employeeA), $external)->assertSessionHasErrors('employer_name');
    $this->actingAs($this->hr)->post(route('work-experience.records.store', $this->employeeA), [...$external, 'employer_name' => 'Menelik School', 'evidence' => UploadedFile::fake()->create('letter.pdf', 80, 'application/pdf')])
        ->assertSessionHasNoErrors();
    // Dates are checked: nothing in the future, no end before the start.
    $this->actingAs($this->hr)->post(route('work-experience.records.store', $this->employeeA), [...$external, 'employer_name' => 'X', 'started_on' => '2027-01-01', 'ended_on' => null])->assertSessionHasErrors('started_on');
    $this->actingAs($this->hr)->post(route('work-experience.records.store', $this->employeeA), [...$external, 'employer_name' => 'X', 'ended_on' => '2015-01-01'])->assertSessionHasErrors('ended_on');

    $lines = $timeline->linesFor($this->employeeA, $this->org->id);
    expect($lines)->toHaveCount(2)
        ->and($lines[0])->kind->toBe('external')->employer_am->toBe('Menelik School')->evidence_name->toBe('letter.pdf')
        // The institution of an assignment is a fact of the system, whatever the form said.
        ->and($lines[1])->kind->toBe('internal')->monthly_salary->toBe(12500.0)->record_id->not->toBeNull()
        ->and($timeline->total($lines))->toBe(['years' => 11, 'months' => 2, 'days' => 2])
        ->and(AuditLog::query()->where('event_type', 'work_experience.record_saved')->count())->toBe(2);

    $record = WorkExperienceRecord::query()->whereNull('employee_assignment_id')->sole();
    Storage::disk('local')->assertExists($record->evidence_path);
    $this->actingAs($this->hr)->get(route('work-experience.records.evidence', $record))->assertOk();
    // The employee it is about may read the paper; a colleague may not.
    $this->actingAs($this->userA)->get(route('work-experience.records.evidence', $record))->assertOk();
    $this->actingAs($this->userB)->get(route('work-experience.records.evidence', $record))->assertForbidden();

    // Corrected, then removed with its evidence.
    $this->actingAs($this->hr)->post(route('work-experience.records.update', $record), [...$external, 'employer_name' => 'Menelik II School', 'monthly_salary' => '4200'])->assertSessionHasNoErrors();
    expect($record->refresh())->employer_name->toBe('Menelik II School')->evidence_name->toBe('letter.pdf');
    $path = $record->evidence_path;
    $this->actingAs($this->hr)->delete(route('work-experience.records.destroy', $record))->assertSessionHasNoErrors();
    Storage::disk('local')->assertMissing($path);
    expect($timeline->linesFor($this->employeeA, $this->org->id))->toHaveCount(1);
});

test('the record page lists the employees of the office, who have left included, and nobody else\'s', function (): void {
    $this->actingAs($this->hr)->get(route('work-experience.records.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('modules/work-experience/Records', false)->has('experienceEmployees.data', 3)->where('experienceSelected', null)->where('canRecordAny', true));
    $this->actingAs($this->hr)->get(route('work-experience.records.index', ['service' => 'left']))
        ->assertInertia(fn ($page) => $page->has('experienceEmployees.data', 1)->where('experienceEmployees.data.0.employee_number', 'WX-Z')->where('experienceEmployees.data.0.has_left', true));
    $this->actingAs($this->hr)->get(route('work-experience.records.index', ['selected' => $this->employeeA->id]))
        ->assertInertia(fn ($page) => $page->where('experienceSelected.employee.employee_number', 'WX-A')->has('experienceSelected.lines', 1)->where('experienceSelected.can_record', true));

    // Another institution's employee is neither listed, opened nor recorded.
    $this->actingAs($this->hr)->get(route('work-experience.records.index', ['selected' => $this->employeeF->id]))->assertInertia(fn ($page) => $page->where('experienceSelected', null));
    $this->actingAs($this->hr)->post(route('work-experience.records.store', $this->employeeF), ['kind' => 'internal', 'position_title' => 'X', 'started_on' => '2020-01-01'])->assertForbidden();
    $this->actingAs($this->foreignHr)->get(route('work-experience.records.index'))->assertInertia(fn ($page) => $page->has('experienceEmployees.data', 1));
    // An employee has no business on the office's pages.
    $this->actingAs($this->userA)->get(route('work-experience.records.index'))->assertForbidden();
    $this->actingAs($this->userA)->get(route('work-experience.requests.index'))->assertForbidden();
    $this->actingAs($this->userA)->post(route('work-experience.records.store', $this->employeeA), ['kind' => 'internal', 'position_title' => 'X', 'started_on' => '2020-01-01'])->assertForbidden();
});

// ── The request ─────────────────────────────────────────────────────────────

test('an employee asks with a short form, the office accepts, prints and sends, and the employee is told at each step', function (): void {
    $this->actingAs($this->hr)->post(route('work-experience.records.store', $this->employeeA), [
        'employee_assignment_id' => $this->employeeA->current_assignment_id, 'kind' => 'internal', 'position_title' => 'ባለሙያ', 'started_on' => '2020-01-01', 'monthly_salary' => '12500',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->userA)->get(route('employee.work-experience.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('modules/work-experience/MyExperience', false)->has('experience.lines', 1)->where('experience.lines.0.monthly_salary', 12500)->has('experienceRequests', 0));
    $this->actingAs($this->userA)->post(route('employee.work-experience.store'), ['purpose' => '', 'language' => 'am'])->assertSessionHasErrors('purpose');

    $request = wxAsk($this->userA);
    expect($request)->status->toBe(WorkExperienceStatus::Submitted)->organization_id->toBe($this->org->id)->registered_by_office->toBeFalse()
        ->and($request->reference_number)->toStartWith('WX-2019-');
    Notification::assertSentTo($this->hr, WorkExperienceNotification::class, fn (WorkExperienceNotification $n): bool => $n->kind === 'submitted');
    Notification::assertNotSentTo($this->foreignHr, WorkExperienceNotification::class);

    // No signatory yet: the popup says so and the request cannot be accepted.
    $this->actingAs($this->hr)->get(route('work-experience.requests.index', ['request' => $request->id]))->assertOk()
        ->assertInertia(fn ($page) => $page->component('modules/work-experience/Requests', false)->where('experienceSummary.waiting', 1)
            ->where('experienceOpenRequest.can.decide', true)->where('experienceOpenRequest.problem', __('work_experience::messages.errors.no_signatory'))->has('experienceOpenRequest.certificate.lines', 1));
    $this->actingAs($this->hr)->post(route('work-experience.requests.accept', $request))->assertSessionHasErrors('status');
    wxSignatory($this->org);

    $this->actingAs($this->hr)->post(route('work-experience.requests.accept', $request))->assertSessionHasNoErrors();
    $request->refresh();
    expect($request)->status->toBe(WorkExperienceStatus::Accepted)->certificate_number->toBe('WE/0001/2019')->decided_by->toBe($this->hr->id)
        ->and($request->verification_code)->toMatch('/^[A-HJ-NP-Z2-9]{10}$/')
        ->and($request->certificate['lines'])->toHaveCount(1)
        ->and($request->certificate['lines'][0]['monthly_salary'])->toEqual(12500)
        ->and($request->certificate['total'])->toBe(['years' => 6, 'months' => 10, 'days' => 2])
        ->and($request->certificate['last_day'])->toBeNull();
    Notification::assertSentTo($this->userA, WorkExperienceNotification::class, fn (WorkExperienceNotification $n): bool => $n->kind === 'accepted');
    $this->actingAs($this->hr)->post(route('work-experience.requests.accept', $request))->assertSessionHasErrors('status');

    // The certificate is frozen: a later change to the record does not change it, until the office writes it again.
    WorkExperienceRecord::query()->sole()->update(['monthly_salary' => 13000]);
    expect($request->refresh()->certificate['lines'][0]['monthly_salary'])->toEqual(12500);
    $this->actingAs($this->hr)->post(route('work-experience.requests.rewrite', $request))->assertSessionHasNoErrors();
    expect($request->refresh()->certificate['lines'][0]['monthly_salary'])->toEqual(13000)->and($request->certificate_number)->toBe('WE/0001/2019');

    // Sent only after it was printed. The printed page is in Amharic, dated in the Ethiopian calendar.
    $this->actingAs($this->hr)->post(route('work-experience.requests.sent', $request), ['delivery_note' => 'By hand'])->assertSessionHasErrors('status');
    $this->actingAs($this->userA)->get(route('work-experience.requests.print', $request))->assertForbidden();
    $this->actingAs($this->hr)->get(route('work-experience.requests.print', $request))->assertOk()
        ->assertSee('የሥራ ልምድ ማስረጃ')->assertSee('WE/0001/2019')->assertSee('ሲቪል ሰርቪስ ቢሮ')->assertSee('WX-A')->assertSee('ባለሙያ')->assertSee('13,000.00')
        ->assertSee('6 ዓመት ከ10 ወር ከ2 ቀን')->assertSee('2012 ዓ.ም')->assertSee('አቶ ከበደ አለሙ')->assertSee('Addis Ababa University')->assertSee($request->verification_code);
    expect($request->refresh()->print_count)->toBe(1);

    $this->actingAs($this->hr)->post(route('work-experience.requests.sent', $request), ['delivery_note' => 'By hand'])->assertSessionHasNoErrors();
    expect($request->refresh())->status->toBe(WorkExperienceStatus::Sent)->delivery_note->toBe('By hand')->sent_by->toBe($this->hr->id);
    Notification::assertSentTo($this->userA, WorkExperienceNotification::class, fn (WorkExperienceNotification $n): bool => $n->kind === 'sent');
    // A sent certificate can be printed again but not rewritten.
    $this->actingAs($this->hr)->get(route('work-experience.requests.print', $request))->assertOk();
    $this->actingAs($this->hr)->post(route('work-experience.requests.rewrite', $request))->assertSessionHasErrors('status');

    $this->actingAs($this->userA)->get(route('employee.work-experience.index'))
        ->assertInertia(fn ($page) => $page->has('experienceRequests', 1)->where('experienceRequests.0.status', 'sent')->where('experienceRequests.0.can_cancel', false));
    // The code on the paper finds the request.
    $this->actingAs($this->hr)->get(route('work-experience.requests.index', ['search' => strtolower($request->verification_code)]))->assertInertia(fn ($page) => $page->has('experienceRequests.data', 1));
    foreach (['requested', 'accepted', 'printed', 'sent', 'settings_changed'] as $event) {
        expect(AuditLog::query()->where('event_type', "work_experience.{$event}")->exists())->toBeTrue();
    }
});

test('a request is sent back with a reason or withdrawn by its owner, and an English certificate is dated in the Gregorian calendar', function (): void {
    wxSignatory($this->org);
    $request = wxAsk($this->userA);

    $this->actingAs($this->hr)->post(route('work-experience.requests.return', $request), ['comment' => ''])->assertSessionHasErrors('comment');
    $this->actingAs($this->hr)->post(route('work-experience.requests.return', $request), ['comment' => 'The start date is being checked.'])->assertSessionHasNoErrors();
    expect($request->refresh())->status->toBe(WorkExperienceStatus::Returned)->decision_comment->toBe('The start date is being checked.')->certificate_number->toBeNull();
    Notification::assertSentTo($this->userA, WorkExperienceNotification::class, fn (WorkExperienceNotification $n): bool => $n->kind === 'returned');
    $this->actingAs($this->hr)->get(route('work-experience.requests.print', $request))->assertNotFound();

    // The employee asks again, in English, to whom it may concern; a colleague cannot withdraw it, the owner can.
    $second = wxAsk($this->userA, ['addressee' => '', 'language' => 'en']);
    $this->actingAs($this->userB)->post(route('employee.work-experience.cancel', $second))->assertForbidden();
    $this->actingAs($this->userA)->post(route('employee.work-experience.cancel', $second))->assertSessionHasNoErrors();
    expect($second->refresh()->status)->toBe(WorkExperienceStatus::Cancelled);
    $this->actingAs($this->hr)->post(route('work-experience.requests.accept', $second))->assertSessionHasErrors('status');

    $third = wxAsk($this->userA, ['addressee' => '', 'language' => 'en']);
    $this->actingAs($this->hr)->post(route('work-experience.requests.accept', $third))->assertSessionHasNoErrors();
    $this->actingAs($this->hr)->get(route('work-experience.requests.print', $third))->assertOk()
        ->assertSee('Work Experience Certificate')->assertSee('To whom it may concern')->assertSee('Civil Service Bureau')->assertSee('January 1, 2020')
        ->assertSee('6 years, 10 months, 2 days')->assertSee('Kebede Alemu')->assertSee('HR Director');
});

test('the office asks only for an employee who has left, and only inside its own institution', function (): void {
    wxSignatory($this->org);
    $payload = ['purpose' => 'Pension file', 'language' => 'am'];

    // In service: the employee asks with their own account.
    $this->actingAs($this->hr)->post(route('work-experience.requests.store'), [...$payload, 'employee_id' => $this->employeeA->id])->assertSessionHasErrors('employee_id');
    $this->actingAs($this->foreignHr)->post(route('work-experience.requests.store'), [...$payload, 'employee_id' => $this->former->id])->assertForbidden();
    $this->actingAs($this->userA)->post(route('work-experience.requests.store'), [...$payload, 'employee_id' => $this->former->id])->assertForbidden();
    $this->actingAs($this->hr)->get(route('work-experience.requests.index', ['former' => 'WX-']))
        ->assertInertia(fn ($page) => $page->has('experienceFormer', 1)->where('experienceFormer.0.employee_number', 'WX-Z'));

    $this->actingAs($this->hr)->post(route('work-experience.requests.store'), [...$payload, 'employee_id' => $this->former->id])->assertSessionHasNoErrors();
    $request = WorkExperienceRequest::query()->sole();
    expect($request)->registered_by_office->toBeTrue()->employee_id->toBe($this->former->id)->organization_id->toBe($this->org->id);

    // The person has no account: the steps still work, nobody is notified.
    $this->actingAs($this->hr)->post(route('work-experience.requests.accept', $request))->assertSessionHasNoErrors();
    $this->actingAs($this->hr)->get(route('work-experience.requests.print', $request))->assertOk();
    $this->actingAs($this->hr)->post(route('work-experience.requests.sent', $request), [])->assertSessionHasNoErrors();
    expect($request->refresh()->status)->toBe(WorkExperienceStatus::Sent);
    Notification::assertNotSentTo($this->userA, WorkExperienceNotification::class);
});

test('requests stay inside the institution, certificates are numbered in turn, and the settings decide what is written', function (): void {
    $this->actingAs($this->hr)->post(route('work-experience.records.store', $this->employeeA), [
        'employee_assignment_id' => $this->employeeA->current_assignment_id, 'kind' => 'internal', 'position_title' => 'ባለሙያ', 'started_on' => '2020-01-01', 'monthly_salary' => '12500',
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->hr)->post(route('work-experience.records.store', $this->employeeA), [
        'kind' => 'external', 'employer_name' => 'Menelik School', 'position_title' => 'Teacher', 'started_on' => '2015-09-01', 'ended_on' => '2019-12-31',
    ])->assertSessionHasNoErrors();

    // Settings: only inside scope, and a signatory needs a name and a title.
    $this->actingAs($this->foreignHr)->put(route('work-experience.settings.update', $this->org), ['signatory_name_am' => 'X', 'signatory_title_am' => 'Y', 'include_salary' => true, 'include_external' => false])->assertForbidden();
    $this->actingAs($this->userA)->get(route('work-experience.settings'))->assertForbidden();
    $this->actingAs($this->hr)->put(route('work-experience.settings.update', $this->org), ['signatory_name_am' => 'X', 'include_salary' => true, 'include_external' => false])->assertSessionHasErrors('signatory_title_am');
    $this->actingAs($this->hr)->get(route('work-experience.settings'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('modules/work-experience/Settings', false)->has('experienceOffices.data', 1)->where('experienceOffices.data.0.configured', false)->where('experienceOffices.data.0.include_salary', true));
    wxSignatory($this->org);

    // By default the certificate carries the service given in this institution only, with the salary.
    $first = wxAsk($this->userA);
    $this->actingAs($this->foreignHr)->post(route('work-experience.requests.accept', $first))->assertForbidden();
    $this->actingAs($this->foreignHr)->get(route('work-experience.requests.index', ['request' => $first->id]))
        ->assertInertia(fn ($page) => $page->has('experienceRequests.data', 0)->where('experienceOpenRequest', null));
    $this->actingAs($this->hr)->post(route('work-experience.requests.accept', $first))->assertSessionHasNoErrors();
    expect($first->refresh()->certificate)->lines->toHaveCount(1)->include_salary->toBeTrue()->first_day->toBe('2020-01-01');

    // With service elsewhere written and the salary left out, the next certificate says so; its number follows.
    wxSignatory($this->org, ['include_salary' => false, 'include_external' => true]);
    $second = wxAsk($this->userA);
    $this->actingAs($this->hr)->post(route('work-experience.requests.accept', $second))->assertSessionHasNoErrors();
    expect($second->refresh())->certificate_number->toBe('WE/0002/2019')
        ->and($second->certificate)->lines->toHaveCount(2)->include_salary->toBeFalse()->first_day->toBe('2015-09-01')
        ->and($second->certificate['lines'][1]['monthly_salary'])->toBeNull()
        ->and($second->verification_code)->not->toBe($first->verification_code);
    $this->actingAs($this->hr)->get(route('work-experience.requests.print', $second))->assertOk()->assertSee('Menelik School')->assertDontSee('12,500.00');

    // An employee with nothing recorded in the institution cannot be given an empty certificate.
    EmployeeAssignment::query()->where('employee_id', $this->employeeB->id)->update(['assignment_status' => 'pending_transfer']);
    wxSignatory($this->org);
    $empty = wxAsk($this->userB);
    $this->actingAs($this->hr)->post(route('work-experience.requests.accept', $empty))->assertSessionHasErrors('status');
    expect($empty->refresh()->status)->toBe(WorkExperienceStatus::Submitted);
});

test('the default roles carry the work experience permissions and every permission is in the catalog', function (): void {
    $names = array_column(require database_path('seeders/data/work-experience-permissions.php'), 'name');

    expect($names)->toHaveCount(5)
        ->and(array_diff(WorkExperienceRoles::EMPLOYEE_PERMISSIONS, $names))->toBe([])
        ->and(array_diff(WorkExperienceRoles::HR_PERMISSIONS, $names))->toBe([])
        ->and(DefaultRoleMatrix::permissionsFor(DailyActivityRoles::EMPLOYEE_ROLE))->toContain('work_experience.request_own')
        ->and(DefaultRoleMatrix::permissionsFor('HR Officer'))->toContain('work_experience.requests.manage', 'work_experience.records.manage')
        ->and(DefaultRoleMatrix::problems())->toBe([]);
});
