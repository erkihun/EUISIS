<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ServiceMonitoringSubmission;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

/**
 * Every report action needs both the permission and the report's institution
 * inside the user's organization scope.
 */
class ServiceMonitoringSubmissionPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private readonly OrganizationScopeService $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->canAny(['service_monitoring.reports.view', 'service_monitoring.reports.submit', 'service_monitoring.reports.review']);
    }

    /** Start a report: the institution itself is checked by the controller against the scope. */
    public function create(User $user): bool
    {
        return $user->can('service_monitoring.reports.submit');
    }

    public function view(User $user, ServiceMonitoringSubmission $submission): bool
    {
        foreach (['service_monitoring.reports.view', 'service_monitoring.reports.submit', 'service_monitoring.reports.review'] as $permission) {
            if ($this->scope->canExercisePermission($user, $permission, $submission->organization_id)) {
                return true;
            }
        }

        return false;
    }

    public function update(User $user, ServiceMonitoringSubmission $submission): bool
    {
        return $submission->status->isEditable()
            && $this->scope->canExercisePermission($user, 'service_monitoring.reports.submit', $submission->organization_id);
    }

    public function review(User $user, ServiceMonitoringSubmission $submission): bool
    {
        return $this->scope->canExercisePermission($user, 'service_monitoring.reports.review', $submission->organization_id);
    }

    public function viewSummary(User $user): bool
    {
        return $user->canAny(['service_monitoring.reports.view', 'service_monitoring.reports.review']);
    }
}
