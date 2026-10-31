<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Policies;

use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;
use Modules\Feedback\Application\Services\PublicFeedbackVisibility;
use Modules\Feedback\Contracts\Data\ServiceFeedbackStatus;
use Modules\Feedback\Contracts\Data\ServiceFeedbackType;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;

/**
 * Access control for client service feedback.
 *
 * Two rules layer here. First the permission, then the organization scope: an
 * Organizational Admin holding `service_feedback.view` still only sees feedback
 * recorded against their own organizations, enforced through the same
 * OrganizationScopeService the rest of the platform uses.
 *
 * Employees never gain rights over feedback about themselves through this
 * policy. Reviewing your own criticism is a conflict of interest, so it takes
 * an explicit permission that the employee role does not carry.
 */
class EmployeeServiceFeedbackPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly PublicFeedbackVisibility $visibility,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['service_feedback.view']);
    }

    public function view(User $user, EmployeeServiceFeedback $feedback): bool
    {
        return $user->hasAnyPermission([$feedback->type === ServiceFeedbackType::Complaint
            ? 'service_feedback.complaints.view' : 'service_feedback.view'])
            && $this->withinScope($user, $feedback);
    }

    public function review(User $user, EmployeeServiceFeedback $feedback): bool
    {
        return $user->hasAnyPermission([$feedback->type === ServiceFeedbackType::Complaint
            ? 'service_feedback.complaints.review' : 'service_feedback.review'])
            && $this->withinScope($user, $feedback);
    }

    /**
     * Workflow transitions (brief §48–51): acknowledging and reviewing take the
     * type's review permission, recording an outcome takes `resolve`, closing
     * takes `close`. Assigned, unrouted, pending and hidden are set by routing,
     * assignment and moderation — never granted here.
     */
    public function transition(User $user, EmployeeServiceFeedback $feedback, ServiceFeedbackStatus $to): bool
    {
        $permission = match ($to) {
            ServiceFeedbackStatus::Reviewed, ServiceFeedbackStatus::UnderReview => $this->reviewPermission($feedback),
            ServiceFeedbackStatus::Resolved, ServiceFeedbackStatus::Accepted, ServiceFeedbackStatus::NotAdopted => 'service_feedback.resolve',
            ServiceFeedbackStatus::Closed => 'service_feedback.close',
            default => null,
        };

        return $permission !== null
            && $user->hasAnyPermission([$this->viewPermission($feedback)])
            && $user->hasAnyPermission([$permission])
            && $this->withinScope($user, $feedback);
    }

    /** Naming investigation subjects on a complaint (§61) takes the complaint review grant. */
    public function manageSubjects(User $user, EmployeeServiceFeedback $feedback): bool
    {
        return $feedback->type === ServiceFeedbackType::Complaint
            && $user->hasAnyPermission(['service_feedback.complaints.review'])
            && $this->withinScope($user, $feedback);
    }

    /** Handing the submission to a handler; a first assignment or a reassignment (§53). */
    public function assign(User $user, EmployeeServiceFeedback $feedback): bool
    {
        return $user->hasAnyPermission([$this->viewPermission($feedback)])
            && $user->hasAnyPermission(['service_feedback.assign', 'service_feedback.reassign'])
            && $this->withinScope($user, $feedback);
    }

    private function viewPermission(EmployeeServiceFeedback $feedback): string
    {
        return $feedback->type === ServiceFeedbackType::Complaint ? 'service_feedback.complaints.view' : 'service_feedback.view';
    }

    private function reviewPermission(EmployeeServiceFeedback $feedback): string
    {
        return $feedback->type === ServiceFeedbackType::Complaint ? 'service_feedback.complaints.review' : 'service_feedback.review';
    }

    public function hide(User $user, EmployeeServiceFeedback $feedback): bool
    {
        return $feedback->type !== ServiceFeedbackType::Complaint
            && $user->hasAnyPermission(['service_feedback.hide'])
            && $this->withinScope($user, $feedback);
    }

    public function delete(User $user, EmployeeServiceFeedback $feedback): bool
    {
        return $feedback->type !== ServiceFeedbackType::Complaint
            && $user->hasAnyPermission(['service_feedback.delete'])
            && $this->withinScope($user, $feedback);
    }

    public function export(User $user): bool
    {
        return $user->hasAnyPermission(['service_feedback.export']);
    }

    /** Generating, revoking and printing employee feedback QR codes. */
    public function manageSettings(User $user): bool
    {
        return $user->hasAnyPermission(['service_feedback.settings.manage']);
    }

    /**
     * Feedback with no organization on file (the employee was unassigned when
     * it was submitted) is visible only to unrestricted roles — there is no
     * organization to scope it against, so a scoped admin must not inherit it.
     */
    private function withinScope(User $user, EmployeeServiceFeedback $feedback): bool
    {
        if ($user->employee_id !== null && $user->employee_id === $feedback->employee_id) {
            return false;
        }

        // Brief §95: employee-targeted and organization-targeted submissions are separate grants.
        if (! $this->visibility->canSeeTarget($user, $feedback->target_type)) {
            return false;
        }

        if ($this->scope->isUnrestricted($user)) {
            return true;
        }

        if ($feedback->organization_id === null) {
            return false;
        }

        return $this->scope->canAccessOrganization($user, $feedback->organization_id);
    }
}
