<?php

declare(strict_types=1);

namespace App\Http\Controllers\Performance;

use App\Enums\AuditEventType;
use App\Enums\Performance\KpiAggregation;
use App\Enums\Performance\KpiDataSource;
use App\Enums\Performance\KpiDirection;
use App\Enums\Performance\KpiFrequency;
use App\Enums\Performance\KpiMeasurementType;
use App\Http\Requests\Performance\SaveKpiRequest;
use App\Models\Kpi;
use App\Models\Organization;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\Performance\EpmsAccess;
use App\Services\Performance\EpmsAudit;
use App\Services\Performance\SystemKpiSourceRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** KPI library. Global entries need an unrestricted role; organization entries need scope. */
class KpiController extends PerformanceController
{
    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly EpmsAccess $access,
        private readonly EpmsAudit $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $request->user();
        abort_unless($user->can('kpis.view'), 403);
        $search = trim((string) $request->query('search', ''));

        $kpis = Kpi::query()
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhereIn('organization_id', $this->scope->allowedOrganizationIds($user)))
            ->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('code', 'like', "%{$search}%")->orWhere('name_en', 'like', "%{$search}%")->orWhere('name_am', 'like', "%{$search}%")))
            ->when($request->query('active') !== null, fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('code')->paginate(25)->withQueryString();

        return Inertia::render('Performance/Kpis/Index', [
            'kpis' => $kpis->through(fn (Kpi $k) => [...$k->only(['id', 'code', 'name_en', 'name_am', 'description_en', 'description_am', 'organization_id', 'unit_of_measure', 'system_source_key', 'calculation_formula', 'baseline', 'allow_overachievement', 'achievement_cap', 'target_tolerance', 'zero_score_deviation', 'milestones', 'is_active']),
                'measurement_type' => $k->measurement_type->value, 'direction' => $k->direction->value, 'aggregation_method' => $k->aggregation_method->value,
                'data_source_type' => $k->data_source_type->value, 'frequency' => $k->frequency->value]),
            'filters' => ['search' => $search],
            'options' => [
                'measurement_types' => KpiMeasurementType::values(), 'directions' => KpiDirection::values(), 'aggregations' => KpiAggregation::values(),
                'sources' => KpiDataSource::values(), 'frequencies' => KpiFrequency::values(), 'system_sources' => SystemKpiSourceRegistry::sources(),
            ],
            'organizations' => $this->scope->applyOrganizationScope(Organization::query(), $user, 'id')->orderBy('name_en')->limit(200)->get(['id', 'name_en', 'name_am'])->toArray(),
            'can' => ['create' => $user->can('kpis.create'), 'update' => $user->can('kpis.update'), 'global' => $this->scope->isUnrestricted($user)],
        ]);
    }

    public function store(SaveKpiRequest $request): RedirectResponse
    {
        $this->ensureEnabled();
        $data = $request->validated();
        $this->authorizeOwner($request, 'kpis.create', $data['organization_id'] ?? null);

        $kpi = new Kpi($data);
        $kpi->forceFill(['created_by' => $request->user()->getKey()])->save();
        $this->audit->record(AuditEventType::KpiChanged, $request->user(), $kpi, ['created' => $kpi->code]);

        return $this->saved();
    }

    public function update(SaveKpiRequest $request, Kpi $kpi): RedirectResponse
    {
        $this->ensureEnabled();
        $this->authorizeOwner($request, 'kpis.update', $kpi->organization_id);
        $data = $request->validated();
        // Ownership cannot be moved to another organization by editing.
        unset($data['organization_id']);
        $old = $kpi->only(array_keys($data));
        $kpi->fill($data)->save();
        $this->audit->record(AuditEventType::KpiChanged, $request->user(), $kpi, $data, $old);

        return $this->saved();
    }

    private function authorizeOwner(Request $request, string $permission, ?string $organizationId): void
    {
        $user = $request->user();
        $this->access->authorize($organizationId === null
            ? $user->can($permission) && $this->scope->isUnrestricted($user)
            : $this->access->inScope($user, $permission, $organizationId));
    }
}
