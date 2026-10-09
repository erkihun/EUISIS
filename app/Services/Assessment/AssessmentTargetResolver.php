<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Enums\Assessment\FormVersionStatus;
use App\Enums\Assessment\TargetType;
use App\Models\AssessmentFormVersion;
use App\Models\AssessmentTargetRule;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Services\DailyActivity\EmployeeWorkContextResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which published form version applies to an employee?
 *
 * Decided on the server, from the assignment valid on the reference date
 * (never the employee's current placement, never the employee's choice) and
 * the versions' target rules:
 *
 *  - a version applies when at least one INCLUDE rule matches and no
 *    EXCLUDE rule does;
 *  - among applicable versions of one assessment type, the highest rule
 *    priority wins; a tie is a CONFLICT and nothing is chosen;
 *  - no applicable version is NO_APPLICABLE_FORM, never a guess.
 */
class AssessmentTargetResolver
{
    public const MATCHED = 'matched';

    public const NO_APPLICABLE_FORM = 'no_applicable_form';

    public const CONFLICT = 'conflict';

    public const NOT_ASSIGNED = 'not_assigned';

    /** @var array<string, ?string> unit id => parent id */
    private array $parents = [];

    public function __construct(private readonly EmployeeWorkContextResolver $context) {}

    /**
     * Published versions of a type in force on a date, of forms not archived.
     *
     * @return Collection<int, AssessmentFormVersion>
     */
    public function candidates(string $assessmentTypeId, Carbon $date): Collection
    {
        $day = $date->toDateString();

        return AssessmentFormVersion::query()
            ->where('status', FormVersionStatus::Published->value)
            ->whereHas('form', fn ($query) => $query->where('assessment_type_id', $assessmentTypeId)->where('status', 'active'))
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $day))
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->with(['targetRules', 'form:id,code,name_en,name_am,assessment_type_id,organization_id'])
            ->get();
    }

    /**
     * @param  Collection<int, AssessmentFormVersion>|null  $versions  candidates; resolved for the type when null
     * @return array{status: string, version: ?AssessmentFormVersion, candidates: array<int, string>, assignment: ?EmployeeAssignment}
     */
    public function resolveFor(Employee $employee, Carbon $date, string $assessmentTypeId, ?Collection $versions = null): array
    {
        $versions ??= $this->candidates($assessmentTypeId, $date);
        $assignment = $this->context->assignmentOn($employee, $date);

        if ($assignment === null) {
            return ['status' => self::NOT_ASSIGNED, 'version' => null, 'candidates' => [], 'assignment' => null];
        }

        $position = $assignment->position_id ? Position::query()->find($assignment->position_id, ['id', 'occupation_id', 'grade_level', 'job_family']) : null;

        return [...$this->decide($versions, $assignment, $position, $date->toDateString()), 'assignment' => $assignment];
    }

    /**
     * Who would receive which form: counts per version, unmatched and
     * conflicting employees, over every employee with an assignment on the
     * date, in chunks.
     *
     * @param  array<int, string>|null  $organizationIds  limit to these organizations (scope); null for all
     * @return array{total: int, by_version: array<string, int>, unmatched: int, conflicts: int, conflict_samples: array<int, array{employee_id: string, versions: array<int, string>}>}
     */
    public function preview(string $assessmentTypeId, Carbon $date, ?array $organizationIds = null, int $chunk = 1000): array
    {
        $versions = $this->candidates($assessmentTypeId, $date);
        if ($organizationIds !== null) {
            $versions = $versions->filter(fn (AssessmentFormVersion $version): bool =>
                $version->form->organization_id === null
                || in_array($version->form->organization_id, $organizationIds, true));
        }
        $result = ['total' => 0, 'by_version' => $versions->mapWithKeys(fn (AssessmentFormVersion $v): array => [$v->id => 0])->all(), 'unmatched' => 0, 'conflicts' => 0, 'conflict_samples' => []];
        $day = $date->toDateString();

        Employee::query()->select('id')->orderBy('id')->chunk($chunk, function ($employees) use (&$result, $versions, $date, $day, $organizationIds): void {
            $assignments = $this->context->assignmentsFor($employees->pluck('id')->all(), $date, $date);
            $picked = $employees->mapWithKeys(fn ($employee): array => [$employee->id => $this->context->pickAssignment($assignments->get($employee->id, collect()), $day)])->filter();
            if ($organizationIds !== null) {
                $picked = $picked->filter(fn (EmployeeAssignment $assignment): bool => in_array($assignment->organization_id, $organizationIds, true));
            }
            $positions = Position::query()->whereIn('id', $picked->pluck('position_id')->filter()->unique())->get(['id', 'occupation_id', 'grade_level', 'job_family'])->keyBy('id');

            foreach ($picked as $employeeId => $assignment) {
                $result['total']++;
                $decision = $this->decide($versions, $assignment, $positions->get($assignment->position_id), $day);
                if ($decision['status'] === self::MATCHED) {
                    $result['by_version'][$decision['version']->id]++;
                } elseif ($decision['status'] === self::CONFLICT) {
                    $result['conflicts']++;
                    if (count($result['conflict_samples']) < 20) {
                        $result['conflict_samples'][] = ['employee_id' => (string) $employeeId, 'versions' => $decision['candidates']];
                    }
                } else {
                    $result['unmatched']++;
                }
            }
        });

        return $result;
    }

    /**
     * The decision for an already-resolved assignment, for callers that batch
     * their own assignment and position lookups (the oversight eligibility
     * snapshot). Same rules as resolveFor().
     *
     * @param  Collection<int, AssessmentFormVersion>  $versions
     * @return array{status: string, version: ?AssessmentFormVersion, candidates: array<int, string>}
     */
    public function decideForAssignment(Collection $versions, EmployeeAssignment $assignment, ?Position $position, string $date): array
    {
        return $this->decide($versions, $assignment, $position, $date);
    }

    /**
     * @param  Collection<int, AssessmentFormVersion>  $versions
     * @return array{status: string, version: ?AssessmentFormVersion, candidates: array<int, string>}
     */
    private function decide(Collection $versions, EmployeeAssignment $assignment, ?Position $position, string $date): array
    {
        $matches = [];
        foreach ($versions as $version) {
            $priority = $this->priority($version, $assignment, $position, $date);
            if ($priority !== null) {
                $matches[] = ['version' => $version, 'priority' => $priority];
            }
        }

        if ($matches === []) {
            return ['status' => self::NO_APPLICABLE_FORM, 'version' => null, 'candidates' => []];
        }

        $top = max(array_column($matches, 'priority'));
        $best = array_values(array_filter($matches, fn (array $match): bool => $match['priority'] === $top));

        if (count($best) > 1) {
            return ['status' => self::CONFLICT, 'version' => null, 'candidates' => array_map(fn (array $match): string => $match['version']->id, $best)];
        }

        return ['status' => self::MATCHED, 'version' => $best[0]['version'], 'candidates' => [$best[0]['version']->id]];
    }

    /** The version's priority for this assignment, or null when it does not apply. */
    private function priority(AssessmentFormVersion $version, EmployeeAssignment $assignment, ?Position $position, string $date): ?int
    {
        if ($version->form->organization_id !== null && $version->form->organization_id !== $assignment->organization_id) {
            return null;
        }

        $priority = null;

        foreach ($version->targetRules as $rule) {
            if (! $this->inForce($rule, $date) || ! $this->matches($rule, $assignment, $position)) {
                continue;
            }
            if ($rule->isExclude()) {
                return null;
            }
            $priority = max($priority ?? PHP_INT_MIN, (int) $rule->priority);
        }

        return $priority;
    }

    private function inForce(AssessmentTargetRule $rule, string $date): bool
    {
        return ($rule->effective_from === null || $rule->effective_from->toDateString() <= $date)
            && ($rule->effective_to === null || $rule->effective_to->toDateString() >= $date);
    }

    private function matches(AssessmentTargetRule $rule, EmployeeAssignment $assignment, ?Position $position): bool
    {
        $same = fn (?string $a, ?string $b): bool => $a !== null && $b !== null && mb_strtolower(trim($a)) === mb_strtolower(trim($b));

        return match ($rule->target_type) {
            TargetType::Everyone => true,
            TargetType::Position => $assignment->position_id !== null && $assignment->position_id === $rule->target_id,
            TargetType::Occupation => $position?->occupation_id !== null && $position->occupation_id === $rule->target_id,
            TargetType::GradeLevel => $same($position?->grade_level, $rule->target_value),
            TargetType::JobFamily => $same($position?->job_family, $rule->target_value),
            TargetType::Organization => $assignment->organization_id === $rule->target_id,
            TargetType::OrganizationUnit => $assignment->organization_unit_id !== null
                && ($assignment->organization_unit_id === $rule->target_id
                    || ($rule->include_descendants && in_array($rule->target_id, $this->ancestors($assignment->organization_unit_id), true))),
        };
    }

    /** @return array<int, string> the unit's ancestors, nearest first */
    private function ancestors(string $unitId): array
    {
        $ancestors = [];
        $current = $unitId;
        $guard = 0;

        while ($current !== null && $guard++ < 50) {
            if (! array_key_exists($current, $this->parents)) {
                $this->parents[$current] = OrganizationUnit::query()->whereKey($current)->value('parent_unit_id');
            }
            $current = $this->parents[$current];
            if ($current !== null) {
                $ancestors[] = $current;
            }
        }

        return $ancestors;
    }
}
