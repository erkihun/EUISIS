<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;
use Modules\Feedback\Domain\Models\PublicFeedbackAssignment;
use Modules\Feedback\Domain\Models\PublicFeedbackRoutingRule;
use Tests\Support\PublicFeedbackScenario;

/*
 * ADR 0033: Tip-offs and complaints climb an escalation ladder. Each routing
 * rule serves one level and may give its handler response days; a case past
 * its level's deadline goes one level up, and a reviewer may escalate it.
 */

beforeEach(function (): void {
    app()->setLocale('en');
    foreach (['service_feedback.view', 'service_feedback.complaints.view', 'service_feedback.view_employee_target', 'service_feedback.view_organization_target', 'service_feedback.assign', 'service_feedback.reassign', 'service_feedback.review', 'service_feedback.complaints.review'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $this->role = Role::findOrCreate('Super Admin', 'web');
    $this->role->syncPermissions(Permission::all());
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');

    $this->employee = PublicFeedbackScenario::employeeTarget('ESC');
    RateLimiter::clear('sf-submit:'.$this->employee->token->token.'|127.0.0.1');
    RateLimiter::clear('sf-submit-ip:127.0.0.1');
    $this->base = '/service-feedback/'.$this->employee->token->token;

    $organization = $this->employee->organization->id;
    PublicFeedbackRoutingRule::query()->create(['organization_id' => $organization, 'submission_type' => 'complaint', 'handler_type' => 'user', 'handler_id' => (string) $this->admin->id, 'priority' => 1, 'escalation_level' => 1, 'response_days' => 3, 'is_active' => true, 'created_by' => $this->admin->id]);
    PublicFeedbackRoutingRule::query()->create(['organization_id' => $organization, 'submission_type' => 'complaint', 'handler_type' => 'role', 'handler_id' => (string) $this->role->id, 'priority' => 1, 'escalation_level' => 2, 'response_days' => 5, 'is_active' => true, 'created_by' => $this->admin->id]);
});

function escalationComplaint(object $test): EmployeeServiceFeedback
{
    $test->post($test->base.'/complaint', pfComplete('complaint', ['message' => 'Complaint.']))->assertSessionHasNoErrors();

    return EmployeeServiceFeedback::query()->where('type', 'complaint')->latest('created_at')->firstOrFail();
}

it('routes a new case to level 1 with its deadline and escalates it once the deadline passes', function (): void {
    $complaint = escalationComplaint($this);
    $first = $complaint->currentAssignment()->sole();
    expect($first->level)->toBe(1)
        ->and($first->handler_type->value)->toBe('user')
        ->and($first->due_at->diffInDays(now()->addDays(3), true))->toBeLessThan(0.01);

    $this->artisan('service-feedback:escalate-overdue')->assertSuccessful();
    expect($complaint->currentAssignment()->sole()->level)->toBe(1);

    $this->travel(4)->days();
    $this->actingAs($this->admin)->get(route('service-submissions.complaints.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('figures.overdue', 1));

    $this->artisan('service-feedback:escalate-overdue')->assertSuccessful();
    $second = $complaint->currentAssignment()->sole();
    expect($second->level)->toBe(2)
        ->and($second->handler_type->value)->toBe('role')
        ->and($second->due_at->isFuture())->toBeTrue()
        ->and(PublicFeedbackAssignment::query()->whereKey($first->id)->value('escalated'))->toBeTruthy();

    // At the top of the ladder an overdue case stays where it is, and shows as overdue.
    $this->travel(6)->days();
    $this->artisan('service-feedback:escalate-overdue')->assertSuccessful();
    expect($complaint->currentAssignment()->sole()->id)->toBe($second->id);
    $this->actingAs($this->admin)->get(route('service-submissions.show', ['type' => 'complaint', 'submission' => $complaint->id]))
        ->assertInertia(fn (Assert $page) => $page->where('currentAssignment.level', 2)->where('currentAssignment.overdue', true)->where('can.escalate', false));
});

it('lets a reviewer escalate with a reason, and keeps the deadline through a manual hand-over', function (): void {
    $complaint = escalationComplaint($this);
    $due = $complaint->currentAssignment()->sole()->due_at;

    // A manual hand-over inside level 1 keeps level 1's deadline.
    $this->actingAs($this->admin)->post(route('service-submissions.assign', ['type' => 'complaint', 'submission' => $complaint->id]), ['handler_type' => 'role', 'handler_id' => (string) $this->role->id])->assertSessionHasNoErrors();
    $handOver = $complaint->currentAssignment()->sole();
    expect($handOver->level)->toBe(1)->and($handOver->due_at->equalTo($due))->toBeTrue();

    $this->actingAs($this->admin)->get(route('service-submissions.show', ['type' => 'complaint', 'submission' => $complaint->id]))
        ->assertInertia(fn (Assert $page) => $page->where('can.escalate', true)->where('currentAssignment.level', 1));

    $route = route('service-submissions.escalate', ['type' => 'complaint', 'submission' => $complaint->id]);
    $this->actingAs($this->admin)->post($route, [])->assertSessionHasErrors('reason');
    $this->actingAs($this->admin)->post($route, ['reason' => 'Needs the directorate.'])->assertSessionHasNoErrors();
    $escalated = $complaint->currentAssignment()->sole();
    expect($escalated->level)->toBe(2)->and($escalated->reason)->toBe('Needs the directorate.');

    // No level 3 is configured.
    $this->actingAs($this->admin)->post($route, ['reason' => 'Again.'])->assertSessionHasErrors('escalation');
});

it('saves a rule with its level and response days', function (): void {
    Permission::findOrCreate('service_feedback.manage_routing', 'web');
    $this->role->givePermissionTo('service_feedback.manage_routing');

    $this->actingAs($this->admin)->post(route('service-feedback.routing-rules.store'), [
        'organization_id' => $this->employee->organization->id, 'submission_type' => 'suggestion',
        'handler_type' => 'user', 'handler_id' => (string) $this->admin->id, 'escalation_level' => 3, 'response_days' => 10,
    ])->assertSessionHasNoErrors();

    $rule = PublicFeedbackRoutingRule::query()->where('escalation_level', 3)->sole();
    expect($rule->response_days)->toBe(10);

    $this->actingAs($this->admin)->post(route('service-feedback.routing-rules.store'), [
        'handler_type' => 'user', 'handler_id' => (string) $this->admin->id, 'escalation_level' => 9,
    ])->assertSessionHasErrors('escalation_level');
});
