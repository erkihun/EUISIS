<?php

declare(strict_types=1);

namespace App\Http\Controllers\Grievances;

use App\Enums\AuditEventType;
use App\Exports\Grievances\GrievanceReportExport;
use App\Http\Controllers\Controller;
use App\Models\Grievance;
use App\Models\GrievanceAppeal;
use App\Models\GrievanceCategory;
use App\Models\Organization;
use App\Services\Calendar\LocalizedDateService;
use App\Services\Grievances\GrievanceAudit;
use App\Services\Grievances\GrievanceCaseAccessService;
use App\Services\Grievances\GrievancePresenter;
use App\Services\Grievances\GrievanceReportService;
use App\Services\Grievances\GrievanceSettings;
use App\Services\OrganizationScope\OrganizationScopeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Aggregate grievance reports (no employee ranking), CSV/Excel/PDF export, and the appeals list. */
class GrievanceReportController extends Controller
{
    public function __construct(
        private readonly GrievanceReportService $reports,
        private readonly GrievanceSettings $settings,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('grievance_reports.view'), 403);
        $filters = $this->filters($request);
        $report = in_array($request->string('report')->value(), GrievanceReportService::REPORTS, true) ? $request->string('report')->value() : 'summary';

        return Inertia::render('Grievances/Reports/Index', [
            'report' => $report,
            'reports' => GrievanceReportService::REPORTS,
            'result' => $this->reports->run($report, $user, $filters),
            'filters' => $filters,
            'minGroupSize' => $this->settings->reportMinGroupSize(),
            'options' => [
                'organizations' => $this->scope->applyOrganizationScope(Organization::query(), $user, 'id')->where('status', 'active')->orderBy('name_en')->limit(2000)->get(['id', 'name_en', 'name_am']),
                'categories' => GrievanceCategory::query()->orderBy('name_en')->get(['id', 'name_en', 'name_am']),
            ],
            'can' => ['export' => $user->can('grievance_reports.export')],
        ]);
    }

    public function export(Request $request, GrievanceAudit $audit, LocalizedDateService $dates): HttpResponse
    {
        $user = $request->user();
        abort_unless($user->can('grievance_reports.export'), 403);
        $data = $request->validate([
            'report' => ['required', 'in:'.implode(',', GrievanceReportService::REPORTS)],
            'format' => ['required', 'in:csv,xlsx,pdf'],
        ]);
        $filters = $this->filters($request);
        $result = $this->reports->run($data['report'], $user, $filters);
        $labels = collect($result['columns'])->mapWithKeys(fn ($c) => [$c => __("grievances.reports.columns.{$c}") === "grievances.reports.columns.{$c}" ? $c : __("grievances.reports.columns.{$c}")])->all();
        $export = new GrievanceReportExport($result, $labels);
        $filename = 'grievance-'.str_replace('_', '-', $data['report']).'-'.now()->format('Ymd-His');

        $audit->record(AuditEventType::GrievanceReportExported, $user, $user, ['report' => $data['report'], 'format' => $data['format'], 'rows' => count($result['rows']), 'filters' => $filters]);

        return match ($data['format']) {
            'csv' => Excel::download($export, $filename.'.csv', \Maatwebsite\Excel\Excel::CSV),
            'xlsx' => Excel::download($export, $filename.'.xlsx'),
            'pdf' => Pdf::loadView('grievances.report-pdf', [
                'title' => __("grievances.reports.names.{$data['report']}"),
                'headings' => $export->headings(),
                'rows' => $export->array(),
                'period' => __('grievances.reports.period', ['from' => $filters['from'] ? $dates->displayDate($filters['from']) : '—', 'to' => $filters['to'] ? $dates->displayDate($filters['to']) : '—']),
                'generated' => __('grievances.reports.generated', ['at' => $dates->displayDateTime(now()), 'user' => $user->name]),
                'suppressed' => $result['suppressed'],
                'suppressedNote' => __('grievances.reports.suppressed', ['count' => $result['suppressed'], 'min' => $this->settings->reportMinGroupSize()]),
                'note' => __('grievances.reports.note'),
            ])->setPaper('a4', 'landscape')->download($filename.'.pdf'),
        };
    }

    /** Appeals register for staff: appeals on cases the user may open. */
    public function appeals(Request $request, GrievanceCaseAccessService $access, GrievancePresenter $presenter): Response
    {
        $user = $request->user();
        abort_unless(collect(GrievanceCaseController::STAFF_PERMISSIONS)->contains(fn ($p) => $user->can($p)) || $user->isSuperAdmin(), 403);

        $page = GrievanceAppeal::query()
            ->whereIn('grievance_id', $access->constrainAuthorized(Grievance::query(), $user)->select('grievances.id'))
            ->with(['grievance:id,reference_number,status,category_id,organization_id', 'grievance.category:id,name_en,name_am', 'fromStage', 'toStage'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->string('status')))
            ->latest('filed_at')->paginate(20)->withQueryString();
        $page->through(fn (GrievanceAppeal $a) => [
            'id' => $a->getKey(),
            'grievance_id' => $a->grievance_id,
            'reference_number' => $a->grievance?->reference_number,
            'case_status' => $a->grievance?->status?->value,
            'category' => $a->grievance?->category?->only(['id', 'name_en', 'name_am']),
            'status' => $a->status->value,
            'filed_at' => $a->filed_at?->toIso8601String(),
            'deadline_at' => $a->deadline_at?->toIso8601String(),
            'from' => $a->fromStage ? $presenter->handler($a->fromStage->handler_type->value, $a->fromStage->handler_id) : null,
            'to' => $a->toStage ? $presenter->handler($a->toStage->handler_type->value, $a->toStage->handler_id) : null,
        ]);

        return Inertia::render('Grievances/Appeals/Index', ['appeals' => $page, 'filters' => $request->only('status')]);
    }

    /** @return array{from: string|null, to: string|null, organization_id: string|null, category_id: string|null} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'organization_id' => ['nullable', 'uuid'],
            'category_id' => ['nullable', 'uuid'],
        ]);

        return ['from' => $data['from'] ?? null, 'to' => $data['to'] ?? null, 'organization_id' => $data['organization_id'] ?? null, 'category_id' => $data['category_id'] ?? null];
    }
}
