<?php

declare(strict_types=1);

use App\Enums\OrganizationScopeType;
use App\Models\OrganizationUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Feedback\Application\Services\OrganizationFeedbackQrService;
use Modules\Feedback\Application\Services\PublicFeedbackAssignmentService;
use Modules\Feedback\Application\Services\PublicFeedbackRoutingService;
use Modules\Feedback\Contracts\Data\ServiceFeedbackStatus;
use Modules\Feedback\Contracts\Data\ServiceFeedbackType;
use Modules\Feedback\Domain\Enums\PublicFeedbackHandlerType;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;
use Modules\Feedback\Domain\Models\PublicFeedbackAssignment;
use Modules\Feedback\Domain\Models\PublicFeedbackRoutingRule;
use Modules\Feedback\Domain\Models\PublicFeedbackStatusHistory;
use Tests\Support\PublicFeedbackScenario;

/*
 * Routing rules send a new suggestion or complaint to a handler (brief §45);
 * without a rule the submission is UNROUTED and an administrator is alerted,
 * never the target employee (§46); rules respect organization scope (§47);
 * every assignment is kept (§53).
 */

beforeEach(function (): void {
    app()->setLocale('en');

    foreach ([
        'service_feedback.view', 'service_feedback.review', 'service_feedback.complaints.view', 'service_feedback.complaints.review',
        'service_feedback.assign', 'service_feedback.reassign', 'service_feedback.resolve', 'service_feedback.close', 'service_feedback.manage_routing',
        'service_feedback.view_employee_target', 'service_feedback.view_organization_target',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('Organizational Admin', 'web')->syncPermissions([
        'service_feedback.view', 'service_feedback.complaints.view', 'service_feedback.complaints.review',
        'service_feedback.assign', 'service_feedback.reassign', 'service_feedback.manage_routing', 'service_feedback.view_employee_target', 'service_feedback.view_organization_target',
    ]);
    Role::findOrCreate('Service Quality Team', 'web')->syncPermissions(['service_feedback.complaints.view', 'service_feedback.complaints.review', 'service_feedback.view_employee_target', 'service_feedback.view_organization_target']);

    $this->a = PublicFeedbackScenario::employeeTarget('RT');
    $this->b = PublicFeedbackScenario::employeeTarget('RT2');
    RateLimiter::clear('sf-submit:'.$this->a->token->token.'|127.0.0.1');
    RateLimiter::clear('sf-submit-ip:127.0.0.1');

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('Super Admin');

    $this->orgAdmin = User::factory()->create();
    $this->orgAdmin->assignRole('Organizational Admin');
    UserOrganizationScope::query()->create([
        'user_id' => $this->orgAdmin->id,
        'organization_id' => $this->a->organization->id,
        'scope_type' => OrganizationScopeType::Self,
    ]);

    $this->handler = User::factory()->create(['name' => 'Quality Officer']);
    $this->handler->assignRole('Service Quality Team');
    UserOrganizationScope::query()->create([
        'user_id' => $this->handler->id,
        'organization_id' => $this->a->organization->id,
        'scope_type' => OrganizationScopeType::Self,
    ]);

    $this->orgToken = app(OrganizationFeedbackQrService::class)->ensureActive($this->a->organization, $this->superAdmin)->public_token;
    RateLimiter::clear('sf-submit:'.$this->orgToken.'|127.0.0.1');
});

function rtRule(array $overrides = []): PublicFeedbackRoutingRule
{
    return PublicFeedbackRoutingRule::query()->create(array_merge([
        'organization_id' => test()->a->organization->id,
        'target_type' => null,
        'submission_type' => null,
        'handler_type' => 'user',
        'handler_id' => (string) test()->handler->id,
        'priority' => 100,
        'is_active' => true,
        'created_by' => test()->superAdmin->id,
    ], $overrides));
}

it('assigns a new complaint to the handler of the matching rule and records the assignment', function (): void {
    $rule = rtRule(['submission_type' => 'complaint']);

    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', pfComplete('complaint', ['message' => 'The counter closed early.']))->assertRedirect();

    $complaint = EmployeeServiceFeedback::query()->sole();
    $assignment = PublicFeedbackAssignment::query()->where('submission_id', $complaint->id)->sole();

    expect($complaint->status)->toBe(ServiceFeedbackStatus::Assigned)
        ->and($complaint->assigned_at)->not->toBeNull()
        ->and($assignment->handler_type->value)->toBe('user')
        ->and($assignment->handler_id)->toBe((string) $this->handler->id)
        ->and($assignment->routing_rule_id)->toBe($rule->id)
        ->and($assignment->assigned_by)->toBeNull()
        ->and($assignment->ended_at)->toBeNull()
        ->and(PublicFeedbackStatusHistory::query()->where('submission_id', $complaint->id)->pluck('to_status')->all())->toBe(['pending', 'assigned']);
});

it('picks the most specific active rule in effect, then the priority', function (): void {
    $unit = OrganizationUnit::query()->create([
        'organization_id' => $this->a->organization->id, 'unit_type' => 'department', 'code' => 'SQ', 'name_en' => 'Service Quality', 'name_am' => 'የአገልግሎት ጥራት', 'status' => 'active',
    ]);
    rtRule(['priority' => 10]);                                                       // organization-wide, any type
    $byType = rtRule(['submission_type' => 'complaint', 'priority' => 50, 'handler_type' => 'organization_unit', 'handler_id' => $unit->id]);
    rtRule(['submission_type' => 'complaint', 'priority' => 5, 'is_active' => false]);
    rtRule(['submission_type' => 'complaint', 'priority' => 1, 'effective_to' => now()->subDay()]);
    rtRule(['organization_id' => null, 'submission_type' => 'complaint', 'priority' => 1]); // city-wide loses to the institution's own

    $complaint = EmployeeServiceFeedback::query()->create([
        'employee_id' => $this->a->employee->id, 'organization_id' => $this->a->organization->id, 'type' => ServiceFeedbackType::Complaint,
        'comment' => 'x', 'status' => ServiceFeedbackStatus::Pending, 'submitted_at' => now(),
    ]);
    expect(app(PublicFeedbackRoutingService::class)->resolve($complaint)?->id)->toBe($byType->id);

    $suggestion = EmployeeServiceFeedback::query()->create([
        'employee_id' => $this->a->employee->id, 'organization_id' => $this->a->organization->id, 'type' => ServiceFeedbackType::Suggestion,
        'comment' => 'z', 'status' => ServiceFeedbackStatus::Pending, 'submitted_at' => now(),
    ]);
    expect(app(PublicFeedbackRoutingService::class)->resolve($suggestion)?->priority)->toBe(10);
});

it('marks a submission unrouted when no rule matches and alerts routing administrators, never the target employee', function (): void {
    $targetUser = User::factory()->create(['employee_id' => $this->a->employee->id]);
    $targetUser->assignRole('Super Admin');

    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', pfComplete('complaint', ['message' => 'No one answers the phone.']))->assertRedirect();

    $complaint = EmployeeServiceFeedback::query()->sole();
    expect($complaint->status)->toBe(ServiceFeedbackStatus::Unrouted)
        ->and(PublicFeedbackAssignment::query()->count())->toBe(0)
        ->and(PublicFeedbackStatusHistory::query()->where('submission_id', $complaint->id)->pluck('to_status')->all())->toBe(['pending', 'unrouted']);

    $notified = DB::table('notifications')->pluck('notifiable_id')->map(fn ($id) => (int) $id)->all();
    expect($notified)->toContain((int) $this->orgAdmin->id)
        ->and($notified)->toContain((int) $this->superAdmin->id)
        ->and($notified)->not->toContain((int) $targetUser->id)
        ->and($notified)->not->toContain((int) $this->handler->id);
});

it('does not route rating feedback', function (): void {
    rtRule();

    $this->post('/service-feedback/'.$this->a->token->token.'/feedback', pfComplete('feedback', [
        'position_service_id' => $this->a->positionService->id, 'rating' => 4, 'comment' => 'Fine.',
    ]))->assertRedirect();

    $feedback = EmployeeServiceFeedback::query()->sole();
    expect($feedback->status)->toBe(ServiceFeedbackStatus::Pending)
        ->and(PublicFeedbackAssignment::query()->count())->toBe(0);
});

it('assigns an unrouted complaint by hand and reassigns it without losing the first handler', function (): void {
    $complaint = EmployeeServiceFeedback::query()->create([
        'employee_id' => $this->a->employee->id, 'organization_id' => $this->a->organization->id, 'type' => ServiceFeedbackType::Complaint,
        'comment' => 'x', 'status' => ServiceFeedbackStatus::Unrouted, 'submitted_at' => now(),
    ]);

    $this->actingAs($this->orgAdmin)
        ->post(route('service-submissions.assign', ['type' => 'complaint', 'submission' => $complaint]), [
            'handler_type' => 'user', 'handler_id' => (string) $this->handler->id, 'reason' => 'Quality team takes counter complaints.',
        ])->assertRedirect();

    $complaint->refresh();
    expect($complaint->status)->toBe(ServiceFeedbackStatus::Assigned)->and($complaint->assigned_at)->not->toBeNull();

    $this->actingAs($this->orgAdmin)
        ->post(route('service-submissions.assign', ['type' => 'complaint', 'submission' => $complaint]), [
            'handler_type' => 'role', 'handler_id' => (string) Role::findByName('Service Quality Team', 'web')->id, 'reason' => 'Officer on leave.',
        ])->assertRedirect();

    $assignments = PublicFeedbackAssignment::query()->where('submission_id', $complaint->id)->orderBy('assigned_at')->get();
    expect($assignments)->toHaveCount(2)
        ->and($assignments[0]->ended_at)->not->toBeNull()
        ->and($assignments[0]->handler_type->value)->toBe('user')
        ->and($assignments[1]->ended_at)->toBeNull()
        ->and($assignments[1]->handler_type->value)->toBe('role')
        ->and($assignments[1]->handler_name_snapshot)->toBe('Service Quality Team')
        ->and((int) $assignments[1]->assigned_by)->toBe((int) $this->orgAdmin->id)
        ->and($complaint->fresh()->status)->toBe(ServiceFeedbackStatus::Assigned);
});

it('refuses a handler outside the submission organization', function (): void {
    $complaint = EmployeeServiceFeedback::query()->create([
        'employee_id' => $this->a->employee->id, 'organization_id' => $this->a->organization->id, 'type' => ServiceFeedbackType::Complaint,
        'comment' => 'x', 'status' => ServiceFeedbackStatus::Unrouted, 'submitted_at' => now(),
    ]);
    $foreignUnit = OrganizationUnit::query()->create([
        'organization_id' => $this->b->organization->id, 'unit_type' => 'department', 'code' => 'FX', 'name_en' => 'Foreign unit', 'status' => 'active',
    ]);

    $this->actingAs($this->orgAdmin)
        ->post(route('service-submissions.assign', ['type' => 'complaint', 'submission' => $complaint]), ['handler_type' => 'organization_unit', 'handler_id' => $foreignUnit->id])
        ->assertSessionHasErrors('handler_id');

    expect(PublicFeedbackAssignment::query()->count())->toBe(0)
        ->and($complaint->fresh()->status)->toBe(ServiceFeedbackStatus::Unrouted);
});

it('lets a scoped admin manage rules only inside their scope and city rules only with city access', function (): void {
    $payload = [
        'organization_id' => $this->a->organization->id, 'target_type' => 'employee', 'submission_type' => 'complaint',
        'handler_type' => 'user', 'handler_id' => (string) $this->handler->id, 'priority' => 20, 'effective_from' => null, 'effective_to' => null, 'is_active' => true,
    ];

    $this->actingAs($this->orgAdmin)->post(route('service-feedback.routing-rules.store'), $payload)->assertRedirect();
    expect(PublicFeedbackRoutingRule::query()->where('organization_id', $this->a->organization->id)->count())->toBe(1);

    $this->actingAs($this->orgAdmin)->post(route('service-feedback.routing-rules.store'), array_merge($payload, ['organization_id' => $this->b->organization->id]))->assertForbidden();
    $this->actingAs($this->orgAdmin)->post(route('service-feedback.routing-rules.store'), array_merge($payload, ['organization_id' => null]))->assertForbidden();

    // Organization A may not route to a unit of organization B.
    $foreignUnit = OrganizationUnit::query()->create(['organization_id' => $this->b->organization->id, 'unit_type' => 'department', 'code' => 'FX', 'name_en' => 'Foreign unit', 'status' => 'active']);
    $this->actingAs($this->orgAdmin)->post(route('service-feedback.routing-rules.store'), array_merge($payload, ['handler_type' => 'organization_unit', 'handler_id' => $foreignUnit->id]))
        ->assertSessionHasErrors('handler_id');

    // A city-wide rule may not name a user confined to one institution.
    $this->actingAs($this->superAdmin)->post(route('service-feedback.routing-rules.store'), array_merge($payload, ['organization_id' => null]))->assertSessionHasErrors('handler_id');
    $this->actingAs($this->superAdmin)->post(route('service-feedback.routing-rules.store'), array_merge($payload, ['organization_id' => null, 'handler_type' => 'role', 'handler_id' => (string) Role::findByName('Service Quality Team', 'web')->id]))->assertRedirect();
    expect(PublicFeedbackRoutingRule::query()->whereNull('organization_id')->count())->toBe(1);

    $this->actingAs($this->orgAdmin)
        ->get(route('service-feedback.routing-rules.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceFeedback/RoutingRules/Index')
            ->has('rules', 1)
            ->where('rules.0.handler_name', 'Quality Officer'));
});

it('retires a rule that routed submissions instead of deleting it', function (): void {
    $rule = rtRule(['submission_type' => 'complaint']);
    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', pfComplete('complaint', ['message' => 'Routed once.']))->assertRedirect();

    $this->actingAs($this->superAdmin)->delete(route('service-feedback.routing-rules.destroy', $rule))->assertRedirect();
    expect($rule->fresh())->not->toBeNull()->and($rule->fresh()->is_active)->toBeFalse();

    $unused = rtRule(['submission_type' => 'suggestion']);
    $this->actingAs($this->superAdmin)->delete(route('service-feedback.routing-rules.destroy', $unused))->assertRedirect();
    expect(PublicFeedbackRoutingRule::query()->find($unused->id))->toBeNull();
});

it('shows the assigned handler in the inbox and filters to my queue', function (): void {
    rtRule(['submission_type' => 'complaint']);
    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', pfComplete('complaint', ['message' => 'Routed to the officer.']))->assertRedirect();
    EmployeeServiceFeedback::query()->create([
        'employee_id' => $this->a->employee->id, 'organization_id' => $this->a->organization->id, 'type' => ServiceFeedbackType::Complaint,
        'comment' => 'Nobody yet', 'status' => ServiceFeedbackStatus::Unrouted, 'submitted_at' => now(),
    ]);

    $this->actingAs($this->handler)
        ->get(route('service-submissions.complaints', ['mine' => 1]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.total', 1)
            ->where('entries.data.0.handler', 'Quality Officer'));

    $this->actingAs($this->handler)
        ->get(route('service-submissions.complaints'))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 2));
});

it('stores a complaint as unrouted when the matching rule names a handler that no longer exists, instead of refusing the public submission', function (): void {
    $ghost = User::factory()->create(['name' => 'Left the service']);
    rtRule(['handler_id' => (string) $ghost->id, 'priority' => 1]);
    $ghost->forceDelete();

    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', pfComplete('complaint', ['message' => 'The counter closed early.']))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $complaint = EmployeeServiceFeedback::query()->sole();
    expect($complaint->status)->toBe(ServiceFeedbackStatus::Unrouted)
        ->and(PublicFeedbackAssignment::query()->count())->toBe(0)
        ->and(PublicFeedbackStatusHistory::query()->where('submission_id', $complaint->id)->pluck('to_status')->all())->toBe(['pending', 'unrouted'])
        ->and(DB::table('notifications')->pluck('notifiable_id')->map(fn ($id) => (int) $id)->all())->toContain((int) $this->orgAdmin->id);
});

it('falls through to the next usable rule when the most specific one cannot be used', function (): void {
    $ghost = User::factory()->create(['name' => 'Left the service']);
    rtRule(['handler_id' => (string) $ghost->id, 'submission_type' => 'complaint', 'priority' => 1]);
    $ghost->forceDelete();
    rtRule(['priority' => 50]);

    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', pfComplete('complaint', ['message' => 'The counter closed early.']))->assertSessionHasNoErrors();

    $complaint = EmployeeServiceFeedback::query()->sole();
    expect($complaint->status)->toBe(ServiceFeedbackStatus::Assigned)
        ->and(PublicFeedbackAssignment::query()->sole()->handler_id)->toBe((string) $this->handler->id);
});

/** A public-selectable office of institution A and a reviewer confined to A who can handle it. */
function rtUnitScenario(): array
{
    $unit = OrganizationUnit::query()->create([
        'organization_id' => test()->a->organization->id, 'unit_type' => 'office', 'code' => 'RT-PERMITS', 'name_en' => 'Permits office', 'name_am' => 'የፈቃድ ጽሕፈት ቤት', 'status' => 'active',
    ]);
    $lead = User::factory()->create(['name' => 'Permits Lead']);
    $lead->assignRole('Service Quality Team');
    UserOrganizationScope::query()->create(['user_id' => $lead->id, 'organization_id' => test()->a->organization->id, 'scope_type' => OrganizationScopeType::Self]);

    return [$unit, $lead];
}

it('routes by the relevant work unit the visitor named, sends General / Not sure to the institution-wide rule, and reassignment never moves the unit', function (): void {
    [$permits, $lead] = rtUnitScenario();
    $wide = rtRule(['priority' => 50]);
    $narrow = rtRule(['organization_unit_id' => $permits->id, 'handler_id' => (string) $lead->id, 'priority' => 50]);

    $this->post('/feedback/'.$this->orgToken.'/complaint', pfComplete('complaint', ['message' => 'The permits counter closed early.', 'organization_unit_id' => $permits->id, 'unit_selection_mode' => 'SELECTED']))
        ->assertSessionHasNoErrors();
    $about = EmployeeServiceFeedback::query()->sole();
    expect($about->organization_unit_id)->toBe($permits->id)
        ->and($about->organization_unit_name_snapshot)->toBe('Permits office')
        ->and($about->currentAssignment->handler_id)->toBe((string) $lead->id)
        ->and($about->currentAssignment->routing_rule_id)->toBe($narrow->id);

    RateLimiter::clear('sf-submit:'.$this->orgToken.'|127.0.0.1');
    $this->post('/feedback/'.$this->orgToken.'/complaint', pfComplete('complaint', ['message' => 'Nobody answers the phone.', 'unit_selection_mode' => 'GENERAL']))
        ->assertSessionHasNoErrors();
    $general = EmployeeServiceFeedback::query()->where('unit_selection_mode', 'GENERAL')->sole();
    expect($general->organization_unit_id)->toBeNull()
        ->and($general->currentAssignment->handler_id)->toBe((string) $this->handler->id)
        ->and($general->currentAssignment->routing_rule_id)->toBe($wide->id);

    // The handler changes; what the submission is about does not.
    app(PublicFeedbackAssignmentService::class)->assign($about, PublicFeedbackHandlerType::User, (string) $this->handler->id, $this->superAdmin);
    $about->refresh();
    expect($about->currentAssignment->handler_id)->toBe((string) $this->handler->id)
        ->and($about->organization_unit_id)->toBe($permits->id)
        ->and($about->organization_unit_name_snapshot)->toBe('Permits office')
        ->and($about->organization_target_id)->toBe($this->a->organization->id);
});

it('leaves a General / Not sure complaint unrouted when every rule names a unit', function (): void {
    [$permits, $lead] = rtUnitScenario();
    rtRule(['organization_unit_id' => $permits->id, 'handler_id' => (string) $lead->id]);

    $this->post('/feedback/'.$this->orgToken.'/complaint', pfComplete('complaint', ['message' => 'Nobody answers the phone.', 'unit_selection_mode' => 'GENERAL']))->assertSessionHasNoErrors();

    expect(EmployeeServiceFeedback::query()->sole()->status)->toBe(ServiceFeedbackStatus::Unrouted)
        ->and(PublicFeedbackAssignment::query()->count())->toBe(0);
});

it('accepts a unit only on its own institution\'s rule and shows it on the rules page', function (): void {
    [$permits, $lead] = rtUnitScenario();
    $foreign = OrganizationUnit::query()->create(['organization_id' => $this->b->organization->id, 'unit_type' => 'office', 'code' => 'RT-FOREIGN', 'name_en' => 'Foreign office', 'status' => 'active']);
    $payload = [
        'organization_id' => $this->a->organization->id, 'target_type' => 'organization', 'submission_type' => 'complaint',
        'handler_type' => 'user', 'handler_id' => (string) $lead->id, 'priority' => 20, 'is_active' => true,
    ];

    $this->actingAs($this->orgAdmin)->post(route('service-feedback.routing-rules.store'), $payload + ['organization_unit_id' => $foreign->id])->assertSessionHasErrors('organization_unit_id');
    $this->actingAs($this->superAdmin)->post(route('service-feedback.routing-rules.store'), array_merge($payload, ['organization_id' => null, 'handler_type' => 'role', 'handler_id' => (string) Role::findByName('Service Quality Team', 'web')->id, 'organization_unit_id' => $permits->id]))
        ->assertSessionHasErrors('organization_unit_id');
    expect(PublicFeedbackRoutingRule::query()->count())->toBe(0);

    $this->actingAs($this->orgAdmin)->post(route('service-feedback.routing-rules.store'), $payload + ['organization_unit_id' => $permits->id])->assertRedirect();
    expect(PublicFeedbackRoutingRule::query()->sole()->organization_unit_id)->toBe($permits->id);

    $this->actingAs($this->orgAdmin)->get(route('service-feedback.routing-rules.index'))->assertInertia(fn (Assert $page) => $page
        ->where('rules.0.organization_unit_id', $permits->id)
        ->where('rules.0.unit.en', 'Permits office')
        ->where('rules.0.unit.am', 'የፈቃድ ጽሕፈት ቤት'));
});

it('filters the routing rules page by status and organization like every other list', function (): void {
    rtRule(['priority' => 10]);
    rtRule(['priority' => 20, 'is_active' => false]);

    $this->actingAs($this->superAdmin)
        ->get(route('service-feedback.routing-rules.index', ['status' => 'inactive']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rules', 1)
            ->where('rules.0.is_active', false)
            ->where('filters.status', 'inactive')
        );

    $this->actingAs($this->superAdmin)
        ->get(route('service-feedback.routing-rules.index', ['organization_id' => '00000000-0000-0000-0000-000000000000']))
        ->assertInertia(fn ($page) => $page->has('rules', 0));
});

it('adds and edits a rule on its own page, inside the user scope', function (): void {
    $rule = rtRule();

    $this->actingAs($this->orgAdmin)
        ->get(route('service-feedback.routing-rules.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceFeedback/RoutingRules/Create')
            ->where('canManageCityWide', false)
            ->has('organizations'));

    $this->actingAs($this->orgAdmin)
        ->get(route('service-feedback.routing-rules.edit', $rule))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceFeedback/RoutingRules/Edit')
            ->where('rule.id', $rule->id)
            ->where('rule.handler_name', 'Quality Officer'));

    // A city-wide rule is outside the scoped admin's reach, and open to a city-level account.
    $cityRule = rtRule(['organization_id' => null, 'handler_type' => 'role', 'handler_id' => (string) Role::findByName('Service Quality Team', 'web')->id]);
    $this->actingAs($this->orgAdmin)->get(route('service-feedback.routing-rules.edit', $cityRule))->assertForbidden();
    $this->actingAs($this->superAdmin)->get(route('service-feedback.routing-rules.edit', $cityRule))->assertOk();
});
