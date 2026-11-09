<?php

declare(strict_types=1);

namespace App\Services\ReportingLines;

use App\Backbone\Contracts\ReportingLines;
use App\Backbone\Data\SupervisorResolution;
use App\Enums\AssignmentStatus;
use App\Enums\OccupantBasis;
use App\Enums\ReportingOutcome;
use App\Models\ActingAppointment;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Position;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The one place that answers who supervises whom (ADR 0025).
 *
 * A supervisor is a PERSON holding the supervising POSITION on the date: the
 * position comes from {@see PositionReportingGraph} (explicit profile line,
 * else unit leadership), the person from {@see OccupantResolver} (an acting
 * appointment in force outranks the substantive holder). Nothing is guessed:
 * no assignment, several assignments, no head, a vacant head, two holders, a
 * cycle, each comes back as its own outcome.
 */
class ReportingLineResolver implements ReportingLines
{
    /** Steps up the chain before giving up: deeper than any real organogram. */
    public const MAX_DEPTH = 24;

    /** Supervised positions gathered for an indirect-reports query before the result is marked truncated. */
    public const MAX_REPORT_POSITIONS = 5000;

    public function __construct(
        private readonly ReportingGraphRepository $graphs,
        private readonly OccupantResolver $occupants,
    ) {}

    // ── Direct supervisor ────────────────────────────────────────────────

    public function directSupervisor(string $employeeUuid, ?string $asOf = null): SupervisorResolution
    {
        $employee = Employee::query()->find($employeeUuid);
        if ($employee === null) {
            return new SupervisorResolution(ReportingOutcome::NoAssignment, $employeeUuid, ReportingGraphRepository::day($asOf), explanation: 'reporting-lines.explanations.employee_missing');
        }

        return $this->directSupervisorOf($employee, null, $asOf);
    }

    /**
     * The supervisor of an employee, from a given placement when the caller
     * holds one (a request's snapshot placement), else from the assignment in
     * force on the date.
     */
    public function directSupervisorOf(Employee $employee, ?EmployeeAssignment $placement = null, Carbon|string|null $asOf = null): SupervisorResolution
    {
        $day = ReportingGraphRepository::day($asOf);
        $employeeId = (string) $employee->getKey();

        if ($placement === null) {
            [$placement, $failure] = $this->assignmentOn($employee, $day);
            if ($failure !== null) {
                return $failure;
            }
        }

        $organizationId = $placement->organization_id === null ? null : (string) $placement->organization_id;
        $positionId = $placement->position_id === null ? null : (string) $placement->position_id;
        $unitId = $placement->organization_unit_id === null ? null : (string) $placement->organization_unit_id;
        $base = [
            'employeeUuid' => $employeeId,
            'asOf' => $day,
            'assignmentUuid' => $placement->getKey() === null ? null : (string) $placement->getKey(),
            'subjectPositionUuid' => $positionId,
            'subjectUnitUuid' => $unitId,
            'organizationUuid' => $organizationId,
        ];

        if ($organizationId === null || $positionId === null) {
            return self::make(ReportingOutcome::NoPosition, $base, ['explanation' => 'reporting-lines.explanations.no_position']);
        }

        $graph = $this->graphs->forOrganization($organizationId, $day);
        $link = $graph->supervisorOf($positionId);

        return $this->resolvePerson($graph, $link, $employeeId, $day, $base, [$positionId]);
    }

    // ── Chain ────────────────────────────────────────────────────────────

    /** @return list<SupervisorResolution> */
    public function chain(string $employeeUuid, ?string $asOf = null, int $maxDepth = self::MAX_DEPTH): array
    {
        $employee = Employee::query()->find($employeeUuid);
        if ($employee === null) {
            return [$this->directSupervisor($employeeUuid, $asOf)];
        }

        return $this->chainOf($employee, null, $asOf, $maxDepth);
    }

    /**
     * Supervisor, supervisor's supervisor, … until the top, a problem, or the
     * depth limit. Each step is resolved from the previous supervisor's
     * position, so a vacant or ambiguous step ends the chain visibly rather
     * than skipping to the next level.
     *
     * @return list<SupervisorResolution>
     */
    public function chainOf(Employee $employee, ?EmployeeAssignment $placement = null, Carbon|string|null $asOf = null, int $maxDepth = self::MAX_DEPTH): array
    {
        $day = ReportingGraphRepository::day($asOf);
        $steps = [];
        $first = $this->directSupervisorOf($employee, $placement, $day);
        $steps[] = $first;
        $visited = array_filter([$first->subjectPositionUuid, $first->supervisorPositionUuid]);
        $current = $first;

        while ($current->isResolved() && count($steps) < $maxDepth) {
            $graph = $this->graphs->forOrganization((string) $current->supervisorOrganizationUuid, $day);
            $positionId = (string) $current->supervisorPositionUuid;
            $link = $graph->supervisorOf($positionId);
            $base = [
                'employeeUuid' => (string) $current->supervisorEmployeeUuid,
                'asOf' => $day,
                'assignmentUuid' => null,
                'subjectPositionUuid' => $positionId,
                'subjectUnitUuid' => $graph->position($positionId)?->unitId,
                'organizationUuid' => $graph->organizationId,
            ];
            if ($link->isResolved() && in_array($link->supervisorPositionId, $visited, true)) {
                $steps[] = self::make(ReportingOutcome::Cycle, $base, [
                    'basis' => $link->basis, 'supervisorPositionUuid' => $link->supervisorPositionId,
                    'explanation' => 'reporting-lines.explanations.cycle', 'explanationParams' => ['position' => $graph->position($positionId)?->titleEn],
                    'path' => array_values($visited),
                ]);
                break;
            }
            $next = $this->resolvePerson($graph, $link, (string) $current->supervisorEmployeeUuid, $day, $base, array_values($visited));
            $steps[] = $next;
            if ($next->supervisorPositionUuid !== null) {
                $visited[] = $next->supervisorPositionUuid;
            }
            $current = $next;
        }

        if ($current->isResolved() && count($steps) >= $maxDepth) {
            $steps[] = self::make(ReportingOutcome::Cycle, [
                'employeeUuid' => (string) $current->supervisorEmployeeUuid, 'asOf' => $day,
                'organizationUuid' => $current->supervisorOrganizationUuid, 'subjectPositionUuid' => $current->supervisorPositionUuid,
                'explanation' => 'reporting-lines.explanations.depth_exceeded', 'explanationParams' => ['depth' => $maxDepth],
                'path' => array_values($visited),
            ]);
        }

        return $steps;
    }

    // ── Reports ──────────────────────────────────────────────────────────

    /**
     * Positions the employee supervises directly on the day, through every
     * position they hold (substantively or acting), as organization id =>
     * position ids. An organization head also supervises the heads of the
     * child organizations in the published hierarchy.
     *
     * @return array<string, list<string>>
     */
    public function directReportPositions(Employee $employee, Carbon|string|null $asOf = null): array
    {
        $day = ReportingGraphRepository::day($asOf);
        $held = $this->occupants->positionsHeldBy((string) $employee->getKey(), $day);
        $positions = array_values(array_unique([...$held['substantive'], ...$held['acting']]));

        $byOrganization = [];
        foreach ($positions as $positionId) {
            $organizationId = $this->organizationOfPosition($positionId);
            if ($organizationId === null) {
                continue;
            }
            $this->collectSubordinates($organizationId, $positionId, $day, $byOrganization);
        }

        return $byOrganization;
    }

    /**
     * Positions under the employee at any depth, bounded by MAX_DEPTH levels
     * and MAX_REPORT_POSITIONS positions (then `truncated` is true).
     *
     * @return array{positions: array<string, list<string>>, truncated: bool}
     */
    public function allReportPositions(Employee $employee, Carbon|string|null $asOf = null): array
    {
        $day = ReportingGraphRepository::day($asOf);
        $frontier = $this->directReportPositions($employee, $day);
        $all = [];
        $seen = [];
        $truncated = false;
        $depth = 0;
        while ($frontier !== [] && $depth < self::MAX_DEPTH) {
            $next = [];
            foreach ($frontier as $organizationId => $positionIds) {
                foreach ($positionIds as $positionId) {
                    if (isset($seen[$positionId])) {
                        continue;
                    }
                    $seen[$positionId] = true;
                    $all[$organizationId][] = $positionId;
                    if (count($seen) >= self::MAX_REPORT_POSITIONS) {
                        $truncated = true;
                        break 3;
                    }
                    $this->collectSubordinates((string) $organizationId, $positionId, $day, $next);
                }
            }
            $frontier = $next;
            $depth++;
        }
        if ($frontier !== [] && $depth >= self::MAX_DEPTH) {
            $truncated = true;
        }

        return ['positions' => $all, 'truncated' => $truncated];
    }

    /**
     * Employees holding any of the given positions on the day (substantively
     * or acting), never the supervisor themself. A query, so callers paginate.
     *
     * @param  array<string, list<string>>  $positionsByOrganization
     * @return Builder<Employee>
     */
    public function employeesHolding(array $positionsByOrganization, string $exceptEmployeeId, Carbon|string|null $asOf = null): Builder
    {
        $day = ReportingGraphRepository::day($asOf);
        $positionIds = array_values(array_unique(array_merge([], ...array_values($positionsByOrganization))));

        $query = Employee::query()->whereKeyNot($exceptEmployeeId);
        if ($positionIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($positionIds, $day): void {
            $q->whereIn('id', EmployeeAssignment::query()->select('employee_id')
                ->whereIn('position_id', $positionIds)
                ->where('assignment_status', '!=', AssignmentStatus::PendingTransfer->value)
                ->whereDate('effective_from', '<=', $day)
                ->where(fn (Builder $w) => $w->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day)))
                ->orWhereIn('id', ActingAppointment::query()->select('employee_id')->whereIn('position_id', $positionIds)->inForceOn($day));
        });
    }

    /** @return Builder<Employee> */
    public function directReportsQuery(Employee $employee, Carbon|string|null $asOf = null): Builder
    {
        return $this->employeesHolding($this->directReportPositions($employee, $asOf), (string) $employee->getKey(), $asOf);
    }

    /** @return array{query: Builder<Employee>, truncated: bool} */
    public function allReportsQuery(Employee $employee, Carbon|string|null $asOf = null): array
    {
        $all = $this->allReportPositions($employee, $asOf);

        return ['query' => $this->employeesHolding($all['positions'], (string) $employee->getKey(), $asOf), 'truncated' => $all['truncated']];
    }

    /** @return list<string> */
    public function directReportIds(string $employeeUuid, ?string $asOf = null): array
    {
        $employee = Employee::query()->find($employeeUuid);
        if ($employee === null) {
            return [];
        }

        return $this->directReportsQuery($employee, $asOf)->pluck('id')->map(fn ($id): string => (string) $id)->all();
    }

    // ── Supervises? ──────────────────────────────────────────────────────

    public function supervises(string $supervisorUuid, string $employeeUuid, ?string $asOf = null, bool $directOnly = false): bool
    {
        if ($supervisorUuid === $employeeUuid) {
            return false;
        }
        $employee = Employee::query()->find($employeeUuid);
        if ($employee === null) {
            return false;
        }

        return $this->supervisesEmployee($supervisorUuid, $employee, null, $asOf, $directOnly);
    }

    /**
     * Direct: the resolved supervisor is the person. Indirect: any resolved
     * step of the chain is the person. The chain stops at the first
     * unresolved step, so authority never jumps over a vacancy.
     */
    public function supervisesEmployee(string $supervisorEmployeeId, Employee $employee, ?EmployeeAssignment $placement = null, Carbon|string|null $asOf = null, bool $directOnly = false): bool
    {
        if ($supervisorEmployeeId === (string) $employee->getKey()) {
            return false;
        }
        if ($directOnly) {
            return $this->directSupervisorOf($employee, $placement, $asOf)->supervises($supervisorEmployeeId);
        }
        foreach ($this->chainOf($employee, $placement, $asOf) as $step) {
            if ($step->supervises($supervisorEmployeeId)) {
                return true;
            }
            if (! $step->isResolved()) {
                return false;
            }
        }

        return false;
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * The assignment that places the employee on the day: the one row
     * covering it, else the one row among several that is marked current.
     * Anything else is ambiguous and said so.
     *
     * @return array{0: ?EmployeeAssignment, 1: ?SupervisorResolution}
     */
    public function assignmentOn(Employee $employee, string $day): array
    {
        $covering = EmployeeAssignment::query()
            ->where('employee_id', $employee->getKey())
            ->where('assignment_status', '!=', AssignmentStatus::PendingTransfer->value)
            ->whereDate('effective_from', '<=', $day)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->orderByDesc('is_current')
            ->orderByDesc('effective_from')
            ->get();

        $employeeId = (string) $employee->getKey();
        if ($covering->isEmpty()) {
            return [null, new SupervisorResolution(ReportingOutcome::NoAssignment, $employeeId, $day, explanation: 'reporting-lines.explanations.no_assignment')];
        }
        if ($covering->count() === 1) {
            return [$covering->first(), null];
        }
        $current = $covering->where('is_current', true);
        if ($current->count() === 1) {
            return [$current->first(), null];
        }

        return [null, new SupervisorResolution(ReportingOutcome::AmbiguousAssignment, $employeeId, $day, explanation: 'reporting-lines.explanations.ambiguous_assignment', explanationParams: ['count' => $covering->count()])];
    }

    /**
     * Turn a position link into a person: acting appointment first, then the
     * substantive holder; none is a vacancy, several are ambiguous, the
     * subject themself is a self-report.
     *
     * @param  array<string, mixed>  $base
     * @param  list<string>  $path
     */
    private function resolvePerson(PositionReportingGraph $graph, PositionLink $link, string $subjectEmployeeId, string $day, array $base, array $path): SupervisorResolution
    {
        $common = ['basis' => $link->basis, 'explanation' => $link->explanation, 'explanationParams' => $link->explanationParams, 'path' => $path];

        if (! $link->isResolved()) {
            return self::make($link->outcome, $base, $common, ['supervisorPositionUuid' => $link->supervisorPositionId]);
        }

        $positionId = (string) $link->supervisorPositionId;
        // The parent organization's head lives in that organization's graph.
        $positionGraph = $graph->position($positionId) !== null ? $graph : $this->graphs->forOrganization($graph->parentOrganization()->organizationId ?? $graph->organizationId, $day);
        $position = $positionGraph->position($positionId);
        $unit = $position?->unitId !== null ? $positionGraph->unit($position->unitId) : null;
        $positionFields = [
            'supervisorPositionUuid' => $positionId,
            'supervisorPositionTitleEn' => $position?->titleEn,
            'supervisorPositionTitleAm' => $position?->titleAm,
            'supervisorUnitUuid' => $unit?->id,
            'supervisorUnitNameEn' => $unit?->nameEn,
            'supervisorUnitNameAm' => $unit?->nameAm,
            'supervisorOrganizationUuid' => $position?->organizationId ?? $positionGraph->organizationId,
        ];

        $occupants = $this->occupants->occupantsOf([$positionId], $day)[$positionId] ?? [];
        $acting = array_values(array_filter($occupants, fn (Occupant $o): bool => $o->basis === OccupantBasis::Acting && $o->employeeActive));
        $substantive = array_values(array_filter($occupants, fn (Occupant $o): bool => $o->basis === OccupantBasis::Substantive && $o->employeeActive));

        $title = (string) $position?->titleEn;
        if (count($acting) > 1) {
            return self::make(ReportingOutcome::AmbiguousOccupants, $base, $common, $positionFields, ['explanation' => 'reporting-lines.explanations.acting_overlap', 'explanationParams' => ['position' => $title, 'count' => count($acting)]]);
        }
        if (count($acting) === 1) {
            $holder = $acting[0];
        } elseif (count($substantive) === 1) {
            $holder = $substantive[0];
        } elseif (count($substantive) > 1) {
            return self::make(ReportingOutcome::AmbiguousOccupants, $base, $common, $positionFields, ['explanation' => 'reporting-lines.explanations.several_holders', 'explanationParams' => ['position' => $title, 'count' => count($substantive)]]);
        } else {
            $explanation = count($occupants) > 0 ? 'reporting-lines.explanations.holder_inactive' : 'reporting-lines.explanations.vacant';

            return self::make(ReportingOutcome::HeadVacant, $base, $common, $positionFields, ['explanation' => $explanation, 'explanationParams' => ['position' => $title]]);
        }

        if ($holder->employeeId === $subjectEmployeeId) {
            return self::make(ReportingOutcome::SelfReporting, $base, $common, $positionFields, ['explanation' => 'reporting-lines.explanations.self_holder', 'explanationParams' => ['position' => $title]]);
        }

        return self::make(ReportingOutcome::Resolved, $base, $common, $positionFields, [
            'occupantBasis' => $holder->basis,
            'supervisorEmployeeUuid' => $holder->employeeId,
            'supervisorEmployeeNumber' => $holder->employeeNumber,
            'supervisorName' => $holder->name,
            'supervisorNameAm' => $holder->nameAm,
            'actingAppointmentUuid' => $holder->actingAppointmentId,
        ]);
    }

    /**
     * Build a resolution from argument groups; later groups override earlier
     * ones, so a rule can refine the link's explanation.
     *
     * @param  array<string, mixed>  ...$parts
     */
    private static function make(ReportingOutcome $outcome, array ...$parts): SupervisorResolution
    {
        $args = array_merge(...$parts);
        unset($args['outcome']);

        return new SupervisorResolution($outcome, ...$args);
    }

    /** @param array<string, list<string>> $into */
    private function collectSubordinates(string $organizationId, string $positionId, string $day, array &$into): void
    {
        $graph = $this->graphs->forOrganization($organizationId, $day);
        foreach ($graph->subordinatesOf($positionId) as $subordinate) {
            $into[$organizationId][] = $subordinate;
        }
        $node = $graph->position($positionId);
        if ($node !== null && $node->isOrganizationHead()) {
            foreach ($this->graphs->childOrganizationIds($organizationId, $day) as $childId) {
                $childGraph = $this->graphs->forOrganization($childId, $day);
                [$head, $several] = $childGraph->organizationHead();
                // The child's head reports here only when its own link says so (one head, no explicit line elsewhere).
                if ($head !== null && ! $several && $childGraph->supervisorOf($head->id)->supervisorPositionId === $positionId) {
                    $into[$childId][] = $head->id;
                }
            }
        }
        foreach ($into as $org => $ids) {
            $into[$org] = array_values(array_unique($ids));
        }
    }

    private function organizationOfPosition(string $positionId): ?string
    {
        $value = Position::query()->whereKey($positionId)->value('organization_id');

        return $value === null ? null : (string) $value;
    }
}
