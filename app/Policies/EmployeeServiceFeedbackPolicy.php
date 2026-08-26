<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EmployeeServiceFeedback;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

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

    public function __construct(private readonly OrganizationScopeService $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['service_feedback.view']);
    }

    public function view(User $user, EmployeeServiceFeedback $feedback): bool
    {
        return $user->hasAnyPermission(['service_feedback.view'])
            && $this->withinScope($user, $feedback);
    }

    public function review(User $user, EmployeeServiceFeedback $feedback): bool
    {
        return $user->hasAnyPermission(['service_feedback.review'])
            && $this->withinScope($user, $feedback);
    }

    public function hide(User $user, EmployeeServiceFeedback $feedback): bool
    {
        return $user->hasAnyPermission(['service_feedback.hide'])
            && $this->withinScope($user, $feedback);
    }

    public function delete(User $user, EmployeeServiceFeedback $feedback): bool
    {
        return $user->hasAnyPermission(['service_feedback.delete'])
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
        if ($this->scope->isUnrestricted($user)) {
            return true;
        }

        if ($feedback->organization_id === null) {
            return false;
        }

        return $this->scope->canAccessOrganization($user, $feedback->organization_id);
    }
}
