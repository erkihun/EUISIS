<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\ExportPublicFeedbackReport;
use App\Models\PublicFeedbackReportExport;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\ServiceFeedback\PublicFeedbackMetrics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The public submissions report (brief §83–87): one filterable slice across
 * all three types with breakdowns, and queued, private exports of it.
 */
class PublicFeedbackReportController extends Controller
{
    public function __construct(
        private readonly PublicFeedbackMetrics $metrics,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PublicFeedbackReportExport::class);
        $user = $this->user();
        $filters = $this->metrics->filters($request->query());
        $query = $this->metrics->query($user, $filters);

        return Inertia::render('ServiceFeedback/SubmissionReports', [
            'filters' => $filters,
            'summary' => $this->metrics->overview($query),
            // Counts of reports received; never a ranking, a score or a finding.
            'tipOffs' => $this->metrics->tipOffs($query),
            'tipOffsByOrganization' => $this->metrics->tipOffsByOrganization($query)->all(),
            'employeeTargetedTipOffs' => $this->metrics->employeeTargetedTipOffs($query, $user, $filters)->all(),
            'byOrganization' => $this->metrics->byOrganization($query)->all(),
            'byHandler' => $this->metrics->byHandler($query)->all(),
            'byUnit' => $this->metrics->byUnit($query)->all(),
            'filterOptions' => $this->metrics->filterOptions($user),
            'exports' => PublicFeedbackReportExport::query()
                ->where('requested_by', $user->getKey())
                ->where('report', 'submissions')
                ->latest('created_at')
                ->limit(10)
                ->get()
                ->map(fn (PublicFeedbackReportExport $export): array => [
                    'id' => $export->id,
                    'format' => $export->format,
                    'status' => $export->status,
                    'filename' => $export->filename,
                    'row_count' => $export->row_count,
                    'error' => $export->error,
                    'filters' => $export->filters ?? [],
                    'created_at' => $export->created_at?->toIso8601String(),
                    'completed_at' => $export->completed_at?->toIso8601String(),
                ])->all(),
            'can' => ['export' => $user->can('create', PublicFeedbackReportExport::class)],
        ]);
    }

    public function export(Request $request): RedirectResponse
    {
        $this->authorize('create', PublicFeedbackReportExport::class);
        $user = $this->user();

        $data = $request->validate([
            'format' => ['required', Rule::in(['csv', 'xlsx'])],
        ]);
        $filters = $this->metrics->filters($request->all());
        $organizationId = $filters['organization_id'] ?? null;
        abort_unless($organizationId === null || $this->scope->isUnrestricted($user) || $this->scope->canAccessOrganization($user, $organizationId), 403);

        $export = PublicFeedbackReportExport::query()->create([
            'organization_id' => $organizationId,
            'format' => $data['format'],
            // The job has no request: it writes labels, names and dates in the language the export was asked for.
            'locale' => app()->getLocale(),
            'filters' => $filters,
            'status' => 'queued',
            'disk' => 'local',
            'requested_by' => $user->getKey(),
        ]);
        ExportPublicFeedbackReport::dispatch($export);

        return redirect()->route('service-feedback.submission-reports.index', $filters)
            ->with('success', __('service-feedback.export_queued'));
    }

    public function download(PublicFeedbackReportExport $export): StreamedResponse
    {
        $this->authorize('download', $export);
        abort_unless($export->isReady() && Storage::disk($export->disk)->exists($export->path), 404);

        $mime = $export->format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return Storage::disk($export->disk)->download($export->path, $export->filename ?? ($export->id.'.'.$export->format), [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
