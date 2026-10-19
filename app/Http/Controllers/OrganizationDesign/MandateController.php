<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Enums\LegalSourceStatus;
use App\Enums\OrganizationDesignStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationDesign\NewMandateVersionRequest;
use App\Http\Requests\OrganizationDesign\StoreMandateRequest;
use App\Http\Requests\OrganizationDesign\UpdateMandateRequest;
use App\Models\InstitutionMandate;
use App\Models\LegalInstrumentType;
use App\Models\LegalSource;
use App\Models\Organization;
use App\Models\OrganizationDesignStatusHistory;
use App\Services\OrganizationDesign\FunctionService;
use App\Services\OrganizationDesign\MandateService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MandateController extends Controller
{
    public function __construct(
        private readonly MandateService $mandates,
        private readonly FunctionService $functions,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', InstitutionMandate::class);
        $user = $request->user();

        $query = InstitutionMandate::query()
            ->with(['organization:id,code,name_en,name_am', 'instrumentType:id,code,name_en,name_am'])
            ->when($request->string('organization_id')->toString(), fn ($q, $id) => $q->where('organization_id', $id))
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->when($request->string('search')->toString(), function ($q, $search): void {
                $q->where(function ($nested) use ($search): void {
                    $nested->where('code', ci_like_operator(), "%{$search}%")
                        ->orWhere('title_en', ci_like_operator(), "%{$search}%")
                        ->orWhere('title_am', ci_like_operator(), "%{$search}%")
                        ->orWhere('legal_instrument_number', ci_like_operator(), "%{$search}%");
                });
            })
            ->orderBy('code')->orderByDesc('version_no');
        $this->scope->applyOrganizationScope($query, $user);

        $mandates = $query->paginate(25)->withQueryString()->through(fn (InstitutionMandate $m) => $this->summary($m));

        return Inertia::render('OrganizationDesign/Mandates/Index', [
            'mandates' => $mandates,
            'filters' => $request->only(['organization_id', 'status', 'search']),
            'organizations' => $this->organizationOptions($user),
            'instrumentTypes' => LegalInstrumentType::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_am']),
            'statuses' => array_map(fn (OrganizationDesignStatus $s) => $s->value, OrganizationDesignStatus::cases()),
            'legalSources' => $this->legalSourceOptions($request->string('organization_id')->toString() ?: null),
            'can' => ['create' => $user->can('create', InstitutionMandate::class)],
        ]);
    }

    /**
     * Active legal sources a mandate of this organization may rest on: the
     * organization's own and the city-level ones. Without an organization
     * only the city-level sources are offered.
     *
     * @return list<array{id: string, code: string, title_en: string, title_am: string|null}>
     */
    private function legalSourceOptions(?string $organizationId): array
    {
        $query = LegalSource::query()->where('status', LegalSourceStatus::Active)->orderBy('code');
        $organizationId === null ? $query->whereNull('organization_id') : $query->citableBy($organizationId);

        return $query->limit(300)->get(['id', 'code', 'title_en', 'title_am'])->map->only(['id', 'code', 'title_en', 'title_am'])->all();
    }

    public function store(StoreMandateRequest $request): RedirectResponse
    {
        $mandate = $this->mandates->createDraft($request->user(), $request->validated('organization_id'), $request->validated());

        return to_route('organization-design.mandates.show', $mandate)->with('flash', ['message' => __('organization-design.mandate_created'), 'type' => 'success']);
    }

    public function show(Request $request, InstitutionMandate $mandate): Response
    {
        $this->authorize('view', $mandate);
        $user = $request->user();
        $mandate->load(['organization:id,code,name_en,name_am', 'instrumentType:id,code,name_en,name_am', 'legalSource:id,code,title_en,title_am', 'approver:id,name', 'creator:id,name', 'powers']);

        return Inertia::render('OrganizationDesign/Mandates/Show', [
            'mandate' => [
                ...$this->summary($mandate),
                'description_en' => $mandate->description_en, 'description_am' => $mandate->description_am,
                'legal_reference' => $mandate->legal_reference, 'article_reference' => $mandate->article_reference,
                'issuing_authority' => $mandate->issuing_authority,
                'approved_by' => $mandate->approver?->name, 'approved_at' => $mandate->approved_at?->toISOString(),
                'created_by' => $mandate->creator?->name, 'created_by_id' => $mandate->created_by,
            ],
            'versions' => $mandate->versions()->get(['id', 'version_no', 'status', 'effective_from', 'effective_to'])
                ->map(fn (InstitutionMandate $v) => ['id' => $v->id, 'version_no' => $v->version_no, 'status' => $v->status->value, 'effective_from' => $v->effective_from?->toDateString(), 'effective_to' => $v->effective_to?->toDateString()]),
            'powers' => $mandate->powers->map(fn ($p) => $p->only(['id', 'code', 'title_en', 'title_am', 'description_en', 'description_am', 'legal_reference', 'sequence', 'status'])),
            'functions' => $this->functions->tree($mandate->organization_id, $mandate->getKey()),
            'history' => OrganizationDesignStatusHistory::query()->where('entity_type', $mandate->getMorphClass())->where('entity_id', $mandate->getKey())
                ->with('actor:id,name')->orderByDesc('created_at')->get()
                ->map(fn ($h) => ['from' => $h->from_status, 'to' => $h->to_status, 'actor' => $h->actor?->name, 'reason' => $h->reason, 'at' => $h->created_at->toISOString()]),
            'instrumentTypes' => LegalInstrumentType::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_am']),
            'legalSources' => $this->legalSourceOptions($mandate->organization_id),
            'can' => [
                'update' => $user->can('update', $mandate) && $mandate->status->isEditable(),
                'publish' => $user->can('publish', $mandate) && $mandate->status->isEditable() && (int) $mandate->created_by !== (int) $user->getKey(),
                'version' => $user->can('version', $mandate) && $mandate->status === OrganizationDesignStatus::Published,
                'manageFunctions' => $user->can('manageFunctions', $mandate),
            ],
        ]);
    }

    public function update(UpdateMandateRequest $request, InstitutionMandate $mandate): RedirectResponse
    {
        $this->mandates->updateDraft($request->user(), $mandate, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.mandate_updated'), 'type' => 'success']);
    }

    public function storeVersion(NewMandateVersionRequest $request, InstitutionMandate $mandate): RedirectResponse
    {
        $draft = $this->mandates->newVersion($request->user(), $mandate, $request->validated());

        return to_route('organization-design.mandates.show', $draft)->with('flash', ['message' => __('organization-design.mandate_version_created'), 'type' => 'success']);
    }

    public function publish(Request $request, InstitutionMandate $mandate): RedirectResponse
    {
        $this->authorize('publish', $mandate);
        $this->mandates->publish($request->user(), $mandate);

        return back()->with('flash', ['message' => __('organization-design.mandate_published'), 'type' => 'success']);
    }

    public function cancel(Request $request, InstitutionMandate $mandate): RedirectResponse
    {
        $this->authorize('update', $mandate);
        $this->mandates->cancel($request->user(), $mandate, $request->string('reason')->toString() ?: null);

        return to_route('organization-design.mandates.index')->with('flash', ['message' => __('organization-design.mandate_cancelled'), 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    private function summary(InstitutionMandate $m): array
    {
        return [
            'id' => $m->id, 'code' => $m->code, 'version_no' => $m->version_no, 'lineage_key' => $m->lineage_key,
            'title_en' => $m->title_en, 'title_am' => $m->title_am, 'status' => $m->status->value,
            'legal_instrument_number' => $m->legal_instrument_number,
            'legal_instrument_type_id' => $m->legal_instrument_type_id,
            'legal_source_id' => $m->legal_source_id,
            'legal_source' => $m->relationLoaded('legalSource') && $m->legalSource ? ['id' => $m->legalSource->id, 'code' => $m->legalSource->code, 'title_en' => $m->legalSource->title_en, 'title_am' => $m->legalSource->title_am] : null,
            'instrument_type' => $m->instrumentType ? ['code' => $m->instrumentType->code, 'name_en' => $m->instrumentType->name_en, 'name_am' => $m->instrumentType->name_am] : null,
            'organization' => $m->organization ? ['id' => $m->organization->id, 'code' => $m->organization->code, 'name_en' => $m->organization->name_en, 'name_am' => $m->organization->name_am] : null,
            'organization_id' => $m->organization_id,
            'effective_from' => $m->effective_from?->toDateString(), 'effective_to' => $m->effective_to?->toDateString(),
        ];
    }

    /** @return list<array{id: string, code: string, name_en: string, name_am: string|null}> */
    private function organizationOptions($user): array
    {
        $query = Organization::query()->orderBy('name_en');
        $this->scope->applyOrganizationScope($query, $user, 'id');

        return $query->limit(500)->get(['id', 'code', 'name_en', 'name_am'])->map->only(['id', 'code', 'name_en', 'name_am'])->all();
    }
}
