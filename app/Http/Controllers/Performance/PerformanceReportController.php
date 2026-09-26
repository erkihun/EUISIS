<?php

declare(strict_types=1);

namespace App\Http\Controllers\Performance;

use App\Enums\AuditEventType;
use App\Enums\Performance\AgreementStatus;
use App\Enums\Performance\PlanStatus;
use App\Enums\Performance\PlanType;
use App\Models\EmployeePerformanceAgreement;
use App\Models\EmployeePerformanceItem;
use App\Models\IndividualDevelopmentPlan;
use App\Models\KpiActual;
use App\Models\KpiTarget;
use App\Models\PerformanceAppeal;
use App\Models\PerformanceCalibrationItem;
use App\Models\PerformanceCycle;
use App\Models\PerformanceImprovementPlan;
use App\Models\PerformancePlan;
use App\Models\PerformancePlanScore;
use App\Models\PerformanceResult;
use App\Models\PerformanceReview;
use App\Models\PerformanceScoreAdjustment;
use App\Models\PerformanceTargetAmendment;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\Performance\EpmsAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Scoped performance reports. Paginated on screen; the CSV export streams
 * the same query in chunks (no full load into memory) and never includes rows
 * outside the viewer's scope. Organization, unit and KPI names follow the
 * viewer's language; status columns carry their codes, which the page labels.
 * PDF/Excel exports are future work (the CSV opens in Excel).
 */
class PerformanceReportController extends PerformanceController
{
    /** Report => its columns, in display and export order. */
    private const REPORTS = [
        'results' => ['employee_number', 'employee', 'results_score', 'competency_score', 'final_score', 'rating_en', 'rating_am', 'status'],
        'distribution' => ['rating_en', 'rating_am', 'count'],
        'plan_scores' => ['plan', 'type', 'organization', 'unit', 'version', 'score', 'as_of'],
        'plan_targets' => ['plan', 'objective', 'kpi_code', 'kpi', 'target', 'weight', 'period_start', 'period_end'],
        'agreement_completion' => ['organization', 'unit', 'status', 'count'],
        'review_completion' => ['organization', 'unit', 'review_type', 'status', 'count'],
        'checkins' => ['employee_number', 'employee', 'unit', 'status', 'checkins', 'last_checkin'],
        'missing_actuals' => ['employee_number', 'employee', 'kpi_code', 'kpi', 'weight'],
        'pending_verification' => ['employee_number', 'employee', 'kpi_code', 'kpi', 'period_start', 'period_end', 'value', 'source'],
        'amendments' => ['subject', 'kpi_code', 'reason', 'effective_date', 'status', 'requested_at'],
        'adjustments' => ['employee_number', 'employee', 'adjustment_type', 'original_score', 'adjusted_score', 'reason', 'status', 'requested_at'],
        'calibration' => ['session', 'employee_number', 'employee', 'manager_score', 'calibrated_score', 'reason'],
        'appeals' => ['appeal_no', 'employee_number', 'employee', 'status', 'decision', 'decided_score', 'submitted_at'],
        'improvement_plans' => ['employee_number', 'employee', 'start_date', 'end_date', 'status'],
        'development_plans' => ['employee_number', 'employee', 'development_objective', 'due_date', 'status'],
    ];

    /** How the page shows a column: `enum:<group>` (a translated status), `date` or `score`; anything else is text. */
    private const FORMATS = [
        'results' => ['results_score' => 'score', 'competency_score' => 'score', 'final_score' => 'score', 'status' => 'enum:result'],
        'plan_scores' => ['type' => 'enum:planType', 'score' => 'score', 'as_of' => 'date'],
        'plan_targets' => ['weight' => 'score', 'period_start' => 'date', 'period_end' => 'date'],
        'agreement_completion' => ['status' => 'enum:agreement'],
        'review_completion' => ['review_type' => 'enum:reviewType', 'status' => 'enum:review'],
        'checkins' => ['status' => 'enum:agreement', 'last_checkin' => 'date'],
        'missing_actuals' => ['weight' => 'score'],
        'pending_verification' => ['period_start' => 'date', 'period_end' => 'date', 'source' => 'enum:source'],
        'amendments' => ['subject' => 'enum:subjectType', 'effective_date' => 'date', 'status' => 'enum:approval', 'requested_at' => 'date'],
        'adjustments' => ['adjustment_type' => 'enum:adjustmentType', 'original_score' => 'score', 'adjusted_score' => 'score', 'status' => 'enum:approval', 'requested_at' => 'date'],
        'calibration' => ['manager_score' => 'score', 'calibrated_score' => 'score'],
        'appeals' => ['status' => 'enum:appeal', 'decision' => 'enum:decision', 'decided_score' => 'score', 'submitted_at' => 'date'],
        'improvement_plans' => ['start_date' => 'date', 'end_date' => 'date', 'status' => 'enum:development'],
        'development_plans' => ['due_date' => 'date', 'status' => 'enum:development'],
    ];

    /** @var array<string, ?string> amended target/item id => KPI code, loaded per page or chunk */
    private array $amendedKpiCodes = [];

    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly EpmsAudit $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $request->user();
        abort_unless($user->can('performance_reports.view'), 403);
        $report = $this->reportName($request);

        $rows = $this->query($report, $request)->paginate(50)->withQueryString();
        $this->prepare($report, $rows->getCollection());

        return Inertia::render('Performance/Reports/Index', [
            'reports' => array_keys(self::REPORTS),
            'report' => $report,
            'filters' => ['cycle_id' => $this->cycleId($request)],
            'cycles' => PerformanceCycle::query()
                ->where(fn ($q) => $q->whereNull('organization_id')->orWhereIn('organization_id', $this->scope->allowedOrganizationIds($user)))
                ->orderByDesc('start_date')->limit(20)->get(['id', 'name_en', 'name_am'])->toArray(),
            'rows' => $rows->through(fn ($row) => $this->row($report, $row)),
            'columns' => self::REPORTS[$report],
            'formats' => self::FORMATS[$report] ?? [],
            'can' => ['export' => $user->can('performance_reports.export')],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->ensureEnabled();
        abort_unless($request->user()->can('performance_reports.export'), 403);
        $report = $this->reportName($request);
        $query = $this->query($report, $request);
        $columns = self::REPORTS[$report];

        $this->audit->record(AuditEventType::ExportPerformed, $request->user(), $request->user(), ['report' => 'performance.'.$report, 'cycle_id' => $this->cycleId($request)]);

        return response()->streamDownload(function () use ($query, $columns, $report): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Amharic
            fputcsv($out, $columns);
            $query->chunk(500, function (Collection $rows) use ($out, $report): void {
                $this->prepare($report, $rows);
                foreach ($rows as $row) {
                    fputcsv($out, array_map(fn ($v) => is_scalar($v) || $v === null ? $this->safeCell($v) : json_encode($v), array_values($this->row($report, $row))));
                }
            });
            fclose($out);
        }, "performance-{$report}-".now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function query(string $report, Request $request): Builder
    {
        /** @var User $user */
        $user = $request->user();
        $cycleId = $this->cycleId($request);
        $scoped = fn (Builder $q, string $column = 'organization_id') => $this->scope->applyOrganizationScope($q, $user, $column);
        $inCycle = fn (Builder $q) => $q->when($cycleId, fn ($q, $v) => $q->where('cycle_id', $v));
        $employee = 'employee:id,full_name,name_en,employee_number';

        return match ($report) {
            'results' => $inCycle($scoped(PerformanceResult::query()))->where('is_current', true)
                ->with([$employee])->orderBy('organization_id')->orderBy('id'),
            'distribution' => $inCycle($scoped(PerformanceResult::query()))->where('is_current', true)
                ->whereIn('status', ['FINALIZED', 'PENDING_RELEASE', 'RELEASED'])
                ->selectRaw('rating_label_en, rating_label_am, count(*) as total')->groupBy('rating_label_en', 'rating_label_am')->orderBy('rating_label_en')->orderBy('rating_label_am'),
            'plan_scores' => $inCycle($scoped(PerformancePlan::query()))->where('status', PlanStatus::Published->value)
                ->whereIn('plan_type', [PlanType::Organization->value, PlanType::Unit->value])
                ->select('performance_plans.*')
                ->addSelect([
                    'latest_score' => PerformancePlanScore::query()->select('score')->whereColumn('performance_plan_id', 'performance_plans.id')->orderByDesc('as_of')->limit(1),
                    'latest_as_of' => PerformancePlanScore::query()->select('as_of')->whereColumn('performance_plan_id', 'performance_plans.id')->orderByDesc('as_of')->limit(1),
                ])
                ->with(['organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am'])->orderBy('plan_type')->orderBy('title')->orderBy('id'),
            'plan_targets' => KpiTarget::query()->where('is_current', true)
                ->whereHas('plan', fn ($plan) => $inCycle($scoped($plan))->where('status', PlanStatus::Published->value))
                ->with(['plan:id,title', 'objective:id,code', 'kpi:id,code,name_en,name_am,unit_of_measure'])
                ->orderBy('performance_plan_id')->orderBy('objective_id')->orderBy('id'),
            'agreement_completion' => $inCycle($scoped(EmployeePerformanceAgreement::query()))
                ->selectRaw('organization_id, organization_unit_id, status, count(*) as total')->groupBy('organization_id', 'organization_unit_id', 'status')
                ->orderBy('organization_id')->orderBy('organization_unit_id')->orderBy('status')
                ->with(['organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am']),
            'review_completion' => $scoped(PerformanceReview::query()->join('employee_performance_agreements as a', 'a.id', '=', 'performance_reviews.agreement_id'), 'a.organization_id')
                ->leftJoin('organizations as o', 'o.id', '=', 'a.organization_id')
                ->leftJoin('organization_units as u', 'u.id', '=', 'a.organization_unit_id')
                ->when($cycleId, fn ($q, $v) => $q->where('a.cycle_id', $v))
                ->selectRaw('a.organization_id, a.organization_unit_id, o.name_en as organization_en, o.name_am as organization_am, u.name_en as unit_en, u.name_am as unit_am, performance_reviews.review_type, performance_reviews.status, count(*) as total')
                ->groupBy('a.organization_id', 'a.organization_unit_id', 'o.name_en', 'o.name_am', 'u.name_en', 'u.name_am', 'performance_reviews.review_type', 'performance_reviews.status')
                ->orderBy('a.organization_id')->orderBy('a.organization_unit_id')->orderBy('performance_reviews.review_type')->orderBy('performance_reviews.status'),
            // Live agreements, the ones with the fewest check-ins first.
            'checkins' => $inCycle($scoped(EmployeePerformanceAgreement::query()))
                ->whereIn('status', [AgreementStatus::Agreed->value, AgreementStatus::Active->value, AgreementStatus::UnderReview->value])
                ->withCount('checkins')->withMax('checkins', 'checkin_date')
                ->with([$employee, 'organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am'])
                ->orderBy('checkins_count')->orderBy('id'),
            'missing_actuals' => EmployeePerformanceItem::query()->where('is_current', true)
                ->whereHas('agreement', fn ($a) => $inCycle($scoped($a))->whereIn('status', [AgreementStatus::Active->value, AgreementStatus::UnderReview->value]))
                ->whereDoesntHave('actuals')->with(['agreement.'.$employee, 'kpi:id,code,name_en,name_am'])->orderBy('agreement_id')->orderBy('id'),
            // Entered measurements nobody has verified yet (roll-ups are computed, never verified).
            'pending_verification' => $scoped(KpiActual::query())->where('verified', false)->where('source_key', '!=', 'aggregate')
                ->when($cycleId, fn ($q, $v) => $q->where(fn ($w) => $w
                    ->whereIn('agreement_id', EmployeePerformanceAgreement::query()->where('cycle_id', $v)->select('id'))
                    ->orWhereIn('performance_plan_id', PerformancePlan::query()->where('cycle_id', $v)->select('id'))))
                ->with(['agreement.'.$employee, 'kpi:id,code,name_en,name_am'])->orderBy('period_end')->orderBy('id'),
            'amendments' => PerformanceTargetAmendment::query()
                ->where(fn ($q) => $q
                    ->where(fn ($t) => $t->where('subject_type', 'TARGET')->whereIn('subject_id', KpiTarget::query()->select('id')
                        ->whereIn('performance_plan_id', $inCycle($scoped(PerformancePlan::query()))->select('id'))))
                    ->orWhere(fn ($i) => $i->where('subject_type', 'ITEM')->whereIn('subject_id', EmployeePerformanceItem::query()->select('id')
                        ->whereIn('agreement_id', $inCycle($scoped(EmployeePerformanceAgreement::query()))->select('id')))))
                ->orderByDesc('created_at')->orderBy('id'),
            'adjustments' => PerformanceScoreAdjustment::query()
                ->whereHas('result', fn ($r) => $inCycle($scoped($r)))
                ->with(['result.'.$employee])->orderByDesc('created_at')->orderBy('id'),
            'calibration' => PerformanceCalibrationItem::query()->whereHas('session', fn ($s) => $inCycle($scoped($s)))
                ->with([$employee, 'session:id,title'])->orderBy('session_id')->orderBy('id'),
            'appeals' => $inCycle($scoped(PerformanceAppeal::query()))->with($employee)->orderByDesc('submitted_at')->orderBy('id'),
            'improvement_plans' => $scoped(PerformanceImprovementPlan::query())
                ->when($cycleId, fn ($q, $v) => $q->whereHas('agreement', fn ($a) => $a->where('cycle_id', $v)))
                ->with($employee)->orderByDesc('start_date')->orderBy('id'),
            'development_plans' => IndividualDevelopmentPlan::query()
                ->whereIn('agreement_id', $inCycle($scoped(EmployeePerformanceAgreement::query()))->select('id'))
                ->with($employee)->orderByDesc('created_at')->orderBy('id'),
        };
    }

    /** Batch lookups a page or chunk of rows needs, so rows never query one by one. */
    private function prepare(string $report, Collection $rows): void
    {
        if ($report !== 'amendments') {
            return;
        }

        $ids = fn (string $type) => $rows->filter(fn ($row) => $row->subject_type === $type)->pluck('subject_id')->unique()->values();
        $codes = fn (Collection $models) => $models->mapWithKeys(fn ($model) => [(string) $model->getKey() => $model->kpi?->code])->all();

        $this->amendedKpiCodes = $codes(KpiTarget::query()->with('kpi:id,code')->whereIn('id', $ids('TARGET'))->get())
            + $codes(EmployeePerformanceItem::query()->with('kpi:id,code')->whereIn('id', $ids('ITEM'))->get());
    }

    /** @return array<string, mixed> */
    private function row(string $report, mixed $r): array
    {
        $employee = fn (?Model $e): array => ['employee_number' => $e?->employee_number, 'employee' => $e?->full_name];

        return match ($report) {
            'results' => [...$employee($r->employee), 'results_score' => $r->results_score, 'competency_score' => $r->competency_score,
                'final_score' => $r->final_score, 'rating_en' => $r->rating_label_en, 'rating_am' => $r->rating_label_am, 'status' => $r->status->value],
            'distribution' => ['rating_en' => $r->rating_label_en, 'rating_am' => $r->rating_label_am, 'count' => (int) $r->total],
            'plan_scores' => ['plan' => $r->title, 'type' => $r->plan_type->value, 'organization' => $this->localized($r->organization),
                'unit' => $this->localized($r->organizationUnit), 'version' => $r->version_no, 'score' => $r->latest_score, 'as_of' => $this->day($r->latest_as_of)],
            'plan_targets' => ['plan' => $r->plan?->title, 'objective' => $r->objective?->code, 'kpi_code' => $r->kpi?->code, 'kpi' => $this->localized($r->kpi),
                'target' => $this->measure($r->target_value, $r->target_numerator, $r->target_denominator), 'weight' => $r->weight,
                'period_start' => $this->day($r->period_start), 'period_end' => $this->day($r->period_end)],
            'agreement_completion' => ['organization' => $this->localized($r->organization), 'unit' => $this->localized($r->organizationUnit), 'status' => $r->getRawOriginal('status'), 'count' => (int) $r->total],
            'review_completion' => ['organization' => $this->pick($r->organization_en, $r->organization_am), 'unit' => $this->pick($r->unit_en, $r->unit_am),
                'review_type' => $r->getRawOriginal('review_type'), 'status' => $r->getRawOriginal('status'), 'count' => (int) $r->total],
            'checkins' => [...$employee($r->employee), 'unit' => $this->localized($r->organizationUnit) ?? $this->localized($r->organization), 'status' => $r->status->value,
                'checkins' => (int) $r->checkins_count, 'last_checkin' => $this->day($r->checkins_max_checkin_date)],
            'missing_actuals' => [...$employee($r->agreement?->employee), 'kpi_code' => $r->kpi?->code, 'kpi' => $this->localized($r->kpi), 'weight' => $r->weight],
            'pending_verification' => [...$employee($r->agreement?->employee), 'kpi_code' => $r->kpi?->code, 'kpi' => $this->localized($r->kpi),
                'period_start' => $this->day($r->period_start), 'period_end' => $this->day($r->period_end),
                'value' => $r->milestone_key ?? $this->measure($r->actual_value, $r->actual_numerator, $r->actual_denominator), 'source' => $r->source_type?->value],
            'amendments' => ['subject' => $r->subject_type, 'kpi_code' => $this->amendedKpiCodes[(string) $r->subject_id] ?? null, 'reason' => $r->reason,
                'effective_date' => $this->day($r->effective_date), 'status' => $r->status->value, 'requested_at' => $this->day($r->created_at)],
            'adjustments' => [...$employee($r->result?->employee), 'adjustment_type' => $r->adjustment_type, 'original_score' => $r->original_score,
                'adjusted_score' => $r->adjusted_score, 'reason' => $r->reason, 'status' => $r->status->value, 'requested_at' => $this->day($r->created_at)],
            'calibration' => ['session' => $r->session?->title, ...$employee($r->employee), 'manager_score' => $r->manager_score, 'calibrated_score' => $r->calibrated_score, 'reason' => $r->reason],
            'appeals' => ['appeal_no' => $r->appeal_no, ...$employee($r->employee), 'status' => $r->status->value, 'decision' => $r->decision?->value, 'decided_score' => $r->decided_score, 'submitted_at' => $this->day($r->submitted_at)],
            'improvement_plans' => [...$employee($r->employee), 'start_date' => $this->day($r->start_date), 'end_date' => $this->day($r->end_date), 'status' => $r->status->value],
            'development_plans' => [...$employee($r->employee), 'development_objective' => $r->development_objective, 'due_date' => $this->day($r->due_date), 'status' => $r->status->value],
        };
    }

    private function reportName(Request $request): string
    {
        $report = $request->query('report');

        return is_string($report) && array_key_exists($report, self::REPORTS) ? $report : 'results';
    }

    private function cycleId(Request $request): ?string
    {
        $id = $request->query('cycle_id');

        return is_string($id) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1 ? $id : null;
    }

    /** A bilingual record's name in the viewer's language (English when there is no Amharic name). */
    private function localized(?Model $model): ?string
    {
        return $model === null ? null : $this->pick($model->getAttribute('name_en'), $model->getAttribute('name_am'));
    }

    private function pick(?string $en, ?string $am): ?string
    {
        return app()->getLocale() === 'am' && filled($am) ? $am : $en;
    }

    /** Y-m-d of a date, a datetime or a stored date string. */
    private function day(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }

    /** A value, or "numerator / denominator" for ratio targets and actuals. */
    private function measure(mixed $value, mixed $numerator, mixed $denominator): ?string
    {
        if ($numerator !== null && $denominator !== null) {
            return $numerator.' / '.$denominator;
        }

        return $value === null ? null : (string) $value;
    }

    /** Neutralize spreadsheet formula injection. */
    private function safeCell(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }
}
