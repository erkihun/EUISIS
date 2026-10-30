<?php

declare(strict_types=1);

namespace Modules\DailyWork\Application\Services;

use Modules\DailyWork\Domain\Models\DailyActivityLog;
use Modules\DailyWork\Domain\Models\DailyActivityReviewerAssignment;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\DailyWork\Contracts\Data\DailyActivityCoverage;
use Modules\DailyWork\Contracts\DailyWorkReviewers;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use Illuminate\Support\Carbon;

/**
 * Who may review whose daily activity.
 *
 * Addis One records no supervisor on positions or units, so review authority is
 * never inferred from job titles ("Director", "Team Leader"). It comes only
 * from explicit reviewer assignments:
 *
 *   organization (unit null)    -> every employee logged under that organization
 *   unit (+ optional sub-units) -> employees logged under that unit subtree
 *   employee                    -> that one employee
 *
 * Matching uses the log's SNAPSHOT organization and unit, so authority
 * follows where the work was done, not where the employee sits today.
 * Nobody ever reviews their own log. Super Admin keeps global authority, per
 * the existing architecture.
 */
class DailyActivityReviewerResolver implements DailyWorkReviewers
{
    /** @var array<string, array<int, string>> */
    private array $subtreeCache = [];

    /** @var array<int|string, DailyActivityCoverage> */
    private array $coverageCache = [];

    public function __construct(private readonly OrganizationScopeService $scope) {}

    public function isGlobalReviewer(User $user): bool
    {
        return $user->hasRole('Super Admin');
    }

    public function canReview(User $user, DailyActivityLog $log): bool
    {
        if ($this->isOwnLog($user, $log)) {
            return false;
        }

        return $this->constrainReviewable(DailyActivityLog::query()->whereKey($log->getKey()), $user)->exists();
    }

    public function hasAnyAssignment(User $user): bool
    {
        return ! $this->coverage($user)->isEmpty();
    }

    /**
     * Restrict a log query to what this reviewer may act on. A user with no
     * assignment gets an impossible predicate, never "everything".
     *
     * @param  Builder<DailyActivityLog>  $query
     * @return Builder<DailyActivityLog>
     */
    public function constrainReviewable(Builder $query, User $user): Builder
    {
        return $this->coverage($user)->apply($query);
    }

    /** Everything this user's reviewer assignments cover, minus their own record. */
    public function coverage(User $user): DailyActivityCoverage
    {
        return $this->coverageCache[$user->getKey()] ??= $this->buildCoverage($user);
    }

    /**
     * May this actor create an assignment in this organization? Requires the
     * manage permission and organization scope; nobody may grant review
     * authority outside the organizations they administer.
     */
    public function canManageAssignmentsFor(User $actor, string $organizationId): bool
    {
        return $actor->can('daily_activities.manage_reviewers')
            && $this->scope->canAccessOrganization($actor, $organizationId);
    }

    public function isOwnLog(User $user, DailyActivityLog $log): bool
    {
        $employeeId = $user->employee?->id;

        return $employeeId !== null && $employeeId === $log->employee_id;
    }

    /** @return array<int, string> the unit and every unit beneath it */
    public function subtree(string $unitId): array
    {
        if (isset($this->subtreeCache[$unitId])) {
            return $this->subtreeCache[$unitId];
        }

        $organizationId = OrganizationUnit::query()->whereKey($unitId)->value('organization_id');
        $childrenByParent = OrganizationUnit::query()
            ->where('organization_id', $organizationId)
            ->get(['id', 'parent_unit_id'])
            ->groupBy('parent_unit_id');

        $result = [];
        $queue = [$unitId];

        while ($queue !== []) {
            $current = array_shift($queue);
            if (in_array($current, $result, true)) {
                continue;
            }
            $result[] = $current;
            foreach ($childrenByParent->get($current, collect()) as $child) {
                $queue[] = (string) $child->id;
            }
        }

        return $this->subtreeCache[$unitId] = $result;
    }

    private function buildCoverage(User $user): DailyActivityCoverage
    {
        $ownEmployeeId = $user->employee?->id;

        if ($this->isGlobalReviewer($user)) {
            return new DailyActivityCoverage(all: true, excludeEmployeeId: $ownEmployeeId);
        }

        /** @var Collection<int, DailyActivityReviewerAssignment> $assignments */
        $assignments = DailyActivityReviewerAssignment::query()
            ->where('reviewer_user_id', $user->getKey())
            ->effective()
            ->get();

        $employeeIds = [];
        $organizationIds = [];
        $unitIds = [];

        foreach ($assignments as $assignment) {
            if ($assignment->employee_id !== null) {
                $employeeIds[] = $assignment->employee_id;
            } elseif ($assignment->organization_unit_id === null) {
                $organizationIds[] = $assignment->organization_id;
            } else {
                $unitIds = [...$unitIds, ...($assignment->include_sub_units
                    ? $this->subtree($assignment->organization_unit_id)
                    : [$assignment->organization_unit_id])];
            }
        }

        return new DailyActivityCoverage(
            organizationIds: array_values(array_unique($organizationIds)),
            unitIds: array_values(array_unique($unitIds)),
            employeeIds: array_values(array_unique($employeeIds)),
            excludeEmployeeId: $ownEmployeeId,
        );
    }

    public function directManager(Employee $employee, ?EmployeeAssignment $assignment, Carbon $date): ?User
    {
        if ($assignment === null) {
            return null;
        }
        $day = $date->toDateString();
        $candidates = DailyActivityReviewerAssignment::query()
            ->where('is_active', true)
            ->where('organization_id', $assignment->organization_id)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $day))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->whereHas('reviewer', fn ($q) => $q->where('status', 'active'))
            ->get(['reviewer_user_id', 'organization_unit_id', 'include_sub_units', 'employee_id']);
        $selfUserIds = User::query()->where('employee_id', $employee->id)->pluck('id')->all();
        $candidates = $candidates->reject(fn ($c) => in_array($c->reviewer_user_id, $selfUserIds, true));

        // 1. Assigned to this employee.
        $direct = $candidates->where('employee_id', $employee->id)->pluck('reviewer_user_id')->unique();
        if ($direct->isNotEmpty()) {
            return $direct->count() === 1 ? User::query()->find($direct->first()) : null;
        }

        // 2. Nearest unit up the tree.
        $unitLevel = $candidates->whereNull('employee_id')->whereNotNull('organization_unit_id');
        $chain = $assignment->organization_unit_id ? [$assignment->organization_unit_id, ...$this->unitAncestors($assignment->organization_unit_id)] : [];
        foreach ($chain as $depth => $unitId) {
            $matches = $unitLevel->filter(fn ($c) => $c->organization_unit_id === $unitId && ($depth === 0 || $c->include_sub_units))->pluck('reviewer_user_id')->unique();
            if ($matches->isNotEmpty()) {
                return $matches->count() === 1 ? User::query()->find($matches->first()) : null;
            }
        }

        // 3. The whole organization.
        $orgLevel = $candidates->whereNull('employee_id')->whereNull('organization_unit_id')->pluck('reviewer_user_id')->unique();

        return $orgLevel->count() === 1 ? User::query()->find($orgLevel->first()) : null;
    }

    /** @return array<int, string> */
    private function unitAncestors(string $unitId): array
    {
        $ids = [];
        $current = OrganizationUnit::query()->whereKey($unitId)->value('parent_unit_id');
        for ($guard = 0; $current !== null && $guard < 50; $guard++) {
            $ids[] = $current;
            $current = OrganizationUnit::query()->whereKey($current)->value('parent_unit_id');
        }

        return $ids;
    }
}
