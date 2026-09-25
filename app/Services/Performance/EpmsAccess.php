<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Models\Employee;
use App\Models\EmployeePerformanceAgreement;
use App\Models\GrievanceCommittee;
use App\Models\User;
use App\Services\DailyActivity\DailyActivityReviewerResolver;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may do what in EPMS (docs/epms-permissions.md). Server-side only; the
 * frontend just mirrors it.
 *
 * Three independent sources of authority, never inferred from job titles:
 *
 *   own        the agreement's employee is the user's linked employee record
 *   manager    the agreement's manager, OR an explicit reviewer assignment
 *              covering its organization/unit/employee (the same assignments
 *              the Daily Activity module uses — EUISIS records no
 *              supervisor on positions)
 *   oversight  a permission exercised inside OrganizationScopeService scope
 *
 * Nobody manages their own agreement. Separation of duties: the preparer of
 * a plan/change cannot approve it unless settings allow it.
 */
final class EpmsAccess
{
    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly DailyActivityReviewerResolver $reviewers,
        private readonly EpmsSettings $settings,
    ) {}

    public function employeeOf(User $user): ?Employee
    {
        $employee = $user->employee;

        return $employee instanceof Employee ? $employee : null;
    }

    public function inScope(User $user, string $permission, ?string $organizationId): bool
    {
        return $organizationId !== null && $this->scope->canExercisePermission($user, $permission, $organizationId);
    }

    public function isOwn(User $user, EmployeePerformanceAgreement $agreement): bool
    {
        $employee = $this->employeeOf($user);

        return $employee !== null && $employee->getKey() === $agreement->employee_id;
    }

    /** Line-manager authority over this agreement (never over one's own). */
    public function isManagerOf(User $user, EmployeePerformanceAgreement $agreement): bool
    {
        if ($this->isOwn($user, $agreement)) {
            return false;
        }

        if ((int) $agreement->manager_user_id === (int) $user->getKey()) {
            return true;
        }

        return $this->reviewers->coverage($user)->apply(
            EmployeePerformanceAgreement::query()->whereKey($agreement->getKey())
        )->exists();
    }

    public function canManageAgreement(User $user, EmployeePerformanceAgreement $agreement): bool
    {
        if ($this->isOwn($user, $agreement) || ! $user->can('employee_performance_agreements.manage')) {
            return false;
        }

        return $this->isManagerOf($user, $agreement)
            || $this->inScope($user, 'employee_performance_agreements.manage', $agreement->organization_id)
                && $user->can('performance_plans.approve');
    }

    public function canViewAgreement(User $user, EmployeePerformanceAgreement $agreement): bool
    {
        if ($this->isOwn($user, $agreement)) {
            return $user->can('employee_performance_agreements.view_own');
        }

        return $this->isManagerOf($user, $agreement) && $user->can('employee_performance_agreements.manage')
            || $this->inScope($user, 'performance_reports.view', $agreement->organization_id);
    }

    /**
     * Agreements the user may see in lists: oversight scope, manager coverage
     * and agreements where they are the named manager. Never includes the
     * user's own agreement in management lists.
     *
     * @param  Builder<EmployeePerformanceAgreement>  $query
     * @return Builder<EmployeePerformanceAgreement>
     */
    public function constrainAgreements(Builder $query, User $user): Builder
    {
        $own = $this->employeeOf($user)?->getKey();
        $oversight = $user->can('performance_reports.view');
        $unrestricted = $oversight && $this->scope->isUnrestricted($user);

        if ($own !== null) {
            $query->where($query->qualifyColumn('employee_id'), '!=', $own);
        }

        if ($unrestricted) {
            return $query;
        }

        $coverage = $user->can('employee_performance_agreements.manage') ? $this->reviewers->coverage($user) : null;

        return $query->where(function (Builder $scoped) use ($user, $oversight, $coverage, $query): void {
            $scoped->where($query->qualifyColumn('manager_user_id'), $user->getKey());

            if ($oversight) {
                $scoped->orWhereIn($query->qualifyColumn('organization_id'), $this->scope->allowedOrganizationIds($user));
            }

            if ($coverage !== null && ! $coverage->isEmpty()) {
                $scoped->orWhere(fn (Builder $covered) => $coverage->apply($covered));
            }
        });
    }

    /** Membership of a (reused) committee through the user's employee record. */
    public function isCommitteeMember(User $user, ?GrievanceCommittee $committee): bool
    {
        $employee = $this->employeeOf($user);
        if ($committee === null || $employee === null) {
            return false;
        }

        return $committee->members()
            ->where('employee_id', $employee->getKey())
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString()))
            ->exists();
    }

    /**
     * Separation of duties: whoever prepared/requested may not also approve,
     * unless policy allows it or the actor is a Super Admin (audited by the caller).
     */
    public function assertSeparated(User $actor, ?int $preparerId, string $step): void
    {
        if ($preparerId === null || (int) $preparerId !== (int) $actor->getKey()) {
            return;
        }

        if ($this->settings->allowSelfApproval() || $actor->hasRole('Super Admin')) {
            return;
        }

        throw new AuthorizationException(__('performance.errors.separation_of_duties', ['step' => $step]));
    }

    public function authorize(bool $allowed): void
    {
        if (! $allowed) {
            throw new AuthorizationException(__('performance.errors.forbidden'));
        }
    }
}
