<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Enums\OrganizationDesignStatus;
use App\Enums\ServiceBeneficiaryType;
use App\Enums\ServiceDeliveryChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationDesign\NewServiceVersionRequest;
use App\Http\Requests\OrganizationDesign\StoreServiceRequest;
use App\Http\Requests\OrganizationDesign\UpdateServiceRequest;
use App\Models\IsicActivity;
use App\Models\Organization;
use App\Models\OrganizationDesignStatusHistory;
use App\Models\OrganizationFunction;
use App\Models\OrganizationUnit;
use App\Models\Service;
use App\Models\ServiceClassification;
use App\Models\ServiceType;
use App\Services\OrganizationDesign\ServiceCatalogService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function __construct(private readonly ServiceCatalogService $services, private readonly OrganizationScopeService $scope) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Service::class);
        $user = $request->user();

        $query = Service::query()
            ->with(['organization:id,code,name_en,name_am', 'classification:id,code,name_en,name_am', 'ownerUnit:id,code,name_en,name_am'])
            ->withCount('functions')
            ->when($request->string('organization_id')->toString(), fn ($q, $id) => $q->where('organization_id', $id))
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->when($request->string('service_classification_id')->toString(), fn ($q, $id) => $q->where('service_classification_id', $id))
            ->when($request->string('search')->toString(), function ($q, $search): void {
                $q->where(function ($nested) use ($search): void {
                    $nested->where('code', ci_like_operator(), "%{$search}%")
                        ->orWhere('name_en', ci_like_operator(), "%{$search}%")
                        ->orWhere('name_am', ci_like_operator(), "%{$search}%");
                });
            })
            ->orderBy('code')->orderByDesc('version_no');
        $this->scope->applyOrganizationScope($query, $user);

        $services = $query->paginate(25)->withQueryString()->through(fn (Service $s) => $this->summary($s));

        return Inertia::render('OrganizationDesign/Services/Index', [
            'services' => $services,
            'filters' => $request->only(['organization_id', 'status', 'service_classification_id', 'search']),
            'organizations' => $this->organizationOptions($user),
            'classifications' => $this->classificationOptions(ServiceClassification::SCHEME_LOCAL),
            'cpcClassifications' => $this->classificationOptions(ServiceClassification::SCHEME_CPC),
            'serviceTypes' => ServiceType::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_am']),
            'statuses' => array_map(fn (OrganizationDesignStatus $s) => $s->value, OrganizationDesignStatus::cases()),
            'beneficiaryTypes' => ServiceBeneficiaryType::values(),
            'deliveryChannels' => ServiceDeliveryChannel::values(),
            'can' => ['create' => $user->can('create', Service::class)],
        ]);
    }

    public function store(StoreServiceRequest $request): RedirectResponse
    {
        $service = $this->services->createDraft($request->user(), $request->validated('organization_id'), $request->validated());

        return to_route('organization-design.services.show', $service)->with('flash', ['message' => __('organization-design.service_created'), 'type' => 'success']);
    }

    public function show(Request $request, Service $service): Response
    {
        $this->authorize('view', $service);
        $user = $request->user();
        $service->load(['organization:id,code,name_en,name_am', 'classification', 'cpcClassification', 'ownerUnit:id,code,name_en,name_am', 'serviceType:id,code,name_en,name_am', 'approver:id,name', 'creator:id,name', 'functions.mandate:id,code', 'isicActivities']);

        return Inertia::render('OrganizationDesign/Services/Show', [
            'service' => [
                ...$this->summary($service),
                'description_en' => $service->description_en, 'description_am' => $service->description_am,
                'legal_basis' => $service->legal_basis, 'service_type_id' => $service->service_type_id,
                'service_type' => $service->serviceType ? $service->serviceType->only(['id', 'code', 'name_en', 'name_am']) : null,
                'cpc_classification_id' => $service->cpc_classification_id,
                'cpc_classification' => $service->cpcClassification ? $service->cpcClassification->only(['id', 'code', 'revision', 'name_en', 'name_am']) : null,
                'owner_organization_unit_id' => $service->owner_organization_unit_id,
                'approved_by' => $service->approver?->name, 'approved_at' => $service->approved_at?->toISOString(),
                'created_by' => $service->creator?->name,
                'function_ids' => $service->functions->pluck('id')->all(),
                'isic_activity_ids' => $service->isicActivities->pluck('id')->all(),
            ],
            'functions' => $service->functions->map(fn (OrganizationFunction $f) => ['id' => $f->id, 'code' => $f->code, 'title_en' => $f->title_en, 'title_am' => $f->title_am, 'mandate_code' => $f->mandate?->code]),
            'isicActivities' => $service->isicActivities->map(fn (IsicActivity $a) => ['id' => $a->id, 'isic_code' => $a->isic_code, 'name_en' => $a->name_en, 'name_am' => $a->name_am, 'revision' => $a->revision]),
            'versions' => $service->versions()->get(['id', 'version_no', 'status', 'effective_from', 'effective_to'])
                ->map(fn (Service $v) => ['id' => $v->id, 'version_no' => $v->version_no, 'status' => $v->status->value, 'effective_from' => $v->effective_from?->toDateString(), 'effective_to' => $v->effective_to?->toDateString()]),
            'history' => OrganizationDesignStatusHistory::query()->where('entity_type', $service->getMorphClass())->where('entity_id', $service->getKey())
                ->with('actor:id,name')->orderByDesc('created_at')->get()
                ->map(fn ($h) => ['from' => $h->from_status, 'to' => $h->to_status, 'actor' => $h->actor?->name, 'reason' => $h->reason, 'at' => $h->created_at->toISOString()]),
            'organizationFunctions' => OrganizationFunction::query()->where('organization_id', $service->organization_id)->orderBy('path')
                ->get(['id', 'code', 'title_en', 'title_am', 'depth'])->map->only(['id', 'code', 'title_en', 'title_am', 'depth']),
            'organizationUnits' => OrganizationUnit::query()->where('organization_id', $service->organization_id)->orderBy('name_en')->limit(500)->get(['id', 'code', 'name_en', 'name_am'])->map->only(['id', 'code', 'name_en', 'name_am']),
            'classifications' => $this->classificationOptions(ServiceClassification::SCHEME_LOCAL),
            'cpcClassifications' => $this->classificationOptions(ServiceClassification::SCHEME_CPC),
            'isicOptions' => IsicActivity::query()->where('is_active', true)->orderBy('isic_code')->limit(1000)->get(['id', 'isic_code', 'name_en', 'name_am', 'level'])->map->only(['id', 'isic_code', 'name_en', 'name_am', 'level']),
            'serviceTypes' => ServiceType::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_am']),
            'beneficiaryTypes' => ServiceBeneficiaryType::values(),
            'deliveryChannels' => ServiceDeliveryChannel::values(),
            'can' => [
                'update' => $user->can('update', $service) && $service->status->isEditable(),
                'publish' => $user->can('publish', $service) && $service->status->isEditable() && (int) $service->created_by !== (int) $user->getKey(),
                'version' => $user->can('version', $service) && $service->status === OrganizationDesignStatus::Published,
            ],
        ]);
    }

    public function update(UpdateServiceRequest $request, Service $service): RedirectResponse
    {
        $this->services->updateDraft($request->user(), $service, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.service_updated'), 'type' => 'success']);
    }

    public function storeVersion(NewServiceVersionRequest $request, Service $service): RedirectResponse
    {
        $draft = $this->services->newVersion($request->user(), $service, $request->validated());

        return to_route('organization-design.services.show', $draft)->with('flash', ['message' => __('organization-design.service_version_created'), 'type' => 'success']);
    }

    public function publish(Request $request, Service $service): RedirectResponse
    {
        $this->authorize('publish', $service);
        $this->services->publish($request->user(), $service);

        return back()->with('flash', ['message' => __('organization-design.service_published'), 'type' => 'success']);
    }

    public function cancel(Request $request, Service $service): RedirectResponse
    {
        $this->authorize('update', $service);
        $this->services->cancel($request->user(), $service, $request->string('reason')->toString() ?: null);

        return to_route('organization-design.services.index')->with('flash', ['message' => __('organization-design.service_cancelled'), 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    private function summary(Service $s): array
    {
        return [
            'id' => $s->id, 'code' => $s->code, 'version_no' => $s->version_no, 'lineage_key' => $s->lineage_key,
            'name_en' => $s->name_en, 'name_am' => $s->name_am, 'status' => $s->status->value,
            'beneficiary_type' => $s->beneficiary_type, 'delivery_channel' => $s->delivery_channel,
            'service_classification_id' => $s->service_classification_id,
            'classification' => $s->classification ? ['id' => $s->classification->id, 'code' => $s->classification->code, 'name_en' => $s->classification->name_en, 'name_am' => $s->classification->name_am] : null,
            'organization' => $s->organization ? ['id' => $s->organization->id, 'code' => $s->organization->code, 'name_en' => $s->organization->name_en, 'name_am' => $s->organization->name_am] : null,
            'organization_id' => $s->organization_id,
            'owner_unit' => $s->ownerUnit ? ['id' => $s->ownerUnit->id, 'code' => $s->ownerUnit->code, 'name_en' => $s->ownerUnit->name_en, 'name_am' => $s->ownerUnit->name_am] : null,
            'functions_count' => $s->functions_count ?? $s->functions()->count(),
            'effective_from' => $s->effective_from?->toDateString(), 'effective_to' => $s->effective_to?->toDateString(),
        ];
    }

    /** @return list<array{id: string, code: string, name_en: string, name_am: string|null}> */
    private function organizationOptions($user): array
    {
        $query = Organization::query()->orderBy('name_en');
        $this->scope->applyOrganizationScope($query, $user, 'id');

        return $query->limit(500)->get(['id', 'code', 'name_en', 'name_am'])->map->only(['id', 'code', 'name_en', 'name_am'])->all();
    }

    /** @return list<array<string, mixed>> */
    private function classificationOptions(string $scheme): array
    {
        return ServiceClassification::query()->where('scheme', $scheme)->where('is_active', true)
            ->orderByDesc('revision')->orderBy('sort_order')->orderBy('code')->limit(2000)
            ->get(['id', 'code', 'revision', 'level', 'name_en', 'name_am'])->map->only(['id', 'code', 'revision', 'level', 'name_en', 'name_am'])->all();
    }
}
