<?php

declare(strict_types=1);

namespace App\Http\Controllers\Performance;

use App\Enums\AuditEventType;
use App\Enums\Performance\AgreementStatus;
use App\Models\EmployeePerformanceAgreement;
use App\Models\EmployeePerformanceItem;
use App\Models\PerformanceAppeal;
use App\Models\PerformanceCalibrationItem;
use App\Models\PerformanceCycle;
use App\Models\PerformanceImprovementPlan;
use App\Models\PerformanceResult;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\Performance\EpmsAccess;
use App\Services\Performance\EpmsAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Scoped performance reports. Paginated on screen; CSV export streams in
 * chunks (no full load into memory) and never includes rows outside the
 * viewer's scope. PDF/Excel exports are future work (CSV opens in Excel).
 */
class PerformanceReportController extends PerformanceController
{
    private const REPORTS = ['results', 'distribution', 'agreement_completion', 'missing_actuals', 'appeals', 'calibration', 'improvement_plans'];

    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly EpmsAccess $access,
        private readonly EpmsAudit $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $request->user();
        abort_unless($user->can('performance_reports.view'), 403);
        $report = in_array($request->query('report'), self::REPORTS, true) ? $request->query('report') : 'results';
        $cycleId = $request->query('cycle_id');

        return Inertia::render('Performance/Reports/Index', [
            'reports' => self::REPORTS,
            'report' => $report,
            'filters' => ['cycle_id' => $cycleId],
            'cycles' => PerformanceCycle::query()
                ->where(fn ($q) => $q->whereNull('organization_id')->orWhereIn('organization_id', $this->scope->allowedOrganizationIds($user)))
                ->orderByDesc('start_date')->limit(20)->get(['id', 'name_en', 'name_am'])->toArray(),
            'rows' => $this->query($report, $request)->paginate(50)->withQueryString()->through(fn ($row) => $this->row($report, $row)),
            'columns' => $this->columns($report),
            'can' => ['export' => $user->can('performance_reports.export')],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->ensureEnabled();
        abort_unless($request->user()->can('performance_reports.export'), 403);
        $report = in_array($request->query('report'), self::REPORTS, true) ? $request->query('report') : 'results';
        $query = $this->query($report, $request);
        $columns = $this->columns($report);

        $this->audit->record(AuditEventType::ExportPerformed, $request->user(), $request->user(), ['report' => 'performance.'.$report, 'cycle_id' => $request->query('cycle_id')]);

        return response()->streamDownload(function () use ($query, $columns, $report): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Amharic
            fputcsv($out, $columns);
            $query->chunk(500, function ($rows) use ($out, $report): void {
                foreach ($rows as $row) {
                    fputcsv($out, array_map(fn ($v) => is_scalar($v) || $v === null ? $this->safeCell($v) : json_encode($v), array_values($this->row($report, $row))));
                }
            });
            fclose($out);
        }, "performance-{$report}-".now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function query(string $report, Request $request): Builder
    {
        $user = $request->user();
        $cycleId = $request->query('cycle_id');
        $scoped = fn (Builder $q) => $this->scope->applyOrganizationScope($q, $user);

        return match ($report) {
            'results', 'distribution' => $scoped(PerformanceResult::query())->where('is_current', true)
                ->when($cycleId, fn ($q, $v) => $q->where('cycle_id', $v))
                ->when($report === 'distribution', fn ($q) => $q->whereIn('status', ['FINALIZED', 'PENDING_RELEASE', 'RELEASED'])
                    ->selectRaw('rating_label_en, rating_label_am, count(*) as total')->groupBy('rating_label_en', 'rating_label_am')->orderBy('rating_label_en'),
                    fn ($q) => $q->with(['employee:id,full_name,name_en,employee_number'])->orderBy('organization_id')->orderBy('id')),
            'agreement_completion' => $scoped(EmployeePerformanceAgreement::query())
                ->when($cycleId, fn ($q, $v) => $q->where('cycle_id', $v))
                ->selectRaw('organization_id, organization_unit_id, status, count(*) as total')->groupBy('organization_id', 'organization_unit_id', 'status')->orderBy('organization_id')
                ->with(['organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am']),
            'missing_actuals' => EmployeePerformanceItem::query()->where('is_current', true)
                ->whereHas('agreement', fn ($a) => $scoped($a->whereIn('status', [AgreementStatus::Active->value, AgreementStatus::UnderReview->value])->when($cycleId, fn ($q, $v) => $q->where('cycle_id', $v))))
                ->whereDoesntHave('actuals')->with(['agreement.employee:id,full_name,name_en,employee_number', 'kpi:id,code,name_en,name_am'])->orderBy('agreement_id'),
            'appeals' => $scoped(PerformanceAppeal::query())->when($cycleId, fn ($q, $v) => $q->where('cycle_id', $v))->with('employee:id,full_name,name_en,employee_number')->orderByDesc('submitted_at'),
            'calibration' => PerformanceCalibrationItem::query()->whereHas('session', fn ($s) => $scoped($s)->when($cycleId, fn ($q, $v) => $q->where('cycle_id', $v)))
                ->with(['employee:id,full_name,name_en,employee_number', 'session:id,title'])->orderBy('session_id'),
            'improvement_plans' => $scoped(PerformanceImprovementPlan::query())->with('employee:id,full_name,name_en,employee_number')->orderByDesc('start_date'),
        };
    }

    /** @return list<string> */
    private function columns(string $report): array
    {
        return match ($report) {
            'results' => ['employee_number', 'employee', 'results_score', 'competency_score', 'final_score', 'rating_en', 'rating_am', 'status'],
            'distribution' => ['rating_en', 'rating_am', 'count'],
            'agreement_completion' => ['organization', 'unit', 'status', 'count'],
            'missing_actuals' => ['employee_number', 'employee', 'kpi_code', 'kpi', 'weight'],
            'appeals' => ['appeal_no', 'employee_number', 'employee', 'status', 'decision', 'decided_score', 'submitted_at'],
            'calibration' => ['session', 'employee_number', 'employee', 'manager_score', 'calibrated_score', 'reason'],
            'improvement_plans' => ['employee_number', 'employee', 'start_date', 'end_date', 'status'],
        };
    }

    /** @return array<string, mixed> */
    private function row(string $report, mixed $r): array
    {
        return match ($report) {
            'results' => ['employee_number' => $r->employee?->employee_number, 'employee' => $r->employee?->full_name, 'results_score' => $r->results_score, 'competency_score' => $r->competency_score,
                'final_score' => $r->final_score, 'rating_en' => $r->rating_label_en, 'rating_am' => $r->rating_label_am, 'status' => $r->status->value],
            'distribution' => ['rating_en' => $r->rating_label_en, 'rating_am' => $r->rating_label_am, 'count' => (int) $r->total],
            'agreement_completion' => ['organization' => $r->organization?->name_en, 'unit' => $r->organizationUnit?->name_en, 'status' => $r->getRawOriginal('status'), 'count' => (int) $r->total],
            'missing_actuals' => ['employee_number' => $r->agreement?->employee?->employee_number, 'employee' => $r->agreement?->employee?->full_name, 'kpi_code' => $r->kpi?->code, 'kpi' => $r->kpi?->name_en, 'weight' => $r->weight],
            'appeals' => ['appeal_no' => $r->appeal_no, 'employee_number' => $r->employee?->employee_number, 'employee' => $r->employee?->full_name, 'status' => $r->status->value, 'decision' => $r->decision?->value, 'decided_score' => $r->decided_score, 'submitted_at' => $r->submitted_at?->toDateString()],
            'calibration' => ['session' => $r->session?->title, 'employee_number' => $r->employee?->employee_number, 'employee' => $r->employee?->full_name, 'manager_score' => $r->manager_score, 'calibrated_score' => $r->calibrated_score, 'reason' => $r->reason],
            'improvement_plans' => ['employee_number' => $r->employee?->employee_number, 'employee' => $r->employee?->full_name, 'start_date' => $r->start_date?->toDateString(), 'end_date' => $r->end_date?->toDateString(), 'status' => $r->status->value],
        };
    }

    /** Neutralize spreadsheet formula injection. */
    private function safeCell(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }
}
