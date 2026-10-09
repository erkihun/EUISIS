<?php

declare(strict_types=1);

namespace App\Services\Assessment\Oversight;

use App\Models\AssessmentCycle;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The one place that defines the oversight numbers (docs/assessment-coverage.md).
 *
 *  - Population  = the cycle's eligibility snapshot (never "all employees").
 *  - Eligible    = snapshot rows with eligibility_status = eligible.
 *  - Assessed    = eligible + the cycle's record is reviewed or acknowledged
 *                  and carries a final percentage (a finalized valid result).
 *  - Unassessed  = every other eligible employee, broken down by derived
 *                  outcome; nothing about it is stored separately.
 *  - Coverage %  = assessed / eligible × 100.
 *
 * Only an approved exclusion can move someone out of "eligible", and only
 * when the cycle's policy says exclusions reduce the denominator.
 * Everything is SQL aggregation; no employee is loaded into memory.
 */
class AssessmentCoverageService
{
    /** Record statuses that mean "finalized": the reviewer has signed off the result. */
    public const ASSESSED_STATUSES = ['reviewed', 'acknowledged'];

    /** Outcome of an eligible employee, in the order the reports list them. */
    public const OUTCOMES = ['assessed', 'approved_exception', 'reported_unassessed', 'awaiting_review', 'in_progress', 'not_started', 'not_assigned', 'invalid_result'];

    public const GENDERS = ['male', 'female', 'other', 'unknown'];

    /** Values found in Employee master data for each bucket (case-insensitive). */
    public const GENDER_VALUES = ['male' => ['male', 'm', 'ወንድ'], 'female' => ['female', 'f', 'ሴት']];

    private const CACHE_SECONDS = 600;

    /**
     * Row-level source: one row per snapshot employee with its outcome and
     * gender bucket. Every aggregate, list and export starts here.
     */
    public function rows(AssessmentCycle $cycle, OversightScope $scope): Builder
    {
        $assessed = "'".implode("','", self::ASSESSED_STATUSES)."'";
        $male = $this->quoted(self::GENDER_VALUES['male']);
        $female = $this->quoted(self::GENDER_VALUES['female']);

        $query = DB::table('assessment_cycle_employee_eligibility as e')
            ->join('employees as emp', 'emp.id', '=', 'e.employee_id')
            ->leftJoin('assessment_records as r', function ($join): void {
                $join->on('r.assessment_cycle_id', '=', 'e.assessment_cycle_id')->on('r.employee_id', '=', 'e.employee_id');
            })
            ->where('e.assessment_cycle_id', $cycle->id)
            ->select([
                'e.id as eligibility_id', 'e.employee_id', 'e.organization_id', 'e.organization_unit_id', 'e.position_id',
                'e.eligibility_status', 'e.reason_id', 'e.reason_source', 'e.form_resolution', 'e.expected_form_version_id',
                'r.id as record_id', 'r.status as record_status', 'r.form_version_id', 'r.percentage', 'r.unassessed_reason',
                'emp.employee_number', 'emp.full_name', 'emp.name_en as employee_name_en',
            ])
            ->selectRaw("CASE
                WHEN r.status IN ({$assessed}) AND r.percentage IS NOT NULL THEN 'assessed'
                WHEN e.reason_source = 'approved_exception' THEN 'approved_exception'
                WHEN r.status = 'unassessed' THEN 'reported_unassessed'
                WHEN r.status = 'submitted' THEN 'awaiting_review'
                WHEN r.status IN ({$assessed}) THEN 'invalid_result'
                WHEN r.id IS NOT NULL AND EXISTS (SELECT 1 FROM assessment_responses s WHERE s.assessment_record_id = r.id AND s.submitted_at IS NOT NULL) THEN 'in_progress'
                WHEN r.id IS NOT NULL THEN 'not_started'
                ELSE 'not_assigned' END AS outcome")
            ->selectRaw("CASE
                WHEN emp.gender IS NULL OR TRIM(emp.gender) = '' THEN 'unknown'
                WHEN LOWER(TRIM(emp.gender)) IN ({$male}) THEN 'male'
                WHEN LOWER(TRIM(emp.gender)) IN ({$female}) THEN 'female'
                ELSE 'other' END AS gender");

        return $scope->apply($query, 'e.organization_id', 'e.organization_unit_id');
    }

    /**
     * Aggregated metrics, optionally grouped by a dimension of rows():
     * organization_id, organization_unit_id, form_version_id, gender, outcome.
     *
     * @param  array<string, mixed>  $filters  see applyFilters()
     * @return array<int, array<string, mixed>> one entry per group (a single entry when ungrouped)
     */
    public function metrics(AssessmentCycle $cycle, OversightScope $scope, ?string $groupBy = null, array $filters = []): array
    {
        $key = 'assessment-oversight:metrics:'.$cycle->id.':'.$this->version($cycle).':'.$scope->cacheKey().':'.($groupBy ?? '-').':'.md5(json_encode($filters));

        return Cache::remember($key, self::CACHE_SECONDS, function () use ($cycle, $scope, $groupBy, $filters): array {
            $query = $this->source($cycle, $scope, $filters);
            $sums = ['COUNT(*) AS population',
                "SUM(CASE WHEN t.eligibility_status = 'eligible' THEN 1 ELSE 0 END) AS eligible",
                "SUM(CASE WHEN t.eligibility_status = 'excluded' THEN 1 ELSE 0 END) AS excluded",
                "SUM(CASE WHEN t.eligibility_status = 'excluded' AND t.reason_source = 'approved_exception' THEN 1 ELSE 0 END) AS approved_exclusions",
                "SUM(CASE WHEN t.eligibility_status = 'eligible' AND t.record_id IS NOT NULL THEN 1 ELSE 0 END) AS assigned"];
            foreach (self::OUTCOMES as $outcome) {
                $sums[] = "SUM(CASE WHEN t.eligibility_status = 'eligible' AND t.outcome = '{$outcome}' THEN 1 ELSE 0 END) AS o_{$outcome}";
            }
            foreach (self::GENDERS as $gender) {
                $sums[] = "SUM(CASE WHEN t.eligibility_status = 'eligible' AND t.gender = '{$gender}' THEN 1 ELSE 0 END) AS eligible_{$gender}";
                $sums[] = "SUM(CASE WHEN t.eligibility_status = 'eligible' AND t.gender = '{$gender}' AND t.outcome = 'assessed' THEN 1 ELSE 0 END) AS assessed_{$gender}";
            }
            $query->selectRaw(implode(', ', $sums));
            if ($groupBy !== null) {
                $query->addSelect("t.{$groupBy} as group_key")->groupBy("t.{$groupBy}");
            }

            return $query->get()->map(fn ($row): array => $this->shape((array) $row))->all();
        });
    }

    /** City/scope total. */
    public function totals(AssessmentCycle $cycle, OversightScope $scope, array $filters = []): array
    {
        return $this->metrics($cycle, $scope, null, $filters)[0] ?? $this->shape([]);
    }

    /** @return array<string, array<string, mixed>> organization id => metrics */
    public function institutionCoverage(AssessmentCycle $cycle, OversightScope $scope, array $filters = []): array
    {
        return collect($this->metrics($cycle, $scope, 'organization_id', $filters))->keyBy('group_key')->all();
    }

    public function eligibleCount(AssessmentCycle $cycle, OversightScope $scope): int
    {
        return $this->totals($cycle, $scope)['eligible'];
    }

    public function assessedCount(AssessmentCycle $cycle, OversightScope $scope): int
    {
        return $this->totals($cycle, $scope)['assessed'];
    }

    public function unassessedCount(AssessmentCycle $cycle, OversightScope $scope): int
    {
        return $this->totals($cycle, $scope)['unassessed'];
    }

    public function coveragePercent(AssessmentCycle $cycle, OversightScope $scope): ?string
    {
        return $this->totals($cycle, $scope)['coverage_percent'];
    }

    /** @return array<string, array{eligible: int, assessed: int, unassessed: int, coverage_percent: ?string}> */
    public function genderCoverage(AssessmentCycle $cycle, OversightScope $scope, array $filters = []): array
    {
        return $this->totals($cycle, $scope, $filters)['gender'];
    }

    /** Percentage to 2 dp with bcmath; null when there is nothing to divide by. */
    public static function percent(int $part, int $whole): ?string
    {
        if ($whole <= 0) {
            return null;
        }
        $value = bcdiv(bcmul((string) $part, '100', 6), (string) $whole, 6);

        return bcadd($value, '0.005', 2);
    }

    /**
     * Reconciliation checks every aggregate must pass; a failure is a data
     * consistency issue, never silently corrected.
     *
     * @return array<int, array{check: string, ok: bool, expected: int, actual: int}>
     */
    public static function reconcile(array $m, ?int $bandTotal = null, ?int $classifiable = null): array
    {
        $checks = [
            ['check' => 'population', 'expected' => $m['population'], 'actual' => $m['eligible'] + $m['excluded']],
            ['check' => 'eligible_outcomes', 'expected' => $m['eligible'], 'actual' => array_sum($m['outcomes'])],
            ['check' => 'eligible_assessed_unassessed', 'expected' => $m['eligible'], 'actual' => $m['assessed'] + $m['unassessed']],
            ['check' => 'gender_eligible', 'expected' => $m['eligible'], 'actual' => array_sum(array_column($m['gender'], 'eligible'))],
            ['check' => 'gender_assessed', 'expected' => $m['assessed'], 'actual' => array_sum(array_column($m['gender'], 'assessed'))],
        ];
        if ($bandTotal !== null && $classifiable !== null) {
            $checks[] = ['check' => 'result_bands', 'expected' => $classifiable, 'actual' => $bandTotal];
        }

        return array_map(fn (array $c): array => $c + ['ok' => $c['expected'] === $c['actual']], $checks);
    }

    /**
     * Server-side filters shared by every list and aggregate.
     *
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['organization_id'] ?? null, fn ($q, $v) => $q->where('e.organization_id', $v))
            ->when($filters['organization_unit_id'] ?? null, fn ($q, $v) => $q->where('e.organization_unit_id', $v))
            ->when($filters['form_version_id'] ?? null, fn ($q, $v) => $q->where('r.form_version_id', $v))
            ->when($filters['form_id'] ?? null, fn ($q, $v) => $q->whereIn('r.form_version_id', DB::table('assessment_form_versions')->where('form_id', $v)->select('id')))
            ->when($filters['eligibility_status'] ?? null, fn ($q, $v) => $q->where('e.eligibility_status', $v))
            ->when($filters['reason_id'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('e.reason_id', $v)
                ->orWhereIn('r.unassessed_reason', DB::table('assessment_unassessed_reasons')->where('id', $v)->select('code'))))
            ->when($filters['search'] ?? null, function ($q, $v): void {
                $like = '%'.mb_strtolower(trim((string) $v)).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(emp.employee_number) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(emp.full_name) LIKE ?', [$like])->orWhereRaw('LOWER(emp.name_en) LIKE ?', [$like]));
            });
    }

    /**
     * rows() with every filter applied, wrapped as "t" so the derived outcome
     * and gender can be filtered and grouped like ordinary columns.
     *
     * @param  array<string, mixed>  $filters
     */
    public function source(AssessmentCycle $cycle, OversightScope $scope, array $filters = []): Builder
    {
        $query = DB::query()->fromSub($this->applyFilters($this->rows($cycle, $scope), $filters), 't');
        if (! empty($filters['gender'])) {
            $query->where('t.gender', (string) $filters['gender']);
        }
        if (! empty($filters['outcome'])) {
            $outcomes = (array) $filters['outcome'];
            if (in_array('unassessed', $outcomes, true)) {
                $outcomes = [...$outcomes, ...array_diff(self::OUTCOMES, ['assessed'])];
            }
            $query->where('t.eligibility_status', 'eligible')->whereIn('t.outcome', array_values(array_intersect(array_unique($outcomes), self::OUTCOMES)) ?: ['-']);
        }

        return $query;
    }

    /** Cache generation for a cycle; bumped whenever any of its source data changes. */
    public function version(AssessmentCycle $cycle): int
    {
        return (int) Cache::get('assessment-oversight:version:'.$cycle->id, 0);
    }

    public static function bump(?string $cycleId): void
    {
        if ($cycleId === null) {
            return;
        }
        $key = 'assessment-oversight:version:'.$cycleId;
        Cache::add($key, 0, now()->addDays(30));
        Cache::increment($key);
    }

    /** @param array<string, mixed> $row */
    private function shape(array $row): array
    {
        $int = fn (string $key): int => (int) ($row[$key] ?? 0);
        $outcomes = [];
        foreach (self::OUTCOMES as $outcome) {
            $outcomes[$outcome] = $int('o_'.$outcome);
        }
        $eligible = $int('eligible');
        $assessed = $outcomes['assessed'];
        $gender = [];
        foreach (self::GENDERS as $g) {
            $ge = $int('eligible_'.$g);
            $ga = $int('assessed_'.$g);
            $gender[$g] = ['eligible' => $ge, 'assessed' => $ga, 'unassessed' => $ge - $ga, 'coverage_percent' => self::percent($ga, $ge)];
        }

        return [
            'group_key' => $row['group_key'] ?? null,
            'population' => $int('population'),
            'eligible' => $eligible,
            'excluded' => $int('excluded'),
            'approved_exclusions' => $int('approved_exclusions'),
            'assigned' => $int('assigned'),
            'assessed' => $assessed,
            'unassessed' => $eligible - $assessed,
            'coverage_percent' => self::percent($assessed, $eligible),
            // Against everyone the snapshot placed in scope before approved exclusions: shows what exclusions changed.
            'gross_coverage_percent' => self::percent($assessed, $eligible + $int('approved_exclusions')),
            'outcomes' => $outcomes,
            'gender' => $gender,
        ];
    }

    /** @param array<int, string> $values */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(fn (string $v): string => DB::getPdo()->quote(mb_strtolower($v)), $values));
    }
}
