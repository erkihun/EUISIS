<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransportProviderRequest;
use App\Http\Requests\UpdateTransportProviderRequest;
use App\Http\Resources\TransportProviderResource;
use App\Models\Organization;
use App\Models\Provider;
use App\Services\Transport\TransportProviderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransportProviderController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-providers.viewAny'), 403);

        $providers = Provider::query()
            ->with(['transportProfile', 'assignedOrganization', 'providerType'])
            ->whereHas('services.serviceType', fn ($query) => $query->where('code', 'transport'))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('name_en', ci_like_operator(), "%{$s}%")->orWhere('provider_code', ci_like_operator(), "%{$s}%")))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(20);

        return Inertia::render('Transport/Providers/Index', [
            'providers' => TransportProviderResource::collection($providers)->response()->getData(true),
            'filters' => $request->only('search', 'status'),
            'can' => [
                'create' => $user->can('transport-providers.create'),
                'update' => $user->can('transport-providers.update'),
                'delete' => $user->can('transport-providers.delete'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('transport-providers.create'), 403);

        return Inertia::render('Transport/Providers/Create', [
            'organizations' => Organization::query()->orderBy('name_en')->limit(500)->get(['id', 'name_en', 'name_am']),
        ]);
    }

    public function store(StoreTransportProviderRequest $request, TransportProviderService $service): RedirectResponse
    {
        $provider = $service->create($request->validated(), $request->user()?->id);

        return to_route('transport.providers.show', $provider)->with('flash', ['type' => 'success', 'message' => __('transport.provider_created')]);
    }

    public function show(Request $request, Provider $provider): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-providers.view'), 403);
        $this->assertTransportProvider($provider);

        return Inertia::render('Transport/Providers/Show', [
            'provider' => (new TransportProviderResource($provider->load(['transportProfile', 'assignedOrganization', 'services.serviceType', 'users'])))->resolve(),
            'can' => [
                'update' => $user->can('transport-providers.update'),
                'delete' => $user->can('transport-providers.delete'),
            ],
        ]);
    }

    public function edit(Request $request, Provider $provider): Response
    {
        abort_unless($request->user()?->can('transport-providers.update'), 403);
        $this->assertTransportProvider($provider);

        return Inertia::render('Transport/Providers/Edit', [
            'provider' => (new TransportProviderResource($provider->load(['transportProfile', 'assignedOrganization'])))->resolve(),
            'organizations' => Organization::query()->orderBy('name_en')->limit(500)->get(['id', 'name_en', 'name_am']),
        ]);
    }

    public function update(UpdateTransportProviderRequest $request, Provider $provider): RedirectResponse
    {
        $this->assertTransportProvider($provider);
        $data = $request->validated();

        if (($data['assigned_scope_type'] ?? 'self') === 'citywide') {
            $data['assigned_organization_id'] = null;
        }

        $provider->update(collect($data)->except(['license_number', 'registration_number', 'service_area_description_en', 'service_area_description_am'])->all() + ['updated_by' => $request->user()?->id]);
        $provider->transportProfile()->updateOrCreate(
            ['provider_id' => $provider->id],
            [
                'license_number' => $data['license_number'] ?? null,
                'registration_number' => $data['registration_number'] ?? null,
                'service_area_description_en' => $data['service_area_description_en'] ?? null,
                'service_area_description_am' => $data['service_area_description_am'] ?? null,
                'status' => $data['status'] ?? 'active',
                'updated_by' => $request->user()?->id,
            ],
        );

        return to_route('transport.providers.show', $provider)->with('flash', ['type' => 'success', 'message' => __('transport.provider_updated')]);
    }

    public function destroy(Request $request, Provider $provider): RedirectResponse
    {
        abort_unless($request->user()?->can('transport-providers.delete'), 403);
        $this->assertTransportProvider($provider);
        $provider->delete();

        return to_route('transport.providers.index')->with('flash', ['type' => 'success', 'message' => __('transport.provider_deleted')]);
    }

    private function assertTransportProvider(Provider $provider): void
    {
        abort_unless($provider->hasService('transport'), 404);
    }
}
