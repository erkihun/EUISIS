<?php

declare(strict_types=1);

namespace App\Services\ReportingLines;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\SupervisorDelegation;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Carbon;

/**
 * May this user exercise a supervisor action over this employee? (ADR 0025)
 *
 * Four things must all hold, and the permission is checked by the caller's
 * policy, not here:
 *   - the user is an employee (an account with no employee record supervises nobody);
 *   - the subject is not the user themself (no self-approval, by any route);
 *   - the subject's organization is one the user's scope reaches;
 *   - the user supervises the subject on the date — directly, indirectly for
 *     the few actions that allow it, or through a delegation in force from
 *     someone who supervises the subject directly on that date.
 * Delegated authority is checked against the delegator LIVE, so it can never
 * exceed what the delegator holds that day, and a delegate never decides for
 * the delegator's own record.
 */
class ReportingAuthority
{
    public function __construct(
        private readonly ReportingLineResolver $resolver,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function decide(User $actor, Employee $subject, string $action, ?EmployeeAssignment $placement = null, Carbon|string|null $asOf = null): AuthorityDecision
    {
        $actorEmployeeId = $actor->employee_id === null ? null : (string) $actor->employee_id;
        if ($actorEmployeeId === null) {
            return AuthorityDecision::denied('reporting-lines.authority.not_an_employee');
        }
        if ($actorEmployeeId === (string) $subject->getKey()) {
            return AuthorityDecision::denied('reporting-lines.authority.self');
        }
        if (! SupervisorActions::isKnown($action)) {
            return AuthorityDecision::denied('reporting-lines.authority.unknown_action');
        }

        $day = ReportingGraphRepository::day($asOf);
        $organizationId = $placement?->organization_id !== null ? (string) $placement->organization_id : $this->organizationOf($subject, $day);
        if ($organizationId !== null && ! $this->scope->canAccessOrganization($actor, $organizationId)) {
            return AuthorityDecision::denied('reporting-lines.authority.out_of_scope');
        }

        if ($this->resolver->supervisesEmployee($actorEmployeeId, $subject, $placement, $day, directOnly: true)) {
            return AuthorityDecision::direct();
        }
        if (SupervisorActions::allowsIndirect($action) && $this->resolver->supervisesEmployee($actorEmployeeId, $subject, $placement, $day)) {
            return AuthorityDecision::indirect();
        }

        $delegations = SupervisorDelegation::query()
            ->where('delegate_employee_id', $actorEmployeeId)
            ->inForceOn($day)
            ->orderBy('starts_on')
            ->get();
        foreach ($delegations as $delegation) {
            if (! $delegation->allows($action)) {
                continue;
            }
            $delegatorId = (string) $delegation->delegator_employee_id;
            if ($delegatorId === (string) $subject->getKey()) {
                continue; // nobody decides for the person who lent them the authority
            }
            if ($this->resolver->supervisesEmployee($delegatorId, $subject, $placement, $day, directOnly: true)) {
                return AuthorityDecision::delegated($delegatorId, (string) $delegation->getKey());
            }
        }

        return AuthorityDecision::denied('reporting-lines.authority.none');
    }

    public function may(User $actor, Employee $subject, string $action, ?EmployeeAssignment $placement = null, Carbon|string|null $asOf = null): bool
    {
        return $this->decide($actor, $subject, $action, $placement, $asOf)->granted;
    }

    private function organizationOf(Employee $subject, string $day): ?string
    {
        [$assignment] = $this->resolver->assignmentOn($subject, $day);
        $organizationId = $assignment?->organization_id;

        return $organizationId === null ? null : (string) $organizationId;
    }
}
