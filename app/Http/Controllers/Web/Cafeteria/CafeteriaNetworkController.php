<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cafeteria;

use App\Enums\CafeteriaLocationType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Cafeteria\Concerns\CafeteriaAdminContext;
use App\Models\CafeteriaServiceNetwork;
use App\Models\OrganizationCafeteriaAccess;
use App\Services\Cafeteria\Network\CafeteriaNetworkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Cafeteria Management → Cafeteria Networks. */
class CafeteriaNetworkController extends Controller
{
    use CafeteriaAdminContext;

    public function __construct(private readonly CafeteriaNetworkService $networks) {}

    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();

        $networks = CafeteriaServiceNetwork::query()
            ->with(['provider:id,provider_code,name_en,name_am', 'mainCafeteria:id,code,name_en,name_am,cafeteria_service_network_id'])
            ->withCount([
                'cafeterias as branch_count' => fn ($q) => $q->where('location_type', CafeteriaLocationType::Branch->value),
                'cafeterias as service_point_count' => fn ($q) => $q->where('location_type', CafeteriaLocationType::ServicePoint->value),
                'organizationAccess as access_count' => fn ($q) => $q->where('status', 'active'),
            ])
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('code', ci_like_operator(), "%{$search}%")
                ->orWhere('name_en', ci_like_operator(), "%{$search}%")
                ->orWhere('name_am', ci_like_operator(), "%{$search}%")))
            ->when($request->filled('provider_id'), fn ($q) => $q->where('provider_id', $request->string('provider_id')->toString()))
            ->orderBy('name_en')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Cafeteria/Networks/Index', [
            'networks' => collect($networks->items())->map(fn (CafeteriaServiceNetwork $n): array => [
                'id' => $n->id,
                'code' => $n->code,
                'name_en' => $n->name_en,
                'name_am' => $n->name_am,
                'status' => $n->status,
                'provider' => $this->namePair($n->provider, 'provider_code'),
                'main_cafeteria' => $this->namePair($n->mainCafeteria),
                'branch_count' => (int) $n->branch_count,
                'service_point_count' => (int) $n->service_point_count,
                'access_count' => (int) $n->access_count,
            ])->all(),
            'meta' => ['currentPage' => $networks->currentPage(), 'lastPage' => $networks->lastPage(), 'total' => $networks->total(), 'perPage' => $networks->perPage()],
            'filters' => $request->only(['search', 'provider_id']),
            'providers' => $this->providerOptions(),
            'can' => ['manage' => $request->user()->can('cafeteria_networks.manage')],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Cafeteria/Networks/Form', [
            'network' => null,
            'providers' => $this->providerOptions(),
            'defaults' => ['provider_id' => $request->string('provider_id')->toString()],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $network = $this->networks->createNetwork($this->validated($request), $request->user(), $request);

        return to_route('cafeteria.networks.show', $network)
            ->with('flash', ['message' => __('cafeteria-policy.network_saved'), 'type' => 'success']);
    }

    public function show(Request $request, CafeteriaServiceNetwork $network): Response
    {
        $network->load('provider:id,provider_code,name_en,name_am');

        $access = OrganizationCafeteriaAccess::query()
            ->where('cafeteria_service_network_id', $network->id)
            ->with(['organization:id,code,name_en,name_am', 'primaryCafeteria:id,code,name_en,name_am'])
            ->tap(fn ($q) => $this->withinOrganizationScope($q, $request->user()))
            ->orderByDesc('effective_from')
            ->get()
            ->map(fn (OrganizationCafeteriaAccess $a): array => [
                'id' => $a->id,
                'organization' => $this->namePair($a->organization),
                'primary_cafeteria' => $this->namePair($a->primaryCafeteria),
                'allow_cross_location_usage' => $a->allow_cross_location_usage,
                'effective_from' => $a->effective_from?->toDateString(),
                'effective_to' => $a->effective_to?->toDateString(),
                'status' => $a->status?->value,
            ]);

        return Inertia::render('Cafeteria/Networks/Show', [
            'network' => [
                'id' => $network->id,
                'code' => $network->code,
                'name_en' => $network->name_en,
                'name_am' => $network->name_am,
                'description' => $network->description,
                'status' => $network->status,
                'provider' => $this->namePair($network->provider, 'provider_code'),
            ],
            'tree' => $this->networks->tree($network),
            'access' => $access,
            'can' => [
                'manage' => $request->user()->can('cafeteria_networks.manage'),
                'manageAccess' => $request->user()->can('cafeteria_access.manage'),
            ],
        ]);
    }

    public function edit(CafeteriaServiceNetwork $network): Response
    {
        return Inertia::render('Cafeteria/Networks/Form', [
            'network' => $network->only(['id', 'provider_id', 'code', 'name_en', 'name_am', 'description', 'status']),
            'providers' => $this->providerOptions(),
            'defaults' => [],
        ]);
    }

    public function update(Request $request, CafeteriaServiceNetwork $network): RedirectResponse
    {
        $this->networks->updateNetwork($network, $this->validated($request, $network), $request->user(), $request);

        return to_route('cafeteria.networks.show', $network)
            ->with('flash', ['message' => __('cafeteria-policy.network_saved'), 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?CafeteriaServiceNetwork $network = null): array
    {
        return $request->validate([
            'provider_id' => [$network ? 'prohibited' : 'required', 'uuid', 'exists:providers,id'],
            'code' => [$network ? 'prohibited' : 'required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('cafeteria_service_networks', 'code')],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);
    }
}
