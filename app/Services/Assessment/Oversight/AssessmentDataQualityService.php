<?php

declare(strict_types=1);

namespace App\Services\Assessment\Oversight;

use App\Models\AssessmentCycle;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Data-quality rules for a cycle (docs/assessment-data-quality.md).
 *
 * Each rule is one SQL query yielding (organization_id, employee_id,
 * record_id, detail). Summaries are GROUP BY counts; detail lists are
 * paginated per rule. Only BLOCKING issues stop an institution submission.
 */
class AssessmentDataQualityService
{
    public const INFO = 'info';

    public const WARNING = 'warning';

    public const BLOCKING = 'blocking';

    /** code => [severity, resolution hint key] */
    public const RULES = [
        'NO_APPLICABLE_FORM' => self::BLOCKING,
        'MULTIPLE_APPLICABLE_FORMS' => self::BLOCKING,
        'MISSING_ASSESSMENT_ASSIGNMENT' => self::BLOCKING,
        'UNFINALIZED_ASSESSMENT' => self::BLOCKING,
        'MISSING_EVALUATOR' => self::BLOCKING,
        'INVALID_EVALUATOR' => self::BLOCKING,
        'EVALUATOR_INACTIVE' => self::WARNING,
        'EVALUATOR_OUTSIDE_SCOPE' => self::WARNING,
        'EVALUATOR_NOT_COMPLETED' => self::INFO,
        'MISSING_POSITION' => self::WARNING,
        'INVALID_ASSIGNMENT' => self::WARNING,
        'RECORD_OUTSIDE_POPULATION' => self::WARNING,
        'MISSING_GENDER' => self::WARNING,
        'UNRECOGNIZED_GENDER' => self::INFO,
        'INVALID_SCORE' => self::BLOCKING,
        'FORM_ASSIGNMENT_MISMATCH' => self::BLOCKING,
        'UNAPPROVED_FORM_VERSION' => self::BLOCKING,
        'FORM_VERSION_MISMATCH' => self::WARNING,
        'DUPLICATE_ASSESSMENT' => self::BLOCKING,
        'RESULT_WITHOUT_REQUIRED_COMPONENT' => self::BLOCKING,
        'EXCLUSION_WITHOUT_REASON' => self::BLOCKING,
        'PENDING_EXCLUSION_REQUEST' => self::BLOCKING,
        'UNCLASSIFIED_RESULT' => self::WARNING,
    ];

    /** Rules about evaluators, for the dashboard's evaluator-compliance figures. */
    public const EVALUATOR_RULES = ['MISSING_EVALUATOR', 'INVALID_EVALUATOR', 'EVALUATOR_INACTIVE', 'EVALUATOR_OUTSIDE_SCOPE', 'EVALUATOR_NOT_COMPLETED'];

    public function __construct(private readonly AssessmentCoverageService $coverage, private readonly AssessmentResultDistributionService $bands) {}

    /**
     * Counts per rule and institution.
     *
     * @return array{rules: array<string, array{severity: string, count: int}>, organizations: array<string, array{blocking: int, warning: int, info: int, total: int}>, cycle: array<int, array{code: string, severity: string}>}
     */
    public function summary(AssessmentCycle $cycle, OversightScope $scope, ?string $organizationId = null): array
    {
        $key = 'assessment-oversight:dq:'.$cycle->id.':'.$this->coverage->version($cycle).':'.$scope->cacheKey().':'.($organizationId ?? '-');

        return Cache::remember($key, 600, function () use ($cycle, $scope, $organizationId): array {
            $rules = [];
            $organizations = [];
            foreach (self::RULES as $code => $severity) {
                $query = $this->rule($cycle, $scope, $code);
                if ($query === null) {
                    $rules[$code] = ['severity' => $severity, 'count' => 0];

                    continue;
                }
                $rows = DB::query()->fromSub($query, 'q')
                    ->when($organizationId, fn ($q, $v) => $q->where('q.organization_id', $v))
                    ->selectRaw('q.organization_id, COUNT(*) as n')->groupBy('q.organization_id')->get();
                $rules[$code] = ['severity' => $severity, 'count' => (int) $rows->sum('n')];
                foreach ($rows as $row) {
                    $org = (string) $row->organization_id;
                    $organizations[$org] ??= [self::BLOCKING => 0, self::WARNING => 0, self::INFO => 0, 'total' => 0];
                    $organizations[$org][$severity] += (int) $row->n;
                    $organizations[$org]['total'] += (int) $row->n;
                }
            }

            return ['rules' => $rules, 'organizations' => $organizations, 'cycle' => $this->cycleIssues($cycle)];
        });
    }

    /** Blocking issues of one institution, including cycle-level blockers. */
    public function blockingCount(AssessmentCycle $cycle, string $organizationId): int
    {
        $summary = $this->summary($cycle, new OversightScope([$organizationId]), $organizationId);
        $cycleBlocking = collect($summary['cycle'])->where('severity', self::BLOCKING)->count();

        return ($summary['organizations'][$organizationId][self::BLOCKING] ?? 0) + $cycleBlocking;
    }

    /**
     * Normalized issue rows of one rule, paginated and named.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function issues(AssessmentCycle $cycle, OversightScope $scope, string $code, ?string $organizationId = null, int $perPage = 25): LengthAwarePaginator
    {
        abort_unless(array_key_exists($code, self::RULES), 404);
        $query = $this->rule($cycle, $scope, $code);
        if ($query === null) {
            return new LengthAwarePaginator([], 0, $perPage);
        }
        $severity = self::RULES[$code];

        return DB::query()->fromSub($query, 'q')
            ->leftJoin('employees as emp', 'emp.id', '=', 'q.employee_id')
            ->leftJoin('organizations as o', 'o.id', '=', 'q.organization_id')
            ->when($organizationId, fn ($q, $v) => $q->where('q.organization_id', $v))
            ->select(['q.organization_id', 'q.employee_id', 'q.record_id', 'q.detail', 'emp.employee_number', 'emp.full_name', 'emp.name_en as employee_name_en', 'o.name_en as organization_en', 'o.name_am as organization_am'])
            ->orderBy('o.name_en')->orderBy('emp.employee_number')
            ->paginate($perPage)->withQueryString()
            ->through(fn ($row): array => [
                'code' => $code, 'severity' => $severity, 'is_blocking' => $severity === self::BLOCKING,
                'organization' => ['id' => $row->organization_id, 'name_en' => $row->organization_en, 'name_am' => $row->organization_am],
                'employee' => $row->employee_id ? ['id' => $row->employee_id, 'number' => $row->employee_number, 'name' => $row->full_name, 'name_en' => $row->employee_name_en] : null,
                'record_id' => $row->record_id, 'details' => $row->detail,
                'resolution_hint' => 'assessmentOversight.hints.'.$code,
            ]);
    }

    /** @return array<int, array{code: string, severity: string}> issues of the cycle itself */
    public function cycleIssues(AssessmentCycle $cycle): array
    {
        $issues = [];
        if (! $cycle->isEligibilityFinalized()) {
            $issues[] = ['code' => 'ELIGIBILITY_NOT_FINALIZED', 'severity' => self::BLOCKING];
        }
        if ($cycle->result_band_policy_id === null) {
            $issues[] = ['code' => 'NO_RESULT_BAND_POLICY', 'severity' => self::INFO];
        }

        return $issues;
    }

    /** One rule as a query of (organization_id, employee_id, record_id, detail), or null when it cannot apply. */
    public function rule(AssessmentCycle $cycle, OversightScope $scope, string $code): ?Builder
    {
        $rows = fn (): Builder => DB::query()->fromSub($this->coverage->rows($cycle, $scope), 't');
        $eligible = fn (): Builder => $rows()->where('t.eligibility_status', 'eligible')->where(fn ($q) => $q->whereNull('t.reason_source')->orWhere('t.reason_source', '!=', 'approved_exception'));
        $pick = fn (Builder $q, string $detail = 'NULL'): Builder => $q->selectRaw("t.organization_id, t.employee_id, t.record_id, {$detail} as detail");
        $records = fn (): Builder => $scope->apply(DB::table('assessment_records as r')->where('r.assessment_cycle_id', $cycle->id), 'r.organization_id');
        $required = DB::table('assessment_evaluator_schemes')->selectRaw('form_version_id, SUM(required_count) as required')->groupBy('form_version_id');
        $responses = fn (bool $submittedOnly): Builder => DB::table('assessment_responses')->selectRaw('assessment_record_id, COUNT(*) as n')
            ->when($submittedOnly, fn ($q) => $q->whereNotNull('submitted_at'))->groupBy('assessment_record_id');
        $assessed = AssessmentCoverageService::ASSESSED_STATUSES;

        return match ($code) {
            'NO_APPLICABLE_FORM' => $pick($eligible()->where('t.form_resolution', 'no_applicable_form')),
            'MULTIPLE_APPLICABLE_FORMS' => $pick($eligible()->where('t.form_resolution', 'conflict')),
            'MISSING_ASSESSMENT_ASSIGNMENT' => $pick($eligible()->whereNull('t.record_id')->where('t.form_resolution', 'matched')),
            'UNFINALIZED_ASSESSMENT' => $pick($eligible()->whereIn('t.record_status', ['assigned', 'submitted']), 't.record_status'),
            'MISSING_POSITION' => $pick($eligible()->whereNull('t.position_id')),
            'MISSING_GENDER' => $pick($rows()->where('t.eligibility_status', 'eligible')->where('t.gender', 'unknown')),
            'UNRECOGNIZED_GENDER' => $pick($rows()->where('t.eligibility_status', 'eligible')->where('t.gender', 'other')),
            'EXCLUSION_WITHOUT_REASON' => $pick($rows()->where(fn ($q) => $q
                ->where(fn ($e) => $e->where('t.eligibility_status', 'excluded')->whereNull('t.reason_id'))
                ->orWhere(fn ($r) => $r->where('t.record_status', 'unassessed')->where(fn ($w) => $w->whereNull('t.unassessed_reason')
                    ->orWhereNotIn('t.unassessed_reason', DB::table('assessment_unassessed_reasons')->select('code')))))),
            'FORM_ASSIGNMENT_MISMATCH' => $pick($rows()->whereNotNull('t.record_id')->whereNotNull('t.expected_form_version_id')
                ->join('assessment_form_versions as used', 'used.id', '=', 't.form_version_id')
                ->join('assessment_form_versions as expected', 'expected.id', '=', 't.expected_form_version_id')
                ->whereColumn('used.form_id', '!=', 'expected.form_id')),
            'FORM_VERSION_MISMATCH' => $pick($rows()->whereNotNull('t.record_id')->whereNotNull('t.expected_form_version_id')
                ->join('assessment_form_versions as used', 'used.id', '=', 't.form_version_id')
                ->join('assessment_form_versions as expected', 'expected.id', '=', 't.expected_form_version_id')
                ->whereColumn('used.form_id', '=', 'expected.form_id')->whereColumn('used.id', '!=', 'expected.id')),
            'UNAPPROVED_FORM_VERSION' => $records()->join('assessment_form_versions as v', 'v.id', '=', 'r.form_version_id')
                ->whereIn('v.status', ['draft', 'archived'])
                ->selectRaw('r.organization_id, r.employee_id, r.id as record_id, v.status as detail'),
            'INVALID_SCORE' => $records()->whereIn('r.status', $assessed)
                ->where(fn ($q) => $q->whereNull('r.percentage')->orWhere('r.percentage', '<', 0)->orWhere('r.percentage', '>', 100))
                ->selectRaw('r.organization_id, r.employee_id, r.id as record_id, r.percentage as detail'),
            'DUPLICATE_ASSESSMENT' => $records()->whereIn('r.employee_id', $records()->select('r.employee_id')->groupBy('r.employee_id')->havingRaw('COUNT(*) > 1'))
                ->selectRaw('r.organization_id, r.employee_id, r.id as record_id, r.status as detail'),
            'MISSING_EVALUATOR' => $records()->whereNotIn('r.status', ['unassessed'])
                ->joinSub($required, 'req', 'req.form_version_id', '=', 'r.form_version_id')
                ->leftJoinSub($responses(false), 'resp', 'resp.assessment_record_id', '=', 'r.id')
                ->whereRaw('COALESCE(resp.n, 0) < req.required')
                ->selectRaw('r.organization_id, r.employee_id, r.id as record_id, req.required - COALESCE(resp.n, 0) as detail'),
            'RESULT_WITHOUT_REQUIRED_COMPONENT' => $records()->whereIn('r.status', $assessed)
                ->joinSub($required, 'req', 'req.form_version_id', '=', 'r.form_version_id')
                ->leftJoinSub($responses(true), 'resp', 'resp.assessment_record_id', '=', 'r.id')
                ->whereRaw('COALESCE(resp.n, 0) < req.required')
                ->selectRaw('r.organization_id, r.employee_id, r.id as record_id, req.required - COALESCE(resp.n, 0) as detail'),
            'INVALID_EVALUATOR' => $records()->join('assessment_responses as s', 's.assessment_record_id', '=', 'r.id')
                ->join('users as u', 'u.id', '=', 's.evaluator_id')->whereColumn('u.employee_id', 'r.employee_id')
                ->selectRaw("r.organization_id, r.employee_id, r.id as record_id, 'self' as detail"),
            'EVALUATOR_INACTIVE' => $records()->where('r.status', 'assigned')->join('assessment_responses as s', 's.assessment_record_id', '=', 'r.id')
                ->whereNull('s.submitted_at')->join('users as u', 'u.id', '=', 's.evaluator_id')->where('u.status', '!=', 'active')
                ->selectRaw('r.organization_id, r.employee_id, r.id as record_id, u.status as detail'),
            'EVALUATOR_OUTSIDE_SCOPE' => $records()->join('assessment_responses as s', 's.assessment_record_id', '=', 'r.id')
                ->join('users as u', 'u.id', '=', 's.evaluator_id')
                ->leftJoin('employees as ev', 'ev.id', '=', 'u.employee_id')
                ->leftJoin('employee_assignments as ea', 'ea.id', '=', 'ev.current_assignment_id')
                ->where(fn ($q) => $q->whereNull('ea.id')->orWhereColumn('ea.organization_id', '!=', 'r.organization_id'))
                ->selectRaw("r.organization_id, r.employee_id, r.id as record_id, 'evaluator_organization' as detail"),
            'EVALUATOR_NOT_COMPLETED' => $records()->where('r.status', 'assigned')->join('assessment_responses as s', 's.assessment_record_id', '=', 'r.id')
                ->whereNull('s.submitted_at')->selectRaw("r.organization_id, r.employee_id, r.id as record_id, 'pending' as detail"),
            'INVALID_ASSIGNMENT' => $rows()->whereNotNull('t.record_id')
                ->join('assessment_records as rr', 'rr.id', '=', 't.record_id')->whereColumn('rr.organization_id', '!=', 't.organization_id')
                ->selectRaw("t.organization_id, t.employee_id, t.record_id, 'record_organization' as detail"),
            'RECORD_OUTSIDE_POPULATION' => $records()->whereNotExists(fn ($q) => $q->from('assessment_cycle_employee_eligibility as x')
                ->whereColumn('x.employee_id', 'r.employee_id')->where('x.assessment_cycle_id', $cycle->id))
                ->selectRaw("r.organization_id, r.employee_id, r.id as record_id, 'not_in_snapshot' as detail"),
            'PENDING_EXCLUSION_REQUEST' => $scope->apply(DB::table('assessment_exclusion_requests as x')->where('x.assessment_cycle_id', $cycle->id)->where('x.status', 'pending'), 'x.organization_id')
                ->selectRaw("x.organization_id, x.employee_id, NULL as record_id, 'pending' as detail"),
            'UNCLASSIFIED_RESULT' => $this->unclassified($cycle, $rows),
            default => null,
        };
    }

    private function unclassified(AssessmentCycle $cycle, \Closure $rows): ?Builder
    {
        $policy = $cycle->bandPolicy()->with('bands')->first();
        if ($policy === null || $policy->bands->isEmpty()) {
            return null;
        }
        $query = $rows()->where('t.eligibility_status', 'eligible')->where('t.outcome', 'assessed');
        foreach ($policy->bands as $band) {
            [$condition, $bindings] = $this->bands->condition($band);
            $query->whereRaw("NOT ({$condition})", $bindings);
        }

        return $query->selectRaw('t.organization_id, t.employee_id, t.record_id, t.percentage as detail');
    }
}
