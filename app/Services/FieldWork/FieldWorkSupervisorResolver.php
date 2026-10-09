<?php

declare(strict_types=1);

namespace App\Services\FieldWork;

use App\Models\DailyActivityReviewerAssignment;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\FieldWorkRequest;
use App\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves the configured supervisor authority; it intentionally never
 * treats a role named “Manager” as a reporting relationship. EUISIS does not
 * yet persist position/unit supervisors, so the existing explicit reviewer
 * assignments are the authoritative, auditable source available today.
 */
final class FieldWorkSupervisorResolver
{
    /** @var array<string, array<int, string>> */
    private array $descendantCache = [];

    /** @var array<string, Collection<int, OrganizationUnit>> */
    private array $unitCache = [];

    public function resolve(Employee $employee, EmployeeAssignment $assignment): ?User
    {
        $unitId = $assignment->organization_unit_id;
        $ancestorUnitIds = $unitId !== null ? $this->ancestorUnitIds($unitId) : [];

        $rows = DailyActivityReviewerAssignment::query()->effective()
            ->with('reviewer.employee')
            ->where('organization_id', $assignment->organization_id)
            ->where(function (Builder $authority) use ($employee, $unitId, $ancestorUnitIds): void {
                $authority->where('employee_id', $employee->id)
                    ->orWhere(function (Builder $coverage) use ($unitId, $ancestorUnitIds): void {
                        $coverage->whereNull('employee_id')
                            ->where(function (Builder $units) use ($unitId, $ancestorUnitIds): void {
                                $units->whereNull('organization_unit_id');

                                if ($unitId !== null) {
                                    $units->orWhere('organization_unit_id', $unitId)
                                        ->orWhere(function (Builder $descendantCoverage) use ($ancestorUnitIds): void {
                                            $descendantCoverage->where('include_sub_units', true)
                                                ->whereIn('organization_unit_id', $ancestorUnitIds);
                                        });
                                }
                            });
                    });
            })
            ->get()
            ->filter(fn (DailyActivityReviewerAssignment $row): bool => $this->covers($row, $employee, $assignment))
            ->filter(fn (DailyActivityReviewerAssignment $row): bool => $row->reviewer?->isActive() === true && $row->reviewer?->employee?->id !== $employee->id)
            ->sortBy(fn (DailyActivityReviewerAssignment $row): string => ($row->employee_id === $employee->id ? '0' : ($row->organization_unit_id === $assignment->organization_unit_id ? '1' : ($row->organization_unit_id ? '2' : '3'))).$row->created_at?->format('YmdHis'));

        return $rows->first()?->reviewer;
    }

    public function canApprove(User $actor, Employee $requester, EmployeeAssignment $assignment): bool
    {
        $supervisor = $this->resolve($requester, $assignment);

        return $supervisor !== null && (int) $supervisor->id === (int) $actor->id;
    }

    /**
     * Apply the same effective reviewer authority used by resolve() directly
     * to a FieldWorkRequest query. This keeps approval queues bounded in SQL.
     *
     * @param  Builder<FieldWorkRequest>  $query
     * @return Builder<FieldWorkRequest>
     */
    public function applyApprovalScope(Builder $query, User $actor): Builder
    {
        $assignments = DailyActivityReviewerAssignment::query()
            ->effective()
            ->where('reviewer_user_id', $actor->id)
            ->get(['organization_id', 'organization_unit_id', 'include_sub_units', 'employee_id']);

        if ($assignments->isEmpty() || ! $actor->isActive()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $authority) use ($assignments): void {
            foreach ($assignments as $assignment) {
                $authority->orWhere(function (Builder $covered) use ($assignment): void {
                    $covered->where('organization_id', $assignment->organization_id);

                    if ($assignment->employee_id !== null) {
                        $covered->where('requester_employee_id', $assignment->employee_id);

                        return;
                    }

                    if ($assignment->organization_unit_id !== null) {
                        $unitIds = $assignment->include_sub_units
                            ? $this->descendantUnitIds($assignment->organization_id, $assignment->organization_unit_id)
                            : [$assignment->organization_unit_id];
                        $covered->whereIn('organization_unit_id', $unitIds);
                    }
                });
            }
        });
    }

    private function covers(DailyActivityReviewerAssignment $row, Employee $employee, EmployeeAssignment $assignment): bool
    {
        if ($row->employee_id !== null) {
            return $row->employee_id === $employee->id;
        }

        if ($row->organization_unit_id === null) {
            return true;
        }

        if ($assignment->organization_unit_id === null) {
            return false;
        }

        if ($row->organization_unit_id === $assignment->organization_unit_id) {
            return true;
        }

        return $row->include_sub_units
            && in_array($row->organization_unit_id, $this->ancestorUnitIds($assignment->organization_unit_id), true);
    }

    /** @return array<int, string> Unit and parents; parent reviewers may cover a child unit. */
    private function ancestorUnitIds(string $unitId): array
    {
        $organizationId = OrganizationUnit::query()->whereKey($unitId)->value('organization_id');
        if ($organizationId === null) {
            return [];
        }

        $all = $this->unitsForOrganization($organizationId)->keyBy('id');
        $ids = [];
        $current = $unitId;
        while ($current !== null && isset($all[$current]) && ! in_array($current, $ids, true)) {
            $ids[] = $current;
            $current = $all[$current]->parent_unit_id;
        }

        return $ids;
    }

    /** @return array<int, string> */
    private function descendantUnitIds(string $organizationId, string $rootUnitId): array
    {
        $key = $organizationId.':'.$rootUnitId;
        if (isset($this->descendantCache[$key])) {
            return $this->descendantCache[$key];
        }

        $units = $this->unitsForOrganization($organizationId);
        $ids = [$rootUnitId];

        do {
            $before = count($ids);
            foreach ($units as $unit) {
                if (in_array($unit->parent_unit_id, $ids, true) && ! in_array($unit->id, $ids, true)) {
                    $ids[] = $unit->id;
                }
            }
        } while (count($ids) > $before);

        return $this->descendantCache[$key] = $ids;
    }

    /** @return Collection<int, OrganizationUnit> */
    private function unitsForOrganization(string $organizationId): Collection
    {
        return $this->unitCache[$organizationId] ??= OrganizationUnit::query()
            ->where('organization_id', $organizationId)
            ->get(['id', 'parent_unit_id']);
    }
}
