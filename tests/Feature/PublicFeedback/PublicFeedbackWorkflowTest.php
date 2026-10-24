<?php

declare(strict_types=1);

use App\Enums\OrganizationScopeType;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Models\EmployeeServiceFeedback;
use App\Models\Permission;
use App\Models\PublicFeedbackStatusHistory;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\ServiceFeedback\PublicFeedbackWorkflow;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PublicFeedbackScenario;

/*
 * Type-specific workflows on one table (brief §48–52): feedback stays
 * lightweight, suggestions end in an outcome, complaints pass through
 * assignment and review. Every transition leaves an immutable history row,
 * and the employee a complaint is about never moves it (§54).
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
        'service_feedback.view', 'service_feedback.review', 'service_feedback.complaints.view', 'service_feedback.complaints.review',
        'service_feedback.assign', 'service_feedback.reassign', 'service_feedback.resolve', 'service_feedback.close', 'service_feedback.view_employee_target', 'service_feedback.view_organization_target',
    ]);
    Role::findOrCreate('Reviewer', 'web')->syncPermissions(['service_feedback.complaints.view', 'service_feedback.complaints.review', 'service_feedback.view_employee_target', 'service_feedback.view_organization_target']);

    $this->a = PublicFeedbackScenario::employeeTarget('WF');
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

    $this->reviewer = User::factory()->create();
    $this->reviewer->assignRole('Reviewer');
    // The reviewer works in the submissions' organization; organization-level roles need an explicit scope (ADR 0003).
    UserOrganizationScope::query()->create([
        'user_id' => $this->reviewer->id,
        'organization_id' => $this->a->organization->id,
        'scope_type' => OrganizationScopeType::Self,
    ]);
});

function wfSubmission(ServiceFeedbackType $type, ServiceFeedbackStatus $status = ServiceFeedbackStatus::Pending): EmployeeServiceFeedback
{
    return EmployeeServiceFeedback::query()->create([
        'employee_id' => test()->a->employee->id,
        'organization_id' => test()->a->organization->id,
        'type' => $type,
        'comment' => 'Workflow fixture',
        'rating' => $type === ServiceFeedbackType::Feedback ? 3 : null,
        'status' => $status,
        'submitted_at' => now(),
    ]);
}

it('records the initial status in the history when a submission is created', function (): void {
    $this->post('/service-feedback/'.$this->a->token->token.'/suggestion', pfComplete('suggestion', ['message' => 'Open the counter earlier.']))->assertRedirect();

    $submission = EmployeeServiceFeedback::query()->sole();
    $history = PublicFeedbackStatusHistory::query()->where('submission_id', $submission->id)->orderBy('transitioned_at')->get();

    expect($history->first()->from_status)->toBeNull()
        ->and($history->first()->to_status)->toBe('pending')
        ->and($history->first()->actor_id)->toBeNull();
});

it('defines a lightweight feedback workflow and a stronger complaint workflow', function (): void {
    $workflow = app(PublicFeedbackWorkflow::class);

    expect($workflow->allowedFrom(ServiceFeedbackType::Feedback, ServiceFeedbackStatus::Pending))
        ->toEqualCanonicalizing([ServiceFeedbackStatus::Reviewed, ServiceFeedbackStatus::Resolved])
        ->and($workflow->allowedFrom(ServiceFeedbackType::Feedback, ServiceFeedbackStatus::Reviewed))
        ->toEqualCanonicalizing([ServiceFeedbackStatus::Resolved, ServiceFeedbackStatus::Closed])
        ->and($workflow->allowedFrom(ServiceFeedbackType::Suggestion, ServiceFeedbackStatus::UnderReview))
        ->toEqualCanonicalizing([ServiceFeedbackStatus::Accepted, ServiceFeedbackStatus::NotAdopted])
        ->and($workflow->allowedFrom(ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Pending))
        ->toEqualCanonicalizing([ServiceFeedbackStatus::Assigned, ServiceFeedbackStatus::Unrouted])
        ->and($workflow->allowedFrom(ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Assigned))
        ->toEqualCanonicalizing([ServiceFeedbackStatus::UnderReview])
        ->and($workflow->allowedFrom(ServiceFeedbackType::Complaint, ServiceFeedbackStatus::UnderReview))
        ->toEqualCanonicalizing([ServiceFeedbackStatus::Resolved])
        ->and($workflow->allowedFrom(ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Resolved))
        ->toEqualCanonicalizing([ServiceFeedbackStatus::Closed])
        ->and($workflow->allowedFrom(ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Closed))
        ->toBe([]);
});

it('moves a complaint through assignment, review, resolution and closure with history and timestamps', function (): void {
    $complaint = wfSubmission(ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Assigned);

    $this->actingAs($this->orgAdmin)
        ->post(route('service-submissions.transition', ['type' => 'complaint', 'submission' => $complaint]), ['status' => 'under_review', 'reason' => 'Starting the review.'])
        ->assertRedirect();
    $this->actingAs($this->orgAdmin)
        ->post(route('service-submissions.transition', ['type' => 'complaint', 'submission' => $complaint]), ['status' => 'resolved', 'reason' => 'Counter reopened and the client was called back.'])
        ->assertRedirect();
    $this->actingAs($this->orgAdmin)
        ->post(route('service-submissions.transition', ['type' => 'complaint', 'submission' => $complaint]), ['status' => 'closed'])
        ->assertRedirect();

    $complaint->refresh();
    expect($complaint->status)->toBe(ServiceFeedbackStatus::Closed)
        ->and($complaint->resolved_at)->not->toBeNull()
        ->and($complaint->closed_at)->not->toBeNull();

    $history = PublicFeedbackStatusHistory::query()->where('submission_id', $complaint->id)->orderBy('transitioned_at')->get();
    expect($history->pluck('to_status')->all())->toBe(['under_review', 'resolved', 'closed'])
        ->and($history->pluck('from_status')->all())->toBe(['assigned', 'under_review', 'resolved'])
        ->and($history[1]->reason)->toBe('Counter reopened and the client was called back.')
        ->and((int) $history[1]->actor_id)->toBe((int) $this->orgAdmin->id);
});

it('rejects a transition the type does not allow', function (): void {
    $complaint = wfSubmission(ServiceFeedbackType::Complaint);

    // A complaint is never closed from pending, and never "accepted" like a suggestion.
    $this->actingAs($this->superAdmin)
        ->post(route('service-submissions.transition', ['type' => 'complaint', 'submission' => $complaint]), ['status' => 'closed'])
        ->assertSessionHasErrors('status');
    $this->actingAs($this->superAdmin)
        ->post(route('service-submissions.transition', ['type' => 'complaint', 'submission' => $complaint]), ['status' => 'accepted'])
        ->assertSessionHasErrors('status');

    expect($complaint->fresh()->status)->toBe(ServiceFeedbackStatus::Pending)
        ->and(PublicFeedbackStatusHistory::query()->where('submission_id', $complaint->id)->count())->toBe(0);
});

it('lets a suggestion end as accepted or not adopted', function (): void {
    $suggestion = wfSubmission(ServiceFeedbackType::Suggestion);

    $this->actingAs($this->orgAdmin)
        ->post(route('service-submissions.transition', ['type' => 'suggestion', 'submission' => $suggestion]), ['status' => 'under_review'])
        ->assertRedirect();
    $this->actingAs($this->orgAdmin)
        ->post(route('service-submissions.transition', ['type' => 'suggestion', 'submission' => $suggestion]), ['status' => 'not_adopted', 'reason' => 'Already covered by the queue system.'])
        ->assertRedirect();

    expect($suggestion->fresh()->status)->toBe(ServiceFeedbackStatus::NotAdopted)
        ->and($suggestion->fresh()->resolved_at)->not->toBeNull();
});

it('requires the matching permission for each transition', function (): void {
    $complaint = wfSubmission(ServiceFeedbackType::Complaint, ServiceFeedbackStatus::UnderReview);

    // The reviewer may review but holds neither resolve nor close.
    $this->actingAs($this->reviewer)
        ->post(route('service-submissions.transition', ['type' => 'complaint', 'submission' => $complaint]), ['status' => 'resolved'])
        ->assertForbidden();
    expect($complaint->fresh()->status)->toBe(ServiceFeedbackStatus::UnderReview);

    $assigned = wfSubmission(ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Assigned);
    $this->actingAs($this->reviewer)
        ->post(route('service-submissions.transition', ['type' => 'complaint', 'submission' => $assigned]), ['status' => 'under_review'])
        ->assertRedirect();
    expect($assigned->fresh()->status)->toBe(ServiceFeedbackStatus::UnderReview);
});

it('never lets the target employee transition or assign their own complaint', function (): void {
    $complaint = wfSubmission(ServiceFeedbackType::Complaint, ServiceFeedbackStatus::UnderReview);
    $this->superAdmin->forceFill(['employee_id' => $this->a->employee->id])->save();

    $this->actingAs($this->superAdmin)
        ->post(route('service-submissions.transition', ['type' => 'complaint', 'submission' => $complaint]), ['status' => 'resolved'])
        ->assertForbidden();
    $this->actingAs($this->superAdmin)
        ->post(route('service-submissions.assign', ['type' => 'complaint', 'submission' => $complaint]), ['handler_type' => 'user', 'handler_id' => (string) $this->orgAdmin->id])
        ->assertForbidden();

    expect($complaint->fresh()->status)->toBe(ServiceFeedbackStatus::UnderReview)
        ->and(PublicFeedbackStatusHistory::query()->where('submission_id', $complaint->id)->count())->toBe(0);
});

it('keeps the status history immutable', function (): void {
    $complaint = wfSubmission(ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Assigned);
    app(PublicFeedbackWorkflow::class)->transition($complaint, ServiceFeedbackStatus::UnderReview, $this->orgAdmin, 'Start');

    $row = PublicFeedbackStatusHistory::query()->where('submission_id', $complaint->id)->sole();

    expect(fn () => $row->forceFill(['to_status' => 'closed'])->save())->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class)
        ->and($row->fresh()->to_status)->toBe('under_review');
});

it('shows the history, the allowed transitions and the handler on the detail page', function (): void {
    $complaint = wfSubmission(ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Assigned);
    app(PublicFeedbackWorkflow::class)->transition($complaint, ServiceFeedbackStatus::UnderReview, $this->orgAdmin, 'Start');

    $this->actingAs($this->orgAdmin)
        ->get(route('service-submissions.show', ['type' => 'complaint', 'submission' => $complaint]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceFeedback/SubmissionDetail')
            ->where('entry.status', 'under_review')
            ->where('allowedTransitions', ['resolved'])
            ->where('history.0.to_status', 'under_review')
            ->where('history.0.reason', 'Start')
            ->where('history.0.actor', $this->orgAdmin->name)
            ->has('assignments', 0)
            ->where('currentAssignment', null)
            ->where('can.assign', true));

    // The reviewer sees the row but only the transition they may make, and cannot assign.
    $this->actingAs($this->reviewer)
        ->get(route('service-submissions.show', ['type' => 'complaint', 'submission' => $complaint]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('allowedTransitions', [])
            ->where('can.assign', false));
});

it('filters the inbox by the new statuses and counts them', function (): void {
    wfSubmission(ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Unrouted);
    wfSubmission(ServiceFeedbackType::Complaint, ServiceFeedbackStatus::UnderReview);
    wfSubmission(ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Closed);

    $this->actingAs($this->superAdmin)
        ->get(route('service-submissions.complaints', ['status' => 'unrouted']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.total', 1)
            ->where('counts.unrouted', 1)
            ->where('counts.under_review', 1)
            ->where('counts.closed', 1));
});
