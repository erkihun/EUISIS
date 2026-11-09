<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Services;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Feedback\Contracts\Data\ServiceFeedbackStatus;
use Modules\Feedback\Contracts\Data\ServiceFeedbackType;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;
use Modules\Feedback\Domain\Models\PublicFeedbackReferral;
use Modules\Feedback\Domain\Models\PublicFeedbackSubjectEmployee;

/**
 * Investigation subjects (brief §61–62) and controlled discipline referral
 * requests (§58–60) on a public complaint. Nothing here opens a discipline
 * case, a grievance or an appeal (§63–64): a referral is a request record
 * the discipline domain may act on later. The employee a complaint is
 * about, or names as a subject, takes no part in its referral (§54, §60).
 */
final class PublicFeedbackReferralService
{
    public const PERMISSION = 'service_feedback.refer_discipline';

    /** A referral is asked for once the complaint has been reviewed (§59). */
    private const REFERRABLE = [ServiceFeedbackStatus::UnderReview, ServiceFeedbackStatus::Resolved, ServiceFeedbackStatus::Closed, ServiceFeedbackStatus::Reviewed];

    public function __construct(
        private readonly WriteAuditLogAction $audit,
        private readonly PublicFeedbackNotifier $notifier,
    ) {}

    /**
     * @throws ValidationException when the employee is not of the complaint's institution, is its target, or is already named
     */
    public function addSubject(EmployeeServiceFeedback $complaint, string $employeeId, ?string $role, User $actor, ?Request $request = null): PublicFeedbackSubjectEmployee
    {
        $employee = Employee::query()->with('currentAssignment')->find($employeeId);

        if ($employee === null || $employee->currentAssignment === null || $complaint->organization_id === null
            || (string) $employee->currentAssignment->organization_id !== (string) $complaint->organization_id) {
            throw ValidationException::withMessages(['employee_id' => __('service-feedback.subject_not_in_organization')]);
        }
        if ($complaint->employee_id !== null && (string) $complaint->employee_id === (string) $employee->getKey()) {
            throw ValidationException::withMessages(['employee_id' => __('service-feedback.subject_is_target')]);
        }
        if ($complaint->subjects()->where('employee_id', $employee->getKey())->exists()) {
            throw ValidationException::withMessages(['employee_id' => __('service-feedback.subject_duplicate')]);
        }

        return DB::transaction(function () use ($complaint, $employee, $role, $actor, $request): PublicFeedbackSubjectEmployee {
            $subject = $complaint->subjects()->create([
                'employee_id' => $employee->getKey(),
                'employee_assignment_id' => $employee->current_assignment_id,
                'subject_role' => $role !== null && trim($role) !== '' ? trim($role) : null,
                'added_by' => $actor->getKey(),
                'added_at' => now(),
            ]);

            $this->audit->execute(
                eventType: AuditEventType::ServiceFeedbackSubjectAdded,
                actor: $actor,
                auditable: $complaint,
                organizationId: $complaint->organization_id,
                newValues: ['employee_id' => $employee->getKey(), 'subject_role' => $subject->subject_role],
                reason: 'Investigation subject named on a public complaint',
                request: $request,
            );

            return $subject;
        });
    }

    public function removeSubject(EmployeeServiceFeedback $complaint, PublicFeedbackSubjectEmployee $subject, User $actor, ?Request $request = null): void
    {
        DB::transaction(function () use ($complaint, $subject, $actor, $request): void {
            $this->audit->execute(
                eventType: AuditEventType::ServiceFeedbackSubjectRemoved,
                actor: $actor,
                auditable: $complaint,
                organizationId: $complaint->organization_id,
                oldValues: ['employee_id' => $subject->employee_id, 'subject_role' => $subject->subject_role],
                reason: 'Investigation subject removed from a public complaint',
                request: $request,
            );
            $subject->delete();
        });
    }

    /** The user is the employee the complaint is about, or one it names as a subject. */
    public function isInvolved(User $user, EmployeeServiceFeedback $complaint): bool
    {
        if ($user->employee_id === null) {
            return false;
        }
        if ($complaint->employee_id !== null && (string) $complaint->employee_id === (string) $user->employee_id) {
            return true;
        }

        return $complaint->subjects()->where('employee_id', $user->employee_id)->exists();
    }

    public function canRefer(User $user, EmployeeServiceFeedback $complaint): bool
    {
        return $complaint->type === ServiceFeedbackType::Complaint
            && $user->hasAnyPermission([self::PERMISSION])
            && ! $this->isInvolved($user, $complaint);
    }

    /** A new request may be made while none is open and none was accepted. */
    public function canRequest(User $user, EmployeeServiceFeedback $complaint): bool
    {
        return $this->canRefer($user, $complaint)
            && ! $complaint->referrals()->whereIn('status', ['requested', 'accepted'])->exists();
    }

    public function canDecide(User $user, PublicFeedbackReferral $referral): bool
    {
        return $referral->isOpen() && $this->canRefer($user, $referral->submission);
    }

    /**
     * @throws ValidationException when the complaint is not yet reviewed or a request is already open
     */
    public function request(EmployeeServiceFeedback $complaint, string $notes, User $actor, ?Request $request = null): PublicFeedbackReferral
    {
        if (! in_array($complaint->status, self::REFERRABLE, true)) {
            throw ValidationException::withMessages(['notes' => __('service-feedback.referral_not_reviewed')]);
        }
        if ($complaint->referrals()->where('status', 'requested')->exists()) {
            throw ValidationException::withMessages(['notes' => __('service-feedback.referral_open_exists')]);
        }
        if ($complaint->referrals()->where('status', 'accepted')->exists()) {
            throw ValidationException::withMessages(['notes' => __('service-feedback.referral_already_accepted')]);
        }

        return DB::transaction(function () use ($complaint, $notes, $actor, $request): PublicFeedbackReferral {
            $referral = $complaint->referrals()->create([
                'kind' => PublicFeedbackReferral::KIND_DISCIPLINE,
                'status' => 'requested',
                'notes' => trim($notes),
                'requested_by' => $actor->getKey(),
                'requested_at' => now(),
            ]);

            $this->audit->execute(
                eventType: AuditEventType::ServiceFeedbackReferralRequested,
                actor: $actor,
                auditable: $complaint,
                organizationId: $complaint->organization_id,
                newValues: ['referral_id' => $referral->getKey(), 'kind' => $referral->kind],
                reason: 'Discipline referral requested for a public complaint',
                request: $request,
            );
            $this->notifier->referred($complaint, $actor);

            return $referral;
        });
    }

    /**
     * @param  'accepted'|'declined'|'withdrawn'  $status
     *
     * @throws ValidationException when the request is no longer open
     */
    public function decide(PublicFeedbackReferral $referral, string $status, ?string $notes, User $actor, ?Request $request = null): PublicFeedbackReferral
    {
        if (! $referral->isOpen()) {
            throw ValidationException::withMessages(['status' => __('service-feedback.referral_already_decided')]);
        }

        return DB::transaction(function () use ($referral, $status, $notes, $actor, $request): PublicFeedbackReferral {
            $referral->forceFill([
                'status' => $status,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'decision_notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            ])->save();

            $complaint = $referral->submission;
            $this->audit->execute(
                eventType: AuditEventType::ServiceFeedbackReferralDecided,
                actor: $actor,
                auditable: $complaint,
                organizationId: $complaint->organization_id,
                oldValues: ['status' => 'requested'],
                newValues: ['referral_id' => $referral->getKey(), 'status' => $status],
                reason: 'Discipline referral '.$status,
                request: $request,
            );

            return $referral;
        });
    }
}
