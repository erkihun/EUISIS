<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\ReportingLines\ReportingLineResolver;

/**
 * Who may look at reporting lines (ADR 0025). Permission AND relationship:
 *   - one's own line needs no permission (My Portal is keyed to the employee);
 *   - a supervisor with reporting-lines.view_team sees the lines of people
 *     the structure places under them, directly or indirectly, nobody else;
 *   - reporting-lines.view_any sees any employee inside the organization scope,
 *     and the organization-level overview and integrity report.
 * Holding a head position without the permission shows nothing; holding the
 * permission without the relationship shows nothing.
 */
readonly class ReportingLinePolicy
{
    use DeniesNonAdminUsers;

    public function __construct(
        private OrganizationScopeService $scope,
        private ReportingLineResolver $resolver,
    ) {}

    /** The overview and diagnostics of an organization. */
    public function viewOrganization(User $user, Organization $organization): bool
    {
        return $user->can('reporting-lines.view_any')
            && $this->scope->canAccessOrganization($user, (string) $organization->getKey());
    }

    /** Any organization overview at all (the index page before an organization is chosen). */
    public function viewAny(User $user): bool
    {
        return $user->can('reporting-lines.view_any');
    }

    /** An employee's supervisor and chain. */
    public function viewLine(User $user, Employee $employee): bool
    {
        if ($this->isSelf($user, $employee)) {
            return true;
        }
        if ($user->can('reporting-lines.view_any') && $this->scope->canAccessEmployee($user, $employee)) {
            return true;
        }

        return $user->can('reporting-lines.view_team')
            && $user->employee_id !== null
            && $this->resolver->supervisesEmployee((string) $user->employee_id, $employee);
    }

    /** The people under an employee. Own team needs the team permission; anyone else's needs view_any in scope. */
    public function viewTeam(User $user, Employee $employee): bool
    {
        if ($this->isSelf($user, $employee)) {
            return $user->can('reporting-lines.view_team');
        }

        return $user->can('reporting-lines.view_any') && $this->scope->canAccessEmployee($user, $employee);
    }

    private function isSelf(User $user, Employee $employee): bool
    {
        return $user->employee_id !== null && (string) $user->employee_id === (string) $employee->getKey();
    }
}
