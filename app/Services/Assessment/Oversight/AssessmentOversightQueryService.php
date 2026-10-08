<?php

declare(strict_types=1);

namespace App\Services\Assessment\Oversight;

use App\Models\AssessmentCycle;
use App\Models\AssessmentInstitutionSubmission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read model for the oversight pages: institution table, drill-downs, form
 * usage, peer completion, reasons and trends. All counts come from
 * AssessmentCoverageService::rows(), so every page uses the same
 * definitions; lists are paginated and aggregates stay in SQL.
 */
class AssessmentOversightQueryService
{
    public const INSTITUTION_SORTS = ['name', 'eligible', 'assessed', 'unassessed', 'coverage', 'submission_status'];

    public function __construct(
        private readonly AssessmentCoverageService $coverage,
        private readonly AssessmentDataQualityService $quality,
    ) {}

    /**
     * The cycle a page shows: the requested one, else the single active
     * cycle when there is exactly one, else none (the page asks to choose).
     */
    public function selectCycle(?string $cycleId): ?AssessmentCycle
    {
        if ($cycleId) {
            return AssessmentCycle::query()->findOrFail($cycleId);
        }
        $active = AssessmentCycle::query()->where('status', 'active')->limit(2)->get();

        return $active->count() === 1 ? $active->first() : null;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function cycleOptions(): Collection
    {
        return AssessmentCycle::query()->orderByDesc('period_end')->limit(100)
            ->get(['id', 'code', 'name_en', 'name_am', 'status', 'period_start', 'period_end'])
            ->map(fn (AssessmentCycle $c): array => [...$c->only(['id', 'code', 'name_en', 'name_am', 'status']), 'period_start' => $c->period_start?->toDateString(), 'period_end' => $c->period_end?->toDateString()]);
    }

    /**
     * Institution monitoring table: server-side search, filters, sort and pagination.
     *
     * @param  array<string, mixed>  $filters
     */
    public function institutions(AssessmentCycle $cycle, OversightScope $scope, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $m = DB::query()->fromSub($this->coverage->rows($cycle, $scope), 't')
            ->selectRaw("t.organization_id,
                SUM(CASE WHEN t.eligibility_status = 'eligible' THEN 1 ELSE 0 END) AS eligible,
                SUM(CASE WHEN t.eligibility_status = 'eligible' AND t.record_id IS NOT NULL THEN 1 ELSE 0 END) AS assigned,
                SUM(CASE WHEN t.eligibility_status = 'eligible' AND t.outcome = 'assessed' THEN 1 ELSE 0 END) AS assessed,
                SUM(CASE WHEN t.eligibility_status = 'eligible' AND t.outcome = 'assessed' AND t.gender = 'male' THEN 1 ELSE 0 END) AS male_assessed,
                SUM(CASE WHEN t.eligibility_status = 'eligible' AND t.outcome = 'assessed' AND t.gender = 'female' THEN 1 ELSE 0 END) AS female_assessed,
                SUM(CASE WHEN t.eligibility_status = 'eligible' AND t.outcome IN ('not_assigned', 'not_started') THEN 1 ELSE 0 END) AS not_started,
                SUM(CASE WHEN t.eligibility_status = 'eligible' AND t.outcome = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
                SUM(CASE WHEN t.eligibility_status = 'eligible' AND t.outcome = 'awaiting_review' THEN 1 ELSE 0 END) AS awaiting_review")
            ->groupBy('t.organization_id');

        $query = $scope->apply(DB::table('assessment_cycle_organizations as co'), 'co.organization_id')
            ->join('organizations as o', 'o.id', '=', 'co.organization_id')
            ->leftJoinSub($m, 'm', 'm.organization_id', '=', 'co.organization_id')
            ->where('co.assessment_cycle_id', $cycle->id)
            ->when($filters['organization_id'] ?? null, fn ($q, $v) => $q->where('co.organization_id', $v))
            ->when($filters['participation'] ?? 'included', fn ($q, $v) => $v === 'all' ? $q : $q->where('co.status', $v))
            ->when($filters['submission_status'] ?? null, fn ($q, $v) => $q->where('co.submission_status', $v))
            ->when($filters['search'] ?? null, function ($q, $v): void {
                $like = '%'.mb_strtolower(trim((string) $v)).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(o.name_en) LIKE ?', [$like])->orWhereRaw('LOWER(o.name_am) LIKE ?', [$like])->orWhereRaw('LOWER(o.code) LIKE ?', [$like]));
            })
            ->select(['co.id', 'co.organization_id', 'co.status', 'co.submission_status', 'o.code', 'o.name_en', 'o.name_am',
                'm.eligible', 'm.assigned', 'm.assessed', 'm.male_assessed', 'm.female_assessed', 'm.not_started', 'm.in_progress', 'm.awaiting_review']);

        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        match ($filters['sort'] ?? 'name') {
            'eligible' => $query->orderByRaw('COALESCE(m.eligible, 0) '.$direction),
            'assessed' => $query->orderByRaw('COALESCE(m.assessed, 0) '.$direction),
            'unassessed' => $query->orderByRaw('COALESCE(m.eligible, 0) - COALESCE(m.assessed, 0) '.$direction),
            'coverage' => $query->orderByRaw('CASE WHEN COALESCE(m.eligible, 0) = 0 THEN 0 ELSE m.assessed * 1.0 / m.eligible END '.$direction),
            'submission_status' => $query->orderBy('co.submission_status', $direction),
            default => $query->orderBy('o.name_en', $direction),
        };
        $page = $query->orderBy('co.id')->paginate($perPage)->withQueryString();

        $orgIds = collect($page->items())->pluck('organization_id')->all();
        $issues = $this->quality->summary($cycle, $scope)['organizations'];
        $cycleBlocking = collect($this->quality->cycleIssues($cycle))->where('severity', AssessmentDataQualityService::BLOCKING)->count();
        $latest = $this->latestSubmissions($cycle, $orgIds);

        return $page->through(function ($row) use ($cycle, $issues, $cycleBlocking, $latest): array {
            $eligible = (int) $row->eligible;
            $assessed = (int) $row->assessed;
            $dq = $issues[$row->organization_id] ?? ['blocking' => 0, 'warning' => 0, 'info' => 0, 'total' => 0];
            $submission = $latest->get($row->organization_id);

            return [
                'organization' => ['id' => $row->organization_id, 'code' => $row->code, 'name_en' => $row->name_en, 'name_am' => $row->name_am],
                'participation' => $row->status, 'submission_status' => $row->submission_status,
                'eligible' => $eligible, 'assigned' => (int) $row->assigned, 'assessed' => $assessed, 'unassessed' => $eligible - $assessed,
                'coverage_percent' => AssessmentCoverageService::percent($assessed, $eligible),
                'male_assessed' => (int) $row->male_assessed, 'female_assessed' => (int) $row->female_assessed,
                'not_started' => (int) $row->not_started, 'in_progress' => (int) $row->in_progress, 'awaiting_review' => (int) $row->awaiting_review, 'finalized' => $assessed,
                'issues' => $dq,
                'status' => $this->institutionStatus($row->submission_status, (int) $row->assigned, $eligible, (int) $row->not_started + (int) $row->in_progress + (int) $row->awaiting_review, $dq['blocking'] + $cycleBlocking),
                'deadline_status' => $this->deadlineStatus($cycle, $row->submission_status),
                'submitted_at' => $submission?->submitted_at?->toIso8601String(), 'verified_at' => $submission?->verified_at?->toIso8601String(),
            ];
        });
    }

    /**
     * NOT_STARTED / IN_PROGRESS / READY_FOR_SUBMISSION from the data, then the
     * submission workflow status once there is one. No percentage thresholds.
     */
    public function institutionStatus(string $submissionStatus, int $assigned, int $eligible, int $open, int $blocking): string
    {
        if ($submissionStatus !== 'not_submitted') {
            return $submissionStatus;
        }
        if ($assigned === 0) {
            return 'not_started';
        }

        return $open === 0 && $blocking === 0 && $eligible > 0 ? 'ready_for_submission' : 'in_progress';
    }

    /** null when the cycle has no deadline; due_soon only when a reminder window is configured. */
    public function deadlineStatus(AssessmentCycle $cycle, string $submissionStatus): ?string
    {
        if ($cycle->submission_deadline === null) {
            return null;
        }
        if (in_array($submissionStatus, ['submitted', 'verified', 'finalized'], true)) {
            return 'met';
        }
        $today = now()->toDateString();
        $deadline = $cycle->submission_deadline->toDateString();
        if ($today > $deadline) {
            return 'overdue';
        }
        if ($cycle->reminder_days_before !== null && $today >= $cycle->submission_deadline->copy()->subDays($cycle->reminder_days_before)->toDateString()) {
            return 'due_soon';
        }

        return 'open';
    }

    /**
     * Employee status list (coverage / unassessed drill-down). Final results
     * only when the caller may see them; never criterion responses.
     *
     * @param  array<string, mixed>  $filters
     */
    public function employees(AssessmentCycle $cycle, OversightScope $scope, array $filters, bool $withResults, int $perPage = 25): LengthAwarePaginator
    {
        $responses = DB::table('assessment_responses')->selectRaw('assessment_record_id, COUNT(*) as total, COUNT(submitted_at) as submitted')->groupBy('assessment_record_id');

        return $this->employeeQuery($cycle, $scope, $filters)
            ->leftJoinSub($responses, 'rs', 'rs.assessment_record_id', '=', 't.record_id')
            ->addSelect(['rs.total as evaluators_total', 'rs.submitted as evaluators_submitted'])
            ->orderBy('t.employee_number')->orderBy('t.employee_id')
            ->paginate($perPage)->withQueryString()
            ->through(fn ($row): array => $this->employeeRow($row, $withResults));
    }

    /** The employee list query, also streamed by the CSV export. */
    public function employeeQuery(AssessmentCycle $cycle, OversightScope $scope, array $filters): Builder
    {
        return $this->coverage->source($cycle, $scope, $filters)
            ->leftJoin('organizations as o', 'o.id', '=', 't.organization_id')
            ->leftJoin('organization_units as u', 'u.id', '=', 't.organization_unit_id')
            ->leftJoin('positions as p', 'p.id', '=', 't.position_id')
            ->leftJoin('assessment_form_versions as v', 'v.id', '=', 't.form_version_id')
            ->leftJoin('assessment_form_versions as xv', 'xv.id', '=', 't.expected_form_version_id')
            ->leftJoin('assessment_unassessed_reasons as er', 'er.id', '=', 't.reason_id')
            ->leftJoin('assessment_unassessed_reasons as rr', 'rr.code', '=', 't.unassessed_reason')
            ->select(['t.eligibility_id', 't.employee_id', 't.employee_number', 't.full_name', 't.employee_name_en', 't.gender', 't.eligibility_status', 't.outcome',
                't.record_id', 't.record_status', 't.percentage', 't.form_resolution', 't.reason_source',
                'o.name_en as organization_en', 'o.name_am as organization_am', 'u.name_en as unit_en', 'u.name_am as unit_am',
                'p.title_en as position_en', 'p.title_am as position_am',
                'v.name_en as form_en', 'v.name_am as form_am', 'v.version_no as form_version', 'xv.name_en as expected_form_en', 'xv.name_am as expected_form_am',
                'er.code as exception_code', 'er.name_en as exception_en', 'er.name_am as exception_am',
                'rr.code as reported_code', 'rr.name_en as reported_en', 'rr.name_am as reported_am']);
    }

    /** @return array<string, mixed> */
    public function employeeRow(object $row, bool $withResults): array
    {
        $reason = $row->exception_code
            ? ['code' => $row->exception_code, 'name_en' => $row->exception_en, 'name_am' => $row->exception_am, 'source' => $row->reason_source]
            : ($row->reported_code ? ['code' => $row->reported_code, 'name_en' => $row->reported_en, 'name_am' => $row->reported_am, 'source' => 'institution_reported'] : null);

        return [
            'eligibility_id' => $row->eligibility_id, 'employee_id' => $row->employee_id, 'employee_number' => $row->employee_number, 'name' => $row->full_name, 'name_en' => $row->employee_name_en,
            'gender' => $row->gender, 'eligibility_status' => $row->eligibility_status, 'outcome' => $row->eligibility_status === 'eligible' ? $row->outcome : 'excluded',
            'organization' => ['name_en' => $row->organization_en, 'name_am' => $row->organization_am],
            'unit' => ['name_en' => $row->unit_en, 'name_am' => $row->unit_am],
            'position' => ['title_en' => $row->position_en, 'title_am' => $row->position_am],
            'form' => $row->form_en ? ['name_en' => $row->form_en, 'name_am' => $row->form_am, 'version_no' => $row->form_version] : null,
            'expected_form' => $row->expected_form_en ? ['name_en' => $row->expected_form_en, 'name_am' => $row->expected_form_am] : null,
            'form_resolution' => $row->form_resolution, 'record_status' => $row->record_status,
            'evaluators' => isset($row->evaluators_total) && $row->evaluators_total !== null ? ['submitted' => (int) $row->evaluators_submitted, 'total' => (int) $row->evaluators_total] : null,
            // Same 4-dp string on every database driver.
            'percentage' => $withResults && $row->outcome === 'assessed' && $row->percentage !== null ? bcadd((string) $row->percentage, '0', 4) : null,
            'reason' => $reason,
        ];
    }

    /** Units of one institution with the same metrics as the institution table. */
    public function units(AssessmentCycle $cycle, OversightScope $scope, string $organizationId): array
    {
        $metrics = $this->coverage->metrics($cycle, $scope, 'organization_unit_id', ['organization_id' => $organizationId]);
        $names = DB::table('organization_units')->whereIn('id', array_filter(array_column($metrics, 'group_key')))->get(['id', 'name_en', 'name_am'])->keyBy('id');

        return collect($metrics)->map(fn (array $m): array => [...$m, 'unit' => $m['group_key'] ? (array) ($names[$m['group_key']] ?? ['id' => $m['group_key']]) : null])
            ->sortBy(fn (array $m): string => (string) ($m['unit']['name_en'] ?? ''))->values()->all();
    }

    /**
     * Forms in use: assigned, assessed, completion, and how many employees the
     * target rules expected on each version.
     *
     * @return array<int, array<string, mixed>>
     */
    public function formUsage(AssessmentCycle $cycle, OversightScope $scope, array $filters = []): array
    {
        $source = $this->coverage->source($cycle, $scope, $filters);
        $used = (clone $source)->whereNotNull('t.form_version_id')
            ->selectRaw("t.form_version_id as version_id, COUNT(*) as assigned, SUM(CASE WHEN t.outcome = 'assessed' THEN 1 ELSE 0 END) as assessed")
            ->groupBy('t.form_version_id')->get()->keyBy('version_id');
        $expected = (clone $source)->where('t.eligibility_status', 'eligible')->whereNotNull('t.expected_form_version_id')
            ->selectRaw('t.expected_form_version_id as version_id, COUNT(*) as expected')->groupBy('t.expected_form_version_id')->pluck('expected', 'version_id');
        $ids = $used->keys()->merge($expected->keys())->unique()->values();
        $versions = DB::table('assessment_form_versions as v')->join('assessment_forms as f', 'f.id', '=', 'v.form_id')->whereIn('v.id', $ids)
            ->get(['v.id', 'v.version_no', 'v.status', 'v.name_en', 'v.name_am', 'f.code', 'f.name_en as form_en', 'f.name_am as form_am'])->keyBy('id');
        $targets = DB::table('assessment_form_target_rules')->whereIn('form_version_id', $ids)->select('form_version_id', 'target_type', 'effect')->distinct()->get()->groupBy('form_version_id');

        return $ids->map(function ($id) use ($used, $expected, $versions, $targets): array {
            $assigned = (int) ($used[$id]->assigned ?? 0);
            $assessed = (int) ($used[$id]->assessed ?? 0);
            $v = $versions[$id] ?? null;

            return [
                'version_id' => $id, 'code' => $v?->code, 'form_en' => $v?->form_en, 'form_am' => $v?->form_am, 'version_no' => $v?->version_no, 'version_status' => $v?->status,
                'targets' => collect($targets[$id] ?? [])->map(fn ($t): string => $t->effect.':'.$t->target_type)->values()->all(),
                'expected' => (int) ($expected[$id] ?? 0), 'assigned' => $assigned, 'assessed' => $assessed, 'unassessed' => $assigned - $assessed,
                'completion_percent' => AssessmentCoverageService::percent($assessed, $assigned),
            ];
        })->sortBy('code')->values()->all();
    }

    /** Peer-assessment completion, only for versions that configure peers. */
    public function peerCompletion(AssessmentCycle $cycle, OversightScope $scope, array $filters = []): array
    {
        $records = $this->coverage->source($cycle, $scope, $filters)->whereNotNull('t.record_id')->where('t.record_status', '!=', 'unassessed')->select('t.record_id', 't.form_version_id', 't.record_status');

        return DB::query()->fromSub($records, 'x')
            ->join('assessment_evaluator_schemes as s', fn ($j) => $j->on('s.form_version_id', '=', 'x.form_version_id')->where('s.evaluator_type', 'peer'))
            ->leftJoinSub(DB::table('assessment_responses')->selectRaw('assessment_record_id, COUNT(*) as assigned, COUNT(submitted_at) as completed')->groupBy('assessment_record_id'), 'rs', 'rs.assessment_record_id', '=', 'x.record_id')
            ->join('assessment_form_versions as v', 'v.id', '=', 'x.form_version_id')
            ->selectRaw('x.form_version_id, v.name_en, v.name_am, v.version_no, COUNT(*) as records,
                SUM(s.required_count) as required, SUM(COALESCE(rs.assigned, 0)) as assigned, SUM(COALESCE(rs.completed, 0)) as completed,
                SUM(CASE WHEN s.required_count > COALESCE(rs.assigned, 0) THEN s.required_count - COALESCE(rs.assigned, 0) ELSE 0 END) as missing,
                SUM(CASE WHEN COALESCE(rs.completed, 0) >= s.required_count THEN 1 ELSE 0 END) as aggregate_ready')
            ->groupBy('x.form_version_id', 'v.name_en', 'v.name_am', 'v.version_no')->get()
            ->map(fn ($r): array => ['version_id' => $r->form_version_id, 'name_en' => $r->name_en, 'name_am' => $r->name_am, 'version_no' => $r->version_no,
                'records' => (int) $r->records, 'required' => (int) $r->required, 'assigned' => (int) $r->assigned, 'completed' => (int) $r->completed,
                'missing' => (int) $r->missing,
                'aggregate_ready' => (int) $r->aggregate_ready, 'aggregate_not_ready' => (int) $r->records - (int) $r->aggregate_ready])->all();
    }

    /**
     * Why eligible employees are unassessed, and why others are out of the denominator.
     *
     * @return array{exceptions: array<int, array<string, mixed>>, reported: array<int, array<string, mixed>>, excluded: array<int, array<string, mixed>>}
     */
    public function reasons(AssessmentCycle $cycle, OversightScope $scope, array $filters = []): array
    {
        $source = fn () => $this->coverage->source($cycle, $scope, $filters);
        $named = fn (Collection $rows, string $key): array => $rows->map(fn ($r): array => (array) $r)->all();

        return [
            'exceptions' => $named($source()->where('t.eligibility_status', 'eligible')->where('t.outcome', 'approved_exception')
                ->leftJoin('assessment_unassessed_reasons as ar', 'ar.id', '=', 't.reason_id')
                ->selectRaw('ar.code, ar.name_en, ar.name_am, COUNT(*) as n')->groupBy('ar.code', 'ar.name_en', 'ar.name_am')->get(), 'exceptions'),
            'reported' => $named($source()->where('t.eligibility_status', 'eligible')->where('t.outcome', 'reported_unassessed')
                ->leftJoin('assessment_unassessed_reasons as ar', 'ar.code', '=', 't.unassessed_reason')
                ->selectRaw('t.unassessed_reason as code, ar.name_en, ar.name_am, COUNT(*) as n')->groupBy('t.unassessed_reason', 'ar.name_en', 'ar.name_am')->get(), 'reported'),
            'excluded' => $named($source()->where('t.eligibility_status', 'excluded')
                ->leftJoin('assessment_unassessed_reasons as ar', 'ar.id', '=', 't.reason_id')
                ->selectRaw('ar.code, ar.name_en, ar.name_am, t.reason_source as source, COUNT(*) as n')->groupBy('ar.code', 'ar.name_en', 'ar.name_am', 't.reason_source')->get(), 'excluded'),
        ];
    }

    /**
     * Coverage across cycles of the same assessment type. Coverage is always
     * the same formula; a changed eligibility rule or band policy is flagged
     * so figures are not compared blindly.
     *
     * @return array<int, array<string, mixed>>
     */
    public function trend(AssessmentCycle $cycle, OversightScope $scope): array
    {
        $cycles = AssessmentCycle::query()->where('assessment_type_id', $cycle->assessment_type_id)->whereNotNull('eligibility_snapshot_at')
            ->where('period_end', '<=', $cycle->period_end)->orderByDesc('period_end')->limit(6)->get()->reverse()->values();
        $rule = fn (AssessmentCycle $c): string => json_encode([$c->eligibleStatuses(), $c->population_rule, $c->min_service_days, $c->exclusion_reduces_denominator]);

        return $cycles->map(fn (AssessmentCycle $c): array => [
            'cycle' => $c->only(['id', 'code', 'name_en', 'name_am']), 'period_end' => $c->period_end?->toDateString(),
            ...collect($this->coverage->totals($c, $scope))->only(['eligible', 'assessed', 'coverage_percent'])->all(),
            'eligibility_rules_differ' => $rule($c) !== $rule($cycle),
            'band_policy_differs' => $c->result_band_policy_id !== $cycle->result_band_policy_id,
        ])->all();
    }

    /** @return Collection<string, AssessmentInstitutionSubmission> organization id => latest submission */
    public function latestSubmissions(AssessmentCycle $cycle, array $organizationIds): Collection
    {
        return AssessmentInstitutionSubmission::query()->where('assessment_cycle_id', $cycle->id)->whereIn('organization_id', $organizationIds)
            ->orderBy('revision_no')->get()->keyBy('organization_id');
    }

    /** Institution counts by derived/workflow status for the city dashboard (SQL, no per-row hydration of employees). */
    public function institutionStatusCounts(AssessmentCycle $cycle, OversightScope $scope, array $filters = []): array
    {
        $all = $this->institutions($cycle, $scope, $filters, 10000);
        $cycleBlocked = collect($this->quality->cycleIssues($cycle))->where('severity', AssessmentDataQualityService::BLOCKING)->isNotEmpty();
        $counts = array_fill_keys(['expected', 'not_started', 'in_progress', 'ready_for_submission', 'submitted', 'returned', 'verified', 'finalized', 'rejected', 'outdated', 'started', 'completed', 'overdue'], 0);
        foreach ($all->items() as $row) {
            $counts['expected']++;
            $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
            if ($row['status'] !== 'not_started') {
                $counts['started']++;
            }
            // Every eligible employee has a final outcome (assessed, approved exception or reported reason).
            if ($row['eligible'] > 0 && $row['not_started'] + $row['in_progress'] + $row['awaiting_review'] === 0 && $row['issues']['blocking'] === 0 && ! $cycleBlocked) {
                $counts['completed']++;
            }
            if ($row['deadline_status'] === 'overdue') {
                $counts['overdue']++;
            }
        }

        return $counts;
    }
}
