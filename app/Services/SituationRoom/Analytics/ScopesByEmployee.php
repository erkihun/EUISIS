<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Enums\AssignmentStatus;
use App\Services\SituationRoom\SituationContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * Rows that belong to an employee rather than to an organization (an ID card,
 * an entitlement, a correction request) are placed where the employee is
 * placed: their current, active assignment. Everything is confined to the
 * viewer's scope through that assignment, never counted first and filtered
 * afterwards.
 */
trait ScopesByEmployee
{
    /** The employees with a current, active assignment in scope, as a subquery of ids. */
    protected function employeesInScope(SituationContext $context): Builder
    {
        return WorkforceQueries::assignments($context)->select('ea.employee_id');
    }

    /** Joins a row's employee to their current assignment (alias `ea`), scoped, so the row can be rolled up by organization. */
    protected function joinAssignment(Builder $query, string $employeeColumn, SituationContext $context): Builder
    {
        $query->join('employee_assignments as ea', function (JoinClause $join) use ($employeeColumn): void {
            $join->on('ea.employee_id', '=', $employeeColumn)
                ->where('ea.is_current', true)
                ->where('ea.assignment_status', AssignmentStatus::Active->value);
        });
        $context->scope->apply($query, 'ea.organization_id');

        return $query;
    }

    /**
     * A grouped sum, keyed by the group column.
     *
     * @return array<string, float>
     */
    protected function sumBy(Builder $query, string $column, string $sum): array
    {
        return (clone $query)
            ->selectRaw("{$column} AS bucket, SUM({$sum}) AS total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->mapWithKeys(fn ($total, $bucket): array => [(string) $bucket => (float) $total])
            ->all();
    }
}
