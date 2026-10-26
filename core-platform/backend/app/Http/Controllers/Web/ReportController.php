<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\ManageExports;
use App\Http\Controllers\Controller;
use App\Models\ExportRun;
use App\Models\HierarchyVersion;
use App\Models\JobGrade;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\Profession;
use App\Models\User;
use App\Reports\ReportCatalog;
use App\Reports\ReportRunner;
use App\Services\OrganizationScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Core/Reports (ADR 0011): the reports this reader may open, one report's
 * filters and a paged preview (the same scoped query an export runs), and
 * the reader's own exports.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportCatalog $catalog, private readonly OrganizationScope $scope) {}

    public function index(Request $request, ReportRunner $runner): Response
    {
        /** @var User $user */
        $user = $request->user();
        $available = $this->catalog->available($user);
        $report = $request->string('report')->toString() ?: null;
        abort_if($report !== null && ! in_array($report, $available, true), 403);
        $definition = $report === null ? null : ReportCatalog::definitions()[$report];
        $filters = $definition === null ? [] : $request->validate(array_fill_keys(array_map(fn (string $f): string => 'filters.'.$f, $definition['filters']), ['nullable', 'string', 'max:64']))['filters'] ?? [];
        $filters = array_filter((array) $filters, fn ($v) => $v !== null && $v !== '');
        $locale = app()->getLocale() === 'am' ? 'am' : 'en';
        $ids = $this->scope->organizationIds($user);

        return Inertia::render('Core/Reports', [
            'reports' => array_map(fn (string $key): array => ['key' => $key, 'citywide' => ReportCatalog::definitions()[$key]['citywide']], $available),
            'report' => $report,
            'definition' => $definition === null ? null : [
                'filters' => $definition['filters'],
                'columns' => collect($runner->columns($report, $user, null, true))->map(fn (array $c, string $k): array => [
                    'key' => $k, 'label' => $locale === 'am' ? $c[1] : $c[0], 'sensitive' => (bool) ($c[2] ?? false)])->values(),
            ],
            'filters' => (object) $filters,
            'preview' => $report === null ? null : $runner->preview($report, $user, $filters, $locale),
            'options' => $report === null ? null : [
                'organizations' => Organization::query()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name_en')->limit(500)->get(['id', 'name_en', 'name_am']),
                'hierarchy_versions' => HierarchyVersion::query()->orderByDesc('created_at')->limit(50)->get(['id', 'version_name', 'status']),
                'job_grades' => JobGrade::query()->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name_en', 'name_am']),
                'occupations' => Occupation::query()->orderBy('sort_order')->orderBy('code')->limit(500)->get(['id', 'code', 'name_en', 'name_am']),
                'professions' => Profession::query()->orderBy('sort_order')->orderBy('code')->limit(500)->get(['id', 'code', 'name_en', 'name_am']),
            ],
            'can' => ['export' => $user->can('reports.export'), 'sensitive' => $user->can('reports.export_sensitive')],
            'exports' => ExportRun::query()->where('requested_by', $user->getKey())->orderByDesc('created_at')->limit(20)->get()
                ->map(fn (ExportRun $run): array => [
                    'id' => $run->id, 'report' => $run->report, 'format' => $run->format, 'status' => $run->status, 'row_count' => $run->row_count,
                    'error' => $run->error, 'created_at' => $run->created_at?->toIso8601String(), 'expires_at' => $run->expires_at?->toIso8601String(),
                    'include_sensitive' => $run->include_sensitive,
                    'download_url' => $run->status === 'completed' && ($run->expires_at === null || $run->expires_at->isFuture()) ? route('core.reports.download', $run) : null,
                ])->values(),
            'export_url' => $report === null ? null : route('core.reports.export', ['report' => $report]),
            'url' => route('core.reports'),
        ]);
    }

    public function export(Request $request, string $report, ManageExports $exports): RedirectResponse
    {
        $exports->request($report, $request->only(['format', 'columns', 'include_sensitive', 'purpose', 'filters']), $request->user());

        return back()->with('success', __('oversight.export_queued'));
    }

    public function download(Request $request, ExportRun $exportRun, ManageExports $exports): StreamedResponse
    {
        return $exports->download($exportRun, $request->user());
    }
}
