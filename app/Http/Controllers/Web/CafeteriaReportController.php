<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Cafeteria\GenerateDailyReportAction;
use App\Actions\Cafeteria\GenerateMonthlyReportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateCafeteriaReportRequest;
use App\Http\Resources\CafeteriaReportRunResource;
use App\Models\CafeteriaReportRun;
use App\Models\Organization;
use App\Services\Cafeteria\CafeteriaProviderAccessService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CafeteriaReportController extends Controller
{
    public function __construct(private readonly CafeteriaProviderAccessService $providerAccess) {}

    public function index(Request $request, OrganizationScopeService $scope): Response
    {
        $this->authorize('viewAny', CafeteriaReportRun::class);

        $request->validate(['type' => ['nullable', 'in:daily,weekly,monthly'], 'organization_id' => ['nullable', 'uuid'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = CafeteriaReportRun::query()
            ->with('organization:id,name_en,name_am')
            ->when($request->string('type')->toString(), fn ($q, $v) => $q->where('report_type', $v))
            ->when($request->string('organization_id')->toString(), fn ($q, $v) => $q->where('organization_id', $v))
            ->when($this->providerAccess->accessibleProviderIds($request->user()) !== [], function ($query) use ($request): void {
                $providerIds = $this->providerAccess->accessibleProviderIds($request->user());
                $query->where(function ($nested) use ($providerIds): void {
                    foreach ($providerIds as $providerId) {
                        $nested->orWhereJsonContains('filters->provider_ids', $providerId)
                            ->orWhereJsonContains('filters->provider_id', $providerId);
                    }
                });
            })
            ->orderByDesc('generated_at')->orderByDesc('id');
        // Saved totals cannot be redacted: require access to every provider in the snapshot.
        $visible = $query->get()->filter(fn ($report) => $request->user()->can('view', $report))->values();
        $page = (int) $request->input('page', 1);
        $reports = new LengthAwarePaginator($visible->forPage($page, 20)->values(), $visible->count(), 20, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return Inertia::render('Cafeteria/Reports/Index', [
            'reports' => CafeteriaReportRunResource::collection($reports)->resolve(),
            'meta' => [
                'current_page' => $reports->currentPage(),
                'last_page' => $reports->lastPage(),
                'total' => $reports->total(),
            ],
            'filters' => $request->only(['type', 'organization_id']),
            'organizations' => $scope->applyOrganizationScope(Organization::query(), $request->user(), 'id')->orderBy('name_en')->get(['id', 'name_en', 'name_am']),
            'requiresOrganization' => ! $scope->isUnrestricted($request->user()),
            'can' => [
                'generate' => $request->user()?->can('generate', CafeteriaReportRun::class) ?? false,
            ],
        ]);
    }

    public function show(CafeteriaReportRun $cafeteriaReport): Response
    {
        $this->authorize('view', $cafeteriaReport);

        return Inertia::render('Cafeteria/Reports/Show', [
            'report' => (new CafeteriaReportRunResource($cafeteriaReport))->resolve(),
        ]);
    }

    public function generate(GenerateCafeteriaReportRequest $request, GenerateDailyReportAction $daily, GenerateMonthlyReportAction $monthly): RedirectResponse
    {
        $from = Carbon::parse($request->validated('period_start'));
        $type = $request->validated('report_type');
        $orgId = $request->validated('organization_id') ?: null;
        if ($orgId !== null) {
            abort_unless(app(OrganizationScopeService::class)->canAccessOrganization($request->user(), $orgId), 403);
        }
        $expectedEnd = $type === 'daily' ? $from->copy() : $from->copy()->endOfMonth();
        if ($request->filled('period_end') && $request->validated('period_end') !== $expectedEnd->toDateString()) {
            throw ValidationException::withMessages(['period_end' => __('cafeteria.reportPeriodMismatch')]);
        }

        $report = match ($type) {
            'daily' => $daily->execute($from, $request->user(), $orgId, $request),
            'monthly' => $monthly->execute($from->year, $from->month, $request->user(), $orgId, $request),
        };

        return to_route('cafeteria.reports.show', $report)
            ->with('flash', ['message' => __('cafeteria.reportGenerated'), 'type' => 'success']);
    }
}
