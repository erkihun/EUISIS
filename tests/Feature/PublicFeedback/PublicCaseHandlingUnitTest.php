<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\OrganizationUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Feedback\Application\Services\PublicFeedbackNotifier;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;
use Modules\Feedback\Domain\Models\PublicCaseHandlingUnit;
use Modules\Feedback\Domain\Models\PublicCaseHandlingUnitMember;
use Modules\Feedback\Domain\Models\PublicFeedbackRoutingRule;
use Tests\Support\PublicFeedbackScenario;

/*
 * ADR 0034: an institution picks its own work unit to handle public Tip-offs
 * and complaints, names the staff who serve in it and registers a signature
 * for each; cases assigned to the unit reach those members.
 */

beforeEach(function (): void {
    app()->setLocale('en');
    Storage::fake('local');
    foreach (['service_feedback.view', 'service_feedback.complaints.view', 'service_feedback.view_employee_target', 'service_feedback.view_organization_target', 'service_feedback.assign', 'service_feedback.reassign', 'service_feedback.review', 'service_feedback.complaints.review', 'service_feedback.manage_routing'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $this->role = Role::findOrCreate('Super Admin', 'web');
    $this->role->syncPermissions(Permission::all());
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');

    $this->s = PublicFeedbackScenario::employeeTarget('HDU');
    RateLimiter::clear('sf-submit:'.$this->s->token->token.'|127.0.0.1');
    RateLimiter::clear('sf-submit-ip:127.0.0.1');

    // The complaints office of the institution, and a team beneath it.
    $this->office = OrganizationUnit::query()->create(['organization_id' => $this->s->organization->id, 'code' => 'HDU-CO', 'name_en' => 'Complaints Office', 'unit_type' => 'department', 'status' => 'active']);
    $this->team = OrganizationUnit::query()->create(['organization_id' => $this->s->organization->id, 'parent_unit_id' => $this->office->id, 'code' => 'HDU-CT', 'name_en' => 'Complaints Team', 'unit_type' => 'team', 'status' => 'active']);
    $this->officer = handlingStaff($this->s, $this->team, 'HDU-EMP-2', 'Abebe Kebede');
    $this->officerUser = User::factory()->create(['employee_id' => $this->officer->id]);
    $this->officerUser->assignRole('Super Admin');
});

function handlingStaff(object $s, OrganizationUnit $unit, string $number, string $name): Employee
{
    $employee = Employee::query()->create(['employee_number' => $number, 'first_name' => explode(' ', $name)[0], 'last_name' => explode(' ', $name)[1], 'full_name' => $name, 'status' => EmployeeStatus::Active->value]);
    $assignment = EmployeeAssignment::query()->create(['employee_id' => $employee->id, 'organization_id' => $s->organization->id, 'organization_unit_id' => $unit->id, 'position_id' => $s->position->id, 'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => now()->toDateString()]);
    $employee->forceFill(['current_assignment_id' => $assignment->id])->save();

    return $employee;
}

it('designates an institution unit and registers members with their signatures', function (): void {
    $this->actingAs($this->admin)->get(route('public-complaints.units.unit-options', ['organization_id' => $this->s->organization->id]))
        ->assertOk()->assertJsonFragment(['name' => 'Complaints Office']);

    $this->actingAs($this->admin)->post(route('public-complaints.units.store'), ['organization_id' => $this->s->organization->id, 'organization_unit_id' => $this->office->id, 'case_type' => 'complaint'])->assertSessionHasNoErrors();
    $unit = PublicCaseHandlingUnit::query()->sole();
    $this->actingAs($this->admin)->post(route('public-complaints.units.store'), ['organization_id' => $this->s->organization->id, 'organization_unit_id' => $this->office->id, 'case_type' => 'complaint'])->assertSessionHasErrors('organization_unit_id');

    $store = route('public-complaints.units.members.store', $unit);
    $member = ['employee_id' => $this->officer->id, 'responsibility' => 'head', 'effective_from' => now()->toDateString()];

    // A signature is required, and a member must be the unit's own staff.
    $this->actingAs($this->admin)->post($store, $member)->assertSessionHasErrors('signature');
    $this->actingAs($this->admin)->post($store, [...$member, 'employee_id' => $this->s->employee->id, 'signature' => UploadedFile::fake()->image('sig.png', 300, 100)])->assertSessionHasErrors('employee_id');

    $this->actingAs($this->admin)->post($store, [...$member, 'signature' => UploadedFile::fake()->image('sig.png', 300, 100)])->assertSessionHasNoErrors();
    $row = PublicCaseHandlingUnitMember::query()->sole();
    expect($row->signature_sha256)->toHaveLength(64);
    Storage::disk('local')->assertExists($row->signature_path);

    // One head only.
    $second = handlingStaff($this->s, $this->office, 'HDU-EMP-3', 'Sara Tesfaye');
    $this->actingAs($this->admin)->post($store, ['employee_id' => $second->id, 'responsibility' => 'head', 'effective_from' => now()->toDateString(), 'signature' => UploadedFile::fake()->image('s.png')])->assertSessionHasErrors('responsibility');

    $this->actingAs($this->admin)->get(route('public-complaints.units.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ServiceFeedback/HandlingUnits/Index')->has('units', 1)
            ->where('units.0.members.0.name', 'Abebe Kebede')->where('units.0.members.0.responsibility', 'head')->where('units.0.problems', []));
    $this->actingAs($this->admin)->get(route('public-complaints.units.members.signature', [$unit, $row]))->assertOk();

    // Ending a term keeps the row; the unit then has no members.
    $this->actingAs($this->admin)->post(route('public-complaints.units.members.end', [$unit, $row]), ['reason' => 'Transferred.'])->assertSessionHasNoErrors();
    expect($row->fresh()->status)->toBe('inactive');
    $this->actingAs($this->admin)->get(route('public-complaints.units.index'))
        ->assertInertia(fn (Assert $page) => $page->has('units.0.members', 0)->where('units.0.problems', fn ($p) => collect($p)->contains('No members serve in the unit.')));
});

it('assigns cases to the handling unit and reaches its members, and offers only designated units', function (): void {
    $this->actingAs($this->admin)->post(route('public-complaints.units.store'), ['organization_id' => $this->s->organization->id, 'organization_unit_id' => $this->office->id, 'case_type' => 'complaint']);
    $unit = PublicCaseHandlingUnit::query()->sole();
    $this->actingAs($this->admin)->post(route('public-complaints.units.members.store', $unit), ['employee_id' => $this->officer->id, 'responsibility' => 'head', 'effective_from' => now()->toDateString(), 'signature' => UploadedFile::fake()->image('sig.png')])->assertSessionHasNoErrors();

    // Only the designated unit is offered, and another unit is refused.
    $this->actingAs($this->admin)->get(route('public-complaints.routing-rules.handlers', ['organization_id' => $this->s->organization->id]))
        ->assertOk()->assertJsonPath('units.0.id', $this->office->id)->assertJsonCount(1, 'units');
    $this->actingAs($this->admin)->post(route('public-complaints.routing-rules.store'), ['organization_id' => $this->s->organization->id, 'submission_type' => 'complaint', 'handler_type' => 'organization_unit', 'handler_id' => $this->s->unit->id])
        ->assertSessionHasErrors('handler_id');

    PublicFeedbackRoutingRule::query()->create(['organization_id' => $this->s->organization->id, 'submission_type' => 'complaint', 'handler_type' => 'organization_unit', 'handler_id' => $this->office->id, 'priority' => 1, 'is_active' => true, 'created_by' => $this->admin->id]);
    $this->post('/service-feedback/'.$this->s->token->token.'/complaint', pfComplete('complaint', ['message' => 'Complaint.']))->assertSessionHasNoErrors();
    $complaint = EmployeeServiceFeedback::query()->where('type', 'complaint')->sole();
    $assignment = $complaint->currentAssignment()->sole();
    expect($assignment->handler_id)->toBe($this->office->id);

    // The member placed in the team beneath the office receives it; the unit's other placed staff do not.
    $placedOnly = handlingStaff($this->s, $this->office, 'HDU-EMP-4', 'Liya Alemu');
    $placedUser = User::factory()->create(['employee_id' => $placedOnly->id]);
    $placedUser->assignRole('Super Admin');
    $recipients = collect(app(PublicFeedbackNotifier::class)->handlerUsers($complaint, $assignment))->pluck('id')->all();
    expect($recipients)->toBe([$this->officerUser->id]);

    $this->actingAs($this->officerUser)->get(route('service-submissions.complaints.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('figures.mine', 1));
    $this->actingAs($placedUser)->get(route('service-submissions.complaints.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('figures.mine', 0));
});

it('lets only routing administrators of the institution manage handling units', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('service_feedback.complaints.view');
    $this->actingAs($viewer)->get(route('public-complaints.units.index'))->assertForbidden();
    $this->actingAs($viewer)->post(route('public-complaints.units.store'), ['organization_id' => $this->s->organization->id, 'organization_unit_id' => $this->office->id, 'case_type' => 'complaint'])->assertForbidden();
    expect(PublicCaseHandlingUnit::query()->count())->toBe(0);
});

it('keeps Tip-off and complaint handling units apart', function (): void {
    // The office receives Tip-offs; complaints have their own unit.
    $tipDesk = OrganizationUnit::query()->create(['organization_id' => $this->s->organization->id, 'code' => 'HDU-TD', 'name_en' => 'Tip-off Desk', 'unit_type' => 'department', 'status' => 'active']);
    $org = $this->s->organization->id;
    $this->actingAs($this->admin)->post(route('tip-offs.units.store'), ['organization_id' => $org, 'organization_unit_id' => $tipDesk->id, 'case_type' => 'suggestion'])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('public-complaints.units.store'), ['organization_id' => $org, 'organization_unit_id' => $this->office->id, 'case_type' => 'complaint'])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get(route('tip-offs.routing-rules.handlers', ['organization_id' => $org]))
        ->assertJsonCount(1, 'units')->assertJsonPath('units.0.id', $tipDesk->id);
    $this->actingAs($this->admin)->get(route('public-complaints.routing-rules.handlers', ['organization_id' => $org]))
        ->assertJsonCount(1, 'units')->assertJsonPath('units.0.id', $this->office->id);

    // A Tip-off rule cannot name the complaints unit, and the reverse.
    $this->actingAs($this->admin)->post(route('tip-offs.routing-rules.store'), ['organization_id' => $org, 'submission_type' => 'suggestion', 'handler_type' => 'organization_unit', 'handler_id' => $this->office->id])->assertSessionHasErrors('handler_id');
    $this->actingAs($this->admin)->post(route('public-complaints.routing-rules.store'), ['organization_id' => $org, 'submission_type' => 'complaint', 'handler_type' => 'organization_unit', 'handler_id' => $tipDesk->id])->assertSessionHasErrors('handler_id');
    $this->actingAs($this->admin)->post(route('tip-offs.routing-rules.store'), ['organization_id' => $org, 'submission_type' => 'suggestion', 'handler_type' => 'organization_unit', 'handler_id' => $tipDesk->id])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get(route('tip-offs.units.index'))
        ->assertInertia(fn (Assert $page) => $page->has('units', 1)->where('units.0.case_type', 'suggestion'));
});

it('shows each module its unit board, and keeps the member details for the popups', function (): void {
    $this->actingAs($this->admin)->post(route('tip-offs.units.store'), ['organization_id' => $this->s->organization->id, 'organization_unit_id' => $this->office->id])
        ->assertRedirect(route('tip-offs.units.index'));
    $unit = PublicCaseHandlingUnit::query()->sole();
    $this->actingAs($this->admin)->post(route('tip-offs.units.members.store', $unit), ['employee_id' => $this->officer->id, 'responsibility' => 'head', 'effective_from' => now()->toDateString(), 'signature' => UploadedFile::fake()->image('sig.png')])
        ->assertRedirect(route('tip-offs.units.index'));
    $member = PublicCaseHandlingUnitMember::query()->sole();
    $old = $member->signature_sha256;

    $this->actingAs($this->admin)->get(route('tip-offs.units.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ServiceFeedback/HandlingUnits/Index')->where('caseType', 'suggestion')
            ->where('summary', ['units' => 1, 'members' => 1, 'attention' => 0])
            ->where('units.0.members.0.name', 'Abebe Kebede')->where('units.0.members.0.position', 'Licensing Officer')->where('units.0.members.0.placed_unit', 'Complaints Team')
            ->where('can.designate', true));
    $this->actingAs($this->admin)->get(route('tip-offs.units.index', ['search' => 'nothing-like-this']))->assertInertia(fn (Assert $page) => $page->has('units', 0));
    $this->actingAs($this->admin)->get(route('tip-offs.units.index', ['status' => 'inactive']))->assertInertia(fn (Assert $page) => $page->has('units', 0));

    $this->actingAs($this->admin)->post(route('tip-offs.units.members.update', [$unit, $member]), ['responsibility' => 'officer', 'signature' => UploadedFile::fake()->image('new.png', 400, 120)])->assertSessionHasNoErrors();
    expect($member->fresh()->responsibility)->toBe('officer')->and($member->fresh()->signature_sha256)->not->toBe($old);
    $this->actingAs($this->admin)->patch(route('tip-offs.units.update', $unit), ['description_am' => 'የጥቆማ መቀበያ'])->assertSessionHasNoErrors();
    expect($unit->fresh()->description_am)->toBe('የጥቆማ መቀበያ');
});
it('shows Tip-off units and complaint units on separate pages', function (): void {
    $tipDesk = OrganizationUnit::query()->create(['organization_id' => $this->s->organization->id, 'code' => 'HDU-TD2', 'name_en' => 'Tip-off Desk', 'unit_type' => 'department', 'status' => 'active']);
    $this->actingAs($this->admin)->post(route('tip-offs.units.store'), ['organization_id' => $this->s->organization->id, 'organization_unit_id' => $tipDesk->id, 'case_type' => 'suggestion'])
        ->assertRedirect(route('tip-offs.units.index'));
    $this->actingAs($this->admin)->post(route('public-complaints.units.store'), ['organization_id' => $this->s->organization->id, 'organization_unit_id' => $this->office->id, 'case_type' => 'complaint'])
        ->assertRedirect(route('public-complaints.units.index'));

    $this->actingAs($this->admin)->get(route('tip-offs.units.index'))
        ->assertInertia(fn (Assert $page) => $page->where('caseType', 'suggestion')->has('units', 1)->where('units.0.unit', 'Tip-off Desk'));
    $this->actingAs($this->admin)->get(route('public-complaints.units.index'))
        ->assertInertia(fn (Assert $page) => $page->where('caseType', 'complaint')->has('units', 1)->where('units.0.unit', 'Complaints Office'));
    $this->actingAs($this->admin)->get('/feedback-admin/handling-units')->assertRedirect('/tip-off-admin/units');
});

it('gives each module its own unit address, and a unit answers only at its own', function (): void {
    expect(route('service-feedback.units.index', [], false))->toBe('/feedback-admin/units')
        ->and(route('tip-offs.units.index', [], false))->toBe('/tip-off-admin/units')
        ->and(route('public-complaints.units.index', [], false))->toBe('/complaint-admin/units');

    $this->actingAs($this->admin)->post(route('service-feedback.units.store'), ['organization_id' => $this->s->organization->id, 'organization_unit_id' => $this->office->id])
        ->assertRedirect(route('service-feedback.units.index'));
    $feedbackUnit = PublicCaseHandlingUnit::query()->sole();
    expect($feedbackUnit->case_type)->toBe('feedback');
    $this->actingAs($this->admin)->get(route('service-feedback.units.index'))
        ->assertInertia(fn (Assert $page) => $page->where('caseType', 'feedback')->has('units', 1));
    $this->actingAs($this->admin)->get(route('tip-offs.units.index'))->assertInertia(fn (Assert $page) => $page->has('units', 0));

    // The feedback unit is not reachable through the Tip-off or complaint address.
    $member = ['employee_id' => $this->officer->id, 'responsibility' => 'head', 'effective_from' => now()->toDateString(), 'signature' => UploadedFile::fake()->image('sig.png')];
    $this->actingAs($this->admin)->post(route('tip-offs.units.members.store', $feedbackUnit), $member)->assertNotFound();
    $this->actingAs($this->admin)->patch(route('public-complaints.units.update', $feedbackUnit), ['status' => 'inactive'])->assertNotFound();
    $this->actingAs($this->admin)->post(route('service-feedback.units.members.store', $feedbackUnit), $member)->assertRedirect(route('service-feedback.units.index'));

    // A feedback unit never receives routed Tip-offs or complaints.
    $this->actingAs($this->admin)->get(route('public-complaints.routing-rules.handlers', ['organization_id' => $this->s->organization->id]))
        ->assertJsonCount(3, 'units');
});
