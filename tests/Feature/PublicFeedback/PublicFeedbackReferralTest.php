<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Enums\EmployeeStatus;
use App\Enums\OrganizationScopeType;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Feedback\Application\Notifications\PublicFeedbackNotification;
use Modules\Feedback\Contracts\Data\ServiceFeedbackStatus;
use Modules\Feedback\Contracts\Data\ServiceFeedbackType;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;
use Modules\Feedback\Domain\Models\PublicFeedbackReferral;
use Modules\Feedback\Domain\Models\PublicFeedbackSubjectEmployee;
use Tests\Support\PublicFeedbackScenario;

/*
 * Investigation subjects on an institution-targeted complaint never change
 * its target (brief §61–62). A referral to discipline is a separate,
 * controlled request that creates no discipline case, no grievance and no
 * appeal by itself (§58–60, §63–64, §122 45–47); the employee a complaint
 * is about, or names as a subject, takes no part in it.
 */

const PFRF_BASE = ['service_feedback.complaints.view', 'service_feedback.complaints.review', 'service_feedback.view_employee_target', 'service_feedback.view_organization_target'];

beforeEach(function (): void {
    app()->setLocale('en');

    foreach ([...PFRF_BASE, 'service_feedback.refer_discipline', 'service_feedback.manage_routing'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('Reviewer', 'web')->syncPermissions(PFRF_BASE);
    Role::findOrCreate('Referrer', 'web')->syncPermissions([...PFRF_BASE, 'service_feedback.refer_discipline']);

    $this->a = PublicFeedbackScenario::employeeTarget('REF');
    $this->b = PublicFeedbackScenario::employeeTarget('REF2');

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('Super Admin');

    $scoped = function (string $role): User {
        $user = User::factory()->create();
        $user->assignRole($role);
        UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $this->a->organization->id, 'scope_type' => OrganizationScopeType::Self]);

        return $user;
    };
    $this->reviewer = $scoped('Reviewer');
    $this->referrer = $scoped('Referrer');
    $this->decider = $scoped('Referrer');

    // A second employee of the same institution, and his own account holding the referral permission.
    $this->colleague = Employee::query()->create(['employee_number' => 'REF-EMP-2', 'first_name' => 'Kebede', 'last_name' => 'Alemu', 'full_name' => 'Kebede Alemu', 'status' => EmployeeStatus::Active->value]);
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $this->colleague->id, 'organization_id' => $this->a->organization->id, 'organization_unit_id' => $this->a->unit->id,
        'position_id' => $this->a->position->id, 'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => now()->toDateString(),
    ]);
    $this->colleague->forceFill(['current_assignment_id' => $assignment->id])->save();
    $this->colleagueUser = $scoped('Referrer');
    $this->colleagueUser->forceFill(['employee_id' => $this->colleague->id])->save();
});

function refComplaint(array $overrides = []): EmployeeServiceFeedback
{
    return EmployeeServiceFeedback::query()->create(array_merge([
        'employee_id' => null,
        'organization_target_id' => test()->a->organization->id,
        'target_type' => 'organization',
        'source_type' => 'organization_feedback_qr',
        'organization_id' => test()->a->organization->id,
        'type' => ServiceFeedbackType::Complaint,
        'comment' => 'Referral fixture',
        'reference_number' => 'PSC-2026-000900',
        'status' => ServiceFeedbackStatus::UnderReview,
        'submitted_at' => now(),
    ], $overrides));
}

it('names investigation subjects on an institution complaint without changing its target', function (): void {
    $complaint = refComplaint();

    $this->actingAs($this->reviewer)
        ->post(route('service-submissions.subjects.store', ['type' => 'complaint', 'submission' => $complaint]), ['employee_id' => $this->colleague->id, 'subject_role' => 'Counter officer on duty'])
        ->assertRedirect();

    $subject = PublicFeedbackSubjectEmployee::query()->sole();
    $complaint->refresh();
    expect($subject->employee_id)->toBe($this->colleague->id)
        ->and($subject->employee_assignment_id)->toBe($this->colleague->current_assignment_id)
        ->and($subject->subject_role)->toBe('Counter officer on duty')
        ->and((int) $subject->added_by)->toBe((int) $this->reviewer->id)
        ->and($complaint->target_type->value)->toBe('organization')
        ->and($complaint->employee_id)->toBeNull()
        ->and($complaint->organization_target_id)->toBe($this->a->organization->id);

    // Not twice, not an employee of another institution, not on a suggestion.
    $this->actingAs($this->reviewer)
        ->post(route('service-submissions.subjects.store', ['type' => 'complaint', 'submission' => $complaint]), ['employee_id' => $this->colleague->id])
        ->assertSessionHasErrors('employee_id');
    $this->actingAs($this->reviewer)
        ->post(route('service-submissions.subjects.store', ['type' => 'complaint', 'submission' => $complaint]), ['employee_id' => $this->b->employee->id])
        ->assertSessionHasErrors('employee_id');
    $suggestion = refComplaint(['type' => ServiceFeedbackType::Suggestion, 'reference_number' => null]);
    $this->actingAs($this->reviewer)
        ->post(route('service-submissions.subjects.store', ['type' => 'suggestion', 'submission' => $suggestion]), ['employee_id' => $this->colleague->id])
        ->assertNotFound();

    $this->actingAs($this->reviewer)
        ->get(route('service-submissions.show', ['type' => 'complaint', 'submission' => $complaint]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('subjects', 1)
            ->where('subjects.0.employee.full_name', 'Kebede Alemu')
            ->where('can.subjects', true));

    $this->actingAs($this->reviewer)
        ->delete(route('service-submissions.subjects.destroy', ['type' => 'complaint', 'submission' => $complaint, 'subject' => $subject]))
        ->assertRedirect();
    expect(PublicFeedbackSubjectEmployee::query()->count())->toBe(0)
        ->and(AuditLog::query()->whereIn('event_type', [AuditEventType::ServiceFeedbackSubjectAdded->value, AuditEventType::ServiceFeedbackSubjectRemoved->value])->count())->toBe(2);
});

it('records a controlled referral request that creates no discipline case, grievance or appeal', function (): void {
    Notification::fake();
    $complaint = refComplaint();

    $this->actingAs($this->reviewer)
        ->post(route('service-submissions.referrals.store', ['type' => 'complaint', 'submission' => $complaint]), ['notes' => 'Facts may justify a discipline process.'])
        ->assertForbidden();

    $this->actingAs($this->referrer)
        ->post(route('service-submissions.referrals.store', ['type' => 'complaint', 'submission' => $complaint]), ['notes' => 'Facts may justify a discipline process.'])
        ->assertRedirect();

    $referral = PublicFeedbackReferral::query()->sole();
    expect($referral->kind)->toBe('discipline')
        ->and($referral->status)->toBe('requested')
        ->and((int) $referral->requested_by)->toBe((int) $this->referrer->id)
        ->and($referral->disciplinary_case_id)->toBeNull()
        ->and(DB::table('grievances')->count())->toBe(0)
        ->and(DB::table('administrative_tribunal_cases')->count())->toBe(0)
        ->and(Schema::hasTable('disciplinary_cases') ? DB::table('disciplinary_cases')->count() : 0)->toBe(0)
        ->and($complaint->fresh()->status)->toBe(ServiceFeedbackStatus::UnderReview)
        ->and(AuditLog::query()->where('event_type', AuditEventType::ServiceFeedbackReferralRequested->value)->exists())->toBeTrue();

    // The other referral decision-makers in scope hear of it; the requester does not.
    Notification::assertSentTo($this->decider, PublicFeedbackNotification::class, fn (PublicFeedbackNotification $n) => $n->kind === 'referred');
    Notification::assertNotSentTo($this->referrer, PublicFeedbackNotification::class);

    // One open request at a time.
    $this->actingAs($this->decider)
        ->post(route('service-submissions.referrals.store', ['type' => 'complaint', 'submission' => $complaint]), ['notes' => 'Again.'])
        ->assertSessionHasErrors('notes');

    // Decision by an authorised user other than the requester; withdrawal by the requester.
    $this->actingAs($this->reviewer)
        ->patch(route('service-submissions.referrals.update', ['type' => 'complaint', 'submission' => $complaint, 'referral' => $referral]), ['status' => 'accepted'])
        ->assertForbidden();
    $this->actingAs($this->decider)
        ->patch(route('service-submissions.referrals.update', ['type' => 'complaint', 'submission' => $complaint, 'referral' => $referral]), ['status' => 'accepted', 'notes' => 'Forwarded to the discipline committee secretariat.'])
        ->assertRedirect();
    $referral->refresh();
    expect($referral->status)->toBe('accepted')
        ->and((int) $referral->decided_by)->toBe((int) $this->decider->id)
        ->and($referral->decided_at)->not->toBeNull()
        ->and(AuditLog::query()->where('event_type', AuditEventType::ServiceFeedbackReferralDecided->value)->exists())->toBeTrue();

    $this->actingAs($this->decider)
        ->patch(route('service-submissions.referrals.update', ['type' => 'complaint', 'submission' => $complaint, 'referral' => $referral]), ['status' => 'declined'])
        ->assertSessionHasErrors('status');

    $this->actingAs($this->referrer)
        ->get(route('service-submissions.show', ['type' => 'complaint', 'submission' => $complaint]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('referrals', 1)
            ->where('referrals.0.status', 'accepted')
            ->where('referrals.0.requested_by', $this->referrer->name)
            ->where('can.refer', false)
            ->where('can.decideReferral', false));
});

it('refuses a referral before the complaint has been reviewed', function (): void {
    $complaint = refComplaint(['status' => ServiceFeedbackStatus::Assigned]);

    $this->actingAs($this->referrer)
        ->post(route('service-submissions.referrals.store', ['type' => 'complaint', 'submission' => $complaint]), ['notes' => 'Too early.'])
        ->assertSessionHasErrors('notes');

    expect(PublicFeedbackReferral::query()->count())->toBe(0);
});

it('keeps the employee a complaint is about, or names as a subject, out of its referral', function (): void {
    // Employee-targeted complaint: the target's own account cannot request or decide.
    $targeted = refComplaint(['employee_id' => $this->a->employee->id, 'organization_target_id' => null, 'target_type' => 'employee', 'source_type' => 'employee_card_qr']);
    $targetUser = User::factory()->create(['employee_id' => $this->a->employee->id]);
    $targetUser->assignRole('Super Admin');
    $this->actingAs($targetUser)
        ->post(route('service-submissions.referrals.store', ['type' => 'complaint', 'submission' => $targeted]), ['notes' => 'About me.'])
        ->assertForbidden();

    // Institution complaint naming a subject: the subject's own account cannot request or decide.
    $complaint = refComplaint(['reference_number' => 'PSC-2026-000901']);
    PublicFeedbackSubjectEmployee::query()->create([
        'submission_id' => $complaint->id, 'employee_id' => $this->colleague->id, 'employee_assignment_id' => $this->colleague->current_assignment_id,
        'subject_role' => 'Officer', 'added_by' => $this->reviewer->id, 'added_at' => now(),
    ]);
    $this->actingAs($this->colleagueUser)
        ->post(route('service-submissions.referrals.store', ['type' => 'complaint', 'submission' => $complaint]), ['notes' => 'About me.'])
        ->assertForbidden();

    $this->actingAs($this->referrer)
        ->post(route('service-submissions.referrals.store', ['type' => 'complaint', 'submission' => $complaint]), ['notes' => 'Please review.'])
        ->assertRedirect();
    $referral = PublicFeedbackReferral::query()->where('submission_id', $complaint->id)->sole();
    $this->actingAs($this->colleagueUser)
        ->patch(route('service-submissions.referrals.update', ['type' => 'complaint', 'submission' => $complaint, 'referral' => $referral]), ['status' => 'declined'])
        ->assertForbidden();

    // The requester may withdraw; nobody else may "withdraw" for them.
    $this->actingAs($this->decider)
        ->patch(route('service-submissions.referrals.update', ['type' => 'complaint', 'submission' => $complaint, 'referral' => $referral]), ['status' => 'withdrawn'])
        ->assertForbidden();
    $this->actingAs($this->referrer)
        ->patch(route('service-submissions.referrals.update', ['type' => 'complaint', 'submission' => $complaint, 'referral' => $referral]), ['status' => 'withdrawn'])
        ->assertRedirect();
    expect($referral->fresh()->status)->toBe('withdrawn');
});
