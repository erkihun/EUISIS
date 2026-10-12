<?php

declare(strict_types=1);

use App\Enums\OrganizationScopeType;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Models\EmployeeServiceFeedback;
use App\Models\OrganizationUnit;
use App\Models\Permission;
use App\Models\PublicFeedbackAssignment;
use App\Models\PublicFeedbackCategory;
use App\Models\PublicFeedbackRoutingRule;
use App\Models\PublicFeedbackStatusHistory;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\ServiceFeedback\OrganizationFeedbackQrService;
use App\Services\ServiceFeedback\PublicFeedbackRoutingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
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

    $this->category = PublicFeedbackCategory::query()->create([
        'code' => 'WAITING', 'name_en' => 'Waiting time', 'submission_types' => ['suggestion', 'complaint'],
        'target_types' => ['employee', 'organization'], 'organization_id' => null, 'is_active' => true, 'sort_order' => 1,
    ]);
});

function rtRule(array $overrides = []): PublicFeedbackRoutingRule
{
    return PublicFeedbackRoutingRule::query()->create(array_merge([
        'organization_id' => test()->a->organization->id,
        'target_type' => null,
        'submission_type' => null,
        'category_id' => null,
        'handler_type' => 'user',
        'handler_id' => (string) test()->handler->id,
        'priority' => 100,
        'is_active' => true,
        'created_by' => test()->superAdmin->id,
    ], $overrides));
}

it('assigns a new complaint to the handler of the matching rule and records the assignment', function (): void {
    $rule = rtRule(['submission_type' => 'complaint']);

    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', ['message' => 'The counter closed early.'])->assertRedirect();

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
    rtRule(['submission_type' => 'complaint', 'priority' => 50, 'handler_type' => 'organization_unit', 'handler_id' => $unit->id]);
    $byCategory = rtRule(['submission_type' => 'complaint', 'category_id' => $this->category->id, 'priority' => 90]);
    rtRule(['submission_type' => 'complaint', 'category_id' => $this->category->id, 'priority' => 5, 'is_active' => false]);
    rtRule(['submission_type' => 'complaint', 'category_id' => $this->category->id, 'priority' => 1, 'effective_to' => now()->subDay()]);
    rtRule(['organization_id' => null, 'submission_type' => 'complaint', 'category_id' => $this->category->id, 'priority' => 1]); // city-wide loses to the institution's own

    $complaint = EmployeeServiceFeedback::query()->create([
        'employee_id' => $this->a->employee->id, 'organization_id' => $this->a->organization->id, 'type' => ServiceFeedbackType::Complaint,
        'comment' => 'x', 'status' => ServiceFeedbackStatus::Pending, 'category_id' => $this->category->id, 'submitted_at' => now(),
    ]);
    expect(app(PublicFeedbackRoutingService::class)->resolve($complaint)?->id)->toBe($byCategory->id);

    $uncategorised = EmployeeServiceFeedback::query()->create([
        'employee_id' => $this->a->employee->id, 'organization_id' => $this->a->organization->id, 'type' => ServiceFeedbackType::Complaint,
        'comment' => 'y', 'status' => ServiceFeedbackStatus::Pending, 'submitted_at' => now(),
    ]);
    expect(app(PublicFeedbackRoutingService::class)->resolve($uncategorised)?->handler_type->value)->toBe('organization_unit');

    $suggestion = EmployeeServiceFeedback::query()->create([
        'employee_id' => $this->a->employee->id, 'organization_id' => $this->a->organization->id, 'type' => ServiceFeedbackType::Suggestion,
        'comment' => 'z', 'status' => ServiceFeedbackStatus::Pending, 'submitted_at' => now(),
    ]);
    expect(app(PublicFeedbackRoutingService::class)->resolve($suggestion)?->priority)->toBe(10);
});

it('marks a submission unrouted when no rule matches and alerts routing administrators, never the target employee', function (): void {
    $targetUser = User::factory()->create(['employee_id' => $this->a->employee->id]);
    $targetUser->assignRole('Super Admin');

    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', ['message' => 'No one answers the phone.'])->assertRedirect();

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

    $this->post('/service-feedback/'.$this->a->token->token.'/feedback', [
        'position_service_id' => $this->a->positionService->id, 'rating' => 4, 'comment' => 'Fine.',
    ])->assertRedirect();

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
        'organization_id' => $this->a->organization->id, 'target_type' => 'employee', 'submission_type' => 'complaint', 'category_id' => null,
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
    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', ['message' => 'Routed once.'])->assertRedirect();

    $this->actingAs($this->superAdmin)->delete(route('service-feedback.routing-rules.destroy', $rule))->assertRedirect();
    expect($rule->fresh())->not->toBeNull()->and($rule->fresh()->is_active)->toBeFalse();

    $unused = rtRule(['submission_type' => 'suggestion']);
    $this->actingAs($this->superAdmin)->delete(route('service-feedback.routing-rules.destroy', $unused))->assertRedirect();
    expect(PublicFeedbackRoutingRule::query()->find($unused->id))->toBeNull();
});

it('shows the assigned handler in the inbox and filters to my queue', function (): void {
    rtRule(['submission_type' => 'complaint']);
    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', ['message' => 'Routed to the officer.'])->assertRedirect();
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
