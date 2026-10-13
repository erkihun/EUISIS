<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Http\Controllers\Controller;
use App\Jobs\OrganizationDesign\ExportOrganizationDesignReport;
use App\Models\OrganizationDesignReportExport;
use App\Services\OrganizationDesign\OrganizationDesignReportService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Organization-design reports: tabular views within the user's scope, queued CSV/XLSX exports, private downloads. */
class ReportController extends Controller
{
    public function __construct(private readonly OrganizationDesignReportService $reports, private readonly OrganizationScopeService $scope) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', OrganizationDesignReportExport::class);
        $user = $request->user();
        $filters = $this->filters($request);
        $report = in_array($request->string('report')->toString(), OrganizationDesignReportService::REPORTS, true) ? $request->string('report')->toString() : 'coverage';

        return Inertia::render('OrganizationDesign/Reports/Index', [
            'report' => $report,
            'reports' => OrganizationDesignReportService::REPORTS,
            'result' => $this->reports->run($report, $user, $filters),
            'labels' => collect(OrganizationDesignReportService::COLUMNS[$report])->mapWithKeys(fn (string $c) => [$c => __("organization-design.reports.columns.{$c}")]),
            'filters' => $filters,
            'organizations' => $this->reports->organizations($user, null)->map->only(['id', 'code', 'name_en', 'name_am'])->values(),
            'exports' => OrganizationDesignReportExport::query()->where('requested_by', $user->getKey())->with('organization:id,code,name_en,name_am')->latest('created_at')->limit(20)->get()
                ->map(fn (OrganizationDesignReportExport $e) => [
                    'id' => $e->id, 'report' => $e->report, 'format' => $e->format, 'status' => $e->status, 'filename' => $e->filename, 'row_count' => $e->row_count, 'error' => $e->error,
                    'organization' => $e->organization?->only(['id', 'code', 'name_en', 'name_am']), 'created_at' => $e->created_at?->toISOString(), 'completed_at' => $e->completed_at?->toISOString(),
                ]),
            'can' => ['export' => $user->can('create', OrganizationDesignReportExport::class)],
        ]);
    }

    public function export(Request $request): RedirectResponse
    {
        $this->authorize('create', OrganizationDesignReportExport::class);
        $user = $request->user();
        $data = $request->validate([
            'report' => ['required', Rule::in(OrganizationDesignReportService::REPORTS)],
            'format' => ['required', Rule::in(['csv', 'xlsx'])],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
        ]);
        $organizationId = $data['organization_id'] ?? null;
        abort_unless($organizationId === null || $this->scope->canAccess($user, $organizationId), 403);

        $export = OrganizationDesignReportExport::query()->create([
            'organization_id' => $organizationId, 'report' => $data['report'], 'format' => $data['format'], 'filters' => [], 'status' => 'queued', 'disk' => 'local', 'requested_by' => $user->getKey(),
        ]);
        ExportOrganizationDesignReport::dispatch($export);

        return to_route('organization-design.reports.index', ['report' => $data['report'], 'organization_id' => $organizationId])
            ->with('flash', ['message' => __('organization-design.reports.export_queued'), 'type' => 'success']);
    }

    public function download(Request $request, OrganizationDesignReportExport $export): HttpResponse
    {
        $this->authorize('download', $export);
        abort_unless($export->isReady() && Storage::disk($export->disk)->exists($export->path), 404);
        $mime = $export->format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return Storage::disk($export->disk)->download($export->path, $export->filename ?? ($export->id.'.'.$export->format), ['Content-Type' => $mime, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @return array{organization_id: string|null} */
    private function filters(Request $request): array
    {
        $data = $request->validate(['organization_id' => ['nullable', 'uuid']]);

        return ['organization_id' => $data['organization_id'] ?? null];
    }
}
