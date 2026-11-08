<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\OrganizationUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Feedback\Application\Services\PublicComplaintStageService;
use Modules\Feedback\Application\Services\PublicFeedbackNotifier;
use Modules\Feedback\Contracts\Data\ServiceFeedbackStatus;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;
use Modules\Feedback\Domain\Models\PublicCaseHandlingUnit;
use Modules\Feedback\Domain\Models\PublicComplaintStage;
use Tests\Support\PublicFeedbackScenario;

/*
 * ADR 0035: a public complaint is heard by the complaint committee; a
 * complainant who is not satisfied appeals to the appeal committee, which
 * upholds or overturns the answer, and then to the head of the institution,
 * whose decision is final. The complainant answers each decision on the
 * public tracking page.
 */

beforeEach(function (): void {
    app()->setLocale('en');
    Storage::fake('local');
    $permissions = ['service_feedback.view', 'service_feedback.tip_offs.view', 'service_feedback.complaints.view', 'service_feedback.view_employee_target', 'service_feedback.view_organization_target', 'service_feedback.assign', 'service_feedback.reassign', 'service_feedback.review', 'service_feedback.tip_offs.review', 'service_feedback.complaints.review', 'service_feedback.resolve', 'service_feedback.close', 'service_feedback.manage_routing'];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');

    $this->s = PublicFeedbackScenario::employeeTarget('APL');
    RateLimiter::clear('sf-submit:'.$this->s->token->token.'|127.0.0.1');
    RateLimiter::clear('sf-submit-ip:127.0.0.1');
    $org = $this->s->organization->id;

    // A handler role without the coordinator grants, scoped to the institution.
    $handler = Role::findOrCreate('Complaint Handler', 'web');
    $handler->syncPermissions(['service_feedback.view', 'service_feedback.tip_offs.view', 'service_feedback.complaints.view', 'service_feedback.complaints.review', 'service_feedback.resolve', 'service_feedback.close', 'service_feedback.view_employee_target', 'service_feedback.view_organization_target']);

    $this->units = [];
    $this->users = [];
    foreach (['first_instance' => 'Complaint Committee', 'appeal' => 'Appeal Committee', 'head_office' => 'Office of the Head'] as $stage => $name) {
        $orgUnit = OrganizationUnit::query()->create(['organization_id' => $org, 'code' => 'APL-'.strtoupper(substr($stage, 0, 3)), 'name_en' => $name, 'unit_type' => 'department', 'status' => 'active']);
        $employee = appealStaff($this->s, $orgUnit, 'APL-'.$stage, 'Member '.ucfirst(str_replace('_', '', $stage)));
        $user = User::factory()->create(['employee_id' => $employee->id]);
        $user->assignRole($handler);
        UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $org, 'scope_type' => 'self', 'is_active' => true]);
        $this->users[$stage] = $user;
        $this->units[$stage] = $orgUnit;
    }
});

function appealStaff(object $s, OrganizationUnit $unit, string $number, string $name): Employee
{
    $employee = Employee::query()->create(['employee_number' => $number, 'first_name' => explode(' ', $name)[0], 'last_name' => explode(' ', $name)[1], 'full_name' => $name, 'status' => EmployeeStatus::Active->value]);
    $assignment = EmployeeAssignment::query()->create(['employee_id' => $employee->id, 'organization_id' => $s->organization->id, 'organization_unit_id' => $unit->id, 'position_id' => $s->position->id, 'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => now()->toDateString()]);
    $employee->forceFill(['current_assignment_id' => $assignment->id])->save();

    return $employee;
}

function designateStage(object $test, string $stage, ?Employee $member = null): PublicCaseHandlingUnit
{
    // ADR 0035: a committee is an institution body with its own name, not a work unit.
    $test->actingAs($test->admin)->post(route('public-complaints.units.store'), ['organization_id' => $test->s->organization->id, 'stage' => $stage, 'name_am' => 'ኮሚቴ '.$stage])->assertSessionHasNoErrors();
    $unit = PublicCaseHandlingUnit::query()->where('stage', $stage)->whereNull('organization_unit_id')->sole();
    $employee = $member ?? Employee::query()->find($test->users[$stage]->employee_id);
    $test->actingAs($test->admin)->post(route('public-complaints.units.members.store', $unit), ['employee_id' => $employee->id, 'responsibility' => 'head', 'effective_from' => now()->toDateString(), 'signature' => UploadedFile::fake()->image('s.png')])->assertSessionHasNoErrors();

    return $unit;
}

function committeeOf(string $stage): PublicCaseHandlingUnit
{
    return PublicCaseHandlingUnit::query()->where('stage', $stage)->whereNull('organization_unit_id')->sole();
}

function fileComplaint(object $test): EmployeeServiceFeedback
{
    $test->post('/service-feedback/'.$test->s->token->token.'/complaint', pfComplete('complaint', ['message' => 'I was refused service.', 'client_contact' => '0911 22 33 44']))->assertSessionHasNoErrors();

    return EmployeeServiceFeedback::query()->where('type', 'complaint')->sole();
}

function decideStage(object $test, User $user, EmployeeServiceFeedback $complaint, array $data): TestResponse
{
    if ($complaint->fresh()->status === ServiceFeedbackStatus::Assigned) {
        $test->actingAs($user)->post(route('public-complaints.cases.transition', [$complaint]), ['status' => 'under_review'])->assertSessionHasNoErrors();
    }

    return $test->actingAs($user)->post(route('public-complaints.cases.transition', [$complaint]), ['status' => 'resolved', ...$data]);
}

function lookUp(object $test, EmployeeServiceFeedback $complaint): void
{
    $test->post(route('public-submissions.track.lookup'), ['reference' => $complaint->reference_number, 'phone' => '+251911223344'])->assertSessionHasNoErrors();
}

function setAppealDays(?int $days): void
{
    SystemSetting::query()->updateOrCreate(['group' => 'service_feedback', 'key' => 'complaint_appeal_days'], ['value' => $days === null ? null : (string) $days, 'type' => 'integer']);
    app(SystemSettingsService::class)->clearCache();
}

it('sends a complaint to the committee, then an appeal to the appeal committee and then to the head', function (): void {
    foreach (['first_instance', 'appeal', 'head_office'] as $stage) {
        designateStage($this, $stage);
    }
    setAppealDays(10);
    $complaint = fileComplaint($this);

    // With no routing rule the complaint goes straight to the complaint committee.
    expect($complaint->currentAssignment()->sole()->handler_id)->toBe(committeeOf('first_instance')->id);

    // The committee must write the response the complainant reads.
    decideStage($this, $this->users['first_instance'], $complaint, ['reason' => ''])->assertSessionHasErrors('reason');
    decideStage($this, $this->users['first_instance'], $complaint, ['reason' => 'Your request will be served this week.'])->assertSessionHasNoErrors();
    $first = PublicComplaintStage::query()->sole();
    expect($first->decision)->toBe('answered')->and($first->appeal_deadline_at->isFuture())->toBeTrue();

    // Staff cannot close while the appeal period runs.
    $this->actingAs($this->admin)->post(route('public-complaints.cases.transition', [$complaint]), ['status' => 'closed'])->assertSessionHasErrors('status');

    // The complainant sees the response, never who decided, and appeals.
    lookUp($this, $complaint);
    $this->get(route('public-submissions.track', ['reference' => $complaint->reference_number]))
        ->assertInertia(fn (Assert $page) => $page->where('result.hearing.stages.0.response', 'Your request will be served this week.')
            ->missing('result.hearing.stages.0.decided_by')->where('result.hearing.can_appeal', true)->where('result.hearing.next', 2));
    $this->post(route('public-submissions.track.respond'), ['reference' => $complaint->reference_number, 'action' => 'appeal', 'reason' => 'short'])->assertSessionHasErrors('reason');
    $this->post(route('public-submissions.track.respond'), ['reference' => $complaint->reference_number, 'action' => 'appeal', 'reason' => 'Nothing was served after a week.'])->assertSessionHasNoErrors();

    $complaint->refresh();
    expect($complaint->status)->toBe(ServiceFeedbackStatus::Assigned)
        ->and($complaint->currentAssignment()->sole()->handler_id)->toBe(committeeOf('appeal')->id)
        ->and($first->fresh()->citizen_outcome)->toBe('appealed');

    // The appeal committee must say whether it upholds or overturns.
    decideStage($this, $this->users['appeal'], $complaint, ['reason' => 'Reviewed.'])->assertSessionHasErrors('stage_decision');
    decideStage($this, $this->users['appeal'], $complaint, ['reason' => 'The office must serve you within three days.', 'stage_decision' => 'overturned'])->assertSessionHasNoErrors();
    expect(PublicComplaintStage::query()->where('stage', 2)->sole()->decision)->toBe('overturned');

    // Then to the head, whose decision is final.
    lookUp($this, $complaint);
    $this->post(route('public-submissions.track.respond'), ['reference' => $complaint->reference_number, 'action' => 'appeal', 'reason' => 'Still not served after three days.'])->assertSessionHasNoErrors();
    expect($complaint->fresh()->currentAssignment()->sole()->handler_id)->toBe(committeeOf('head_office')->id);
    decideStage($this, $this->users['head_office'], $complaint, ['reason' => 'Served today by my office.', 'stage_decision' => 'upheld'])->assertSessionHasNoErrors();

    lookUp($this, $complaint);
    $this->get(route('public-submissions.track', ['reference' => $complaint->reference_number]))
        ->assertInertia(fn (Assert $page) => $page->has('result.hearing.stages', 3)->where('result.hearing.can_appeal', false)->where('result.hearing.can_accept', false));
    // Final: staff may now close it.
    $this->actingAs($this->users['head_office'])->post(route('public-complaints.cases.transition', [$complaint]), ['status' => 'closed'])->assertSessionHasNoErrors();
    expect($complaint->fresh()->status)->toBe(ServiceFeedbackStatus::Closed);

    $this->actingAs($this->admin)->get(route('public-complaints.cases.show', [$complaint]))
        ->assertInertia(fn (Assert $page) => $page->has('hearing.stages', 3)->where('hearing.stages.1.decision', 'overturned'));
});

it('closes the complaint when the complainant accepts the decision', function (): void {
    designateStage($this, 'first_instance');
    $complaint = fileComplaint($this);
    decideStage($this, $this->users['first_instance'], $complaint, ['reason' => 'Served.'])->assertSessionHasNoErrors();

    // Answering needs a lookup with the phone first.
    $this->post(route('public-submissions.track.respond'), ['reference' => $complaint->reference_number, 'action' => 'accept'])->assertSessionHasErrors('reference');
    lookUp($this, $complaint);
    $this->post(route('public-submissions.track.respond'), ['reference' => $complaint->reference_number, 'action' => 'accept'])->assertSessionHasNoErrors();

    expect($complaint->fresh()->status)->toBe(ServiceFeedbackStatus::Closed)
        ->and(PublicComplaintStage::query()->sole()->citizen_outcome)->toBe('accepted');
    $this->post(route('public-submissions.track.respond'), ['reference' => $complaint->reference_number, 'action' => 'appeal', 'reason' => 'Changed my mind about it.'])->assertSessionHasErrors('action');
});

it('sends the appeal straight to the head when the institution has no appeal committee', function (): void {
    designateStage($this, 'first_instance');
    designateStage($this, 'head_office');
    $complaint = fileComplaint($this);
    decideStage($this, $this->users['first_instance'], $complaint, ['reason' => 'Served.'])->assertSessionHasNoErrors();

    lookUp($this, $complaint);
    $this->get(route('public-submissions.track', ['reference' => $complaint->reference_number]))->assertInertia(fn (Assert $page) => $page->where('result.hearing.next', 3));
    $this->post(route('public-submissions.track.respond'), ['reference' => $complaint->reference_number, 'action' => 'appeal', 'reason' => 'It was not served at all.'])->assertSessionHasNoErrors();

    expect(PublicComplaintStage::query()->where('stage', 3)->exists())->toBeTrue()
        ->and($complaint->fresh()->currentAssignment()->sole()->handler_id)->toBe(committeeOf('head_office')->id);
});

it('keeps whoever decided the first answer off the appeal', function (): void {
    designateStage($this, 'first_instance');
    // The same person also sits on the appeal committee.
    designateStage($this, 'appeal', Employee::query()->find($this->users['first_instance']->employee_id));
    $complaint = fileComplaint($this);
    decideStage($this, $this->users['first_instance'], $complaint, ['reason' => 'Served.'])->assertSessionHasNoErrors();
    lookUp($this, $complaint);
    $this->post(route('public-submissions.track.respond'), ['reference' => $complaint->reference_number, 'action' => 'appeal', 'reason' => 'It was not served at all.'])->assertSessionHasNoErrors();

    $this->actingAs($this->users['first_instance'])->post(route('public-complaints.cases.transition', [$complaint]), ['status' => 'under_review'])->assertForbidden();
    expect(app(PublicComplaintStageService::class)->decidedEarlier($this->users['first_instance'], $complaint->fresh()))->toBeTrue();
});

it('closes the complaint when the appeal period passes with no answer, and keeps it open without a period', function (): void {
    designateStage($this, 'first_instance');
    $complaint = fileComplaint($this);
    decideStage($this, $this->users['first_instance'], $complaint, ['reason' => 'Served.'])->assertSessionHasNoErrors();
    $stage = PublicComplaintStage::query()->sole();
    // No period set: nothing lapses, and staff closing ends the right to appeal.
    expect($stage->appeal_deadline_at)->toBeNull();
    $this->artisan('public-complaints:close-lapsed-appeals')->assertSuccessful();
    expect($complaint->fresh()->status)->toBe(ServiceFeedbackStatus::Resolved);

    $stage->forceFill(['appeal_deadline_at' => now()->subDay()])->save();
    $this->artisan('public-complaints:close-lapsed-appeals')->assertSuccessful();
    expect($complaint->fresh()->status)->toBe(ServiceFeedbackStatus::Closed)->and($stage->fresh()->citizen_outcome)->toBe('lapsed');
});

it('allows one appeal committee and one head office per institution', function (): void {
    designateStage($this, 'appeal');
    $this->actingAs($this->admin)->post(route('public-complaints.units.store'), ['organization_id' => $this->s->organization->id, 'stage' => 'appeal', 'name_am' => 'ሁለተኛ'])->assertSessionHasErrors('stage');
    // A committee needs a name.
    $this->actingAs($this->admin)->post(route('public-complaints.units.store'), ['organization_id' => $this->s->organization->id, 'stage' => 'first_instance'])->assertSessionHasErrors('name_am');

    $this->actingAs($this->admin)->get(route('public-complaints.units.index'))
        ->assertInertia(fn (Assert $page) => $page->where('units.0.stage', 'appeal')->where('units.0.committee', true)->where('units.0.unit', 'ኮሚቴ appeal')
            ->where('stageGaps.0.missing', ['first_instance', 'head_office']));
});

it('draws committee members from any unit of the institution, and only from it', function (): void {
    $committee = designateStage($this, 'first_instance');
    // Staff of every unit are offered, not those already serving.
    $this->actingAs($this->admin)->get(route('public-complaints.units.staff', $committee))->assertOk()
        ->assertJsonFragment(['name' => 'Member Appeal'])->assertJsonFragment(['name' => 'Member Headoffice'])->assertJsonMissing(['name' => 'Member Firstinstance']);
    $store = route('public-complaints.units.members.store', $committee);
    foreach (['appeal', 'head_office'] as $stage) {
        $this->actingAs($this->admin)->post($store, ['employee_id' => $this->users[$stage]->employee_id, 'responsibility' => 'officer', 'effective_from' => now()->toDateString(), 'signature' => UploadedFile::fake()->image('s.png')])->assertSessionHasNoErrors();
    }
    expect($committee->members()->count())->toBe(3);

    // An employee of another institution is refused.
    $other = PublicFeedbackScenario::employeeTarget('APX');
    $this->actingAs($this->admin)->post($store, ['employee_id' => $other->employee->id, 'responsibility' => 'officer', 'effective_from' => now()->toDateString(), 'signature' => UploadedFile::fake()->image('s.png')])->assertSessionHasErrors('employee_id');

    // A complaint sent to the committee reaches all three members, and each sees it as theirs.
    $complaint = fileComplaint($this);
    expect($complaint->currentAssignment()->sole()->handler_type->value)->toBe('committee');
    $recipients = collect(app(PublicFeedbackNotifier::class)->handlerUsers($complaint, $complaint->currentAssignment()->sole()))->pluck('id')->sort()->values()->all();
    expect($recipients)->toBe(collect($this->users)->pluck('id')->sort()->values()->all());
    $this->actingAs($this->users['appeal'])->get(route('public-complaints.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('figures.mine', 1));
});
