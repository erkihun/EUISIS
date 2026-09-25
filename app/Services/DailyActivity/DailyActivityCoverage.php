<?php

declare(strict_types=1);

namespace App\Services\DailyActivity;

use App\Models\EmployeeAssignment;
use Illuminate\Database\Eloquent\Builder;

/**
 * The set of employee work placements a user may see or act on.
 *
 * One object answers the question for both sides of the module: filtering
 * stored logs (by their snapshot organization / unit / employee) and deciding
 * whether a calculated day, via its assignment, belongs in a count. Using the
 * same definition for both is what keeps "missing" and "submitted" figures
 * over exactly the same population.
 */
final class DailyActivityCoverage
{
    /**
     * @param  array<int, string>  $organizationIds
     * @param  array<int, string>  $unitIds
     * @param  array<int, string>  $employeeIds
     */
    public function __construct(
        public readonly bool $all = false,
        public readonly array $organizationIds = [],
        public readonly array $unitIds = [],
        public readonly array $employeeIds = [],
        public readonly ?string $excludeEmployeeId = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return ! $this->all && $this->organizationIds === [] && $this->unitIds === [] && $this->employeeIds === [];
    }

    public function merge(self $other): self
    {
        return new self(
            all: $this->all || $other->all,
            organizationIds: array_values(array_unique([...$this->organizationIds, ...$other->organizationIds])),
            unitIds: array_values(array_unique([...$this->unitIds, ...$other->unitIds])),
            employeeIds: array_values(array_unique([...$this->employeeIds, ...$other->employeeIds])),
            // Only exclude one's own record when BOTH sides would: oversight
            // may legitimately include the viewer's own organization.
            excludeEmployeeId: $this->excludeEmployeeId !== null && $this->excludeEmployeeId === $other->excludeEmployeeId
                ? $this->excludeEmployeeId
                : null,
        );
    }

    public function matchesAssignment(EmployeeAssignment $assignment): bool
    {
        if ($this->excludeEmployeeId !== null && $assignment->employee_id === $this->excludeEmployeeId) {
            return false;
        }

        return $this->all
            || in_array($assignment->organization_id, $this->organizationIds, true)
            || ($assignment->organization_unit_id !== null && in_array($assignment->organization_unit_id, $this->unitIds, true))
            || in_array($assignment->employee_id, $this->employeeIds, true);
    }

    /**
     * Constrain any query whose table has organization_id,
     * organization_unit_id and employee_id columns (logs and assignments).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query): Builder
    {
        if ($this->excludeEmployeeId !== null) {
            $query->where($query->qualifyColumn('employee_id'), '!=', $this->excludeEmployeeId);
        }

        if ($this->all) {
            return $query;
        }

        if ($this->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $scoped) use ($query): void {
            $scoped->whereRaw('1 = 0');

            if ($this->organizationIds !== []) {
                $scoped->orWhereIn($query->qualifyColumn('organization_id'), $this->organizationIds);
            }
            if ($this->unitIds !== []) {
                $scoped->orWhereIn($query->qualifyColumn('organization_unit_id'), $this->unitIds);
            }
            if ($this->employeeIds !== []) {
                $scoped->orWhereIn($query->qualifyColumn('employee_id'), $this->employeeIds);
            }
        });
    }
}
