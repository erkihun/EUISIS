<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Security\Passwords\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Providers\Domain\Models\Provider;
use Modules\Transport\Application\Services\TransportProviderService;
use Modules\Transport\Http\Requests\StoreTransportProviderRequest;
use Modules\Transport\Http\Requests\UpdateTransportProviderRequest;
use Modules\Transport\Http\Resources\TransportProviderResource;

class TransportProviderController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-providers.viewAny'), 403);

        $providers = Provider::query()
            ->with(['transportProfile', 'assignedOrganization', 'providerType'])
            ->whereHas('services.serviceType', fn ($query) => $query->where('code', 'transport'))
            ->when($request->string('search')->trim()->toString(), fn ($q, string $s) => $q->where(fn ($q) => $q
                ->where('name_en', ci_like_operator(), '%'.addcslashes($s, '%_\\').'%')
                ->orWhere('provider_code', ci_like_operator(), addcslashes($s, '%_\\').'%')))
            ->when($request->string('status')->toString(), fn ($q, string $s) => $q->where('status', $s))
            ->latest()
            ->paginate(20)
            ->withQueryString();

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
        ]);
    }

    public function store(StoreTransportProviderRequest $request, TransportProviderService $service): RedirectResponse
    {
        $data = $request->validated();

        // A portal account without a typed password gets a unique one-time
        // password (never a constant), shown once to this administrator.
        $temporary = ($data['create_provider_user'] ?? false) && blank($data['user_password'] ?? null)
            ? app(PasswordPolicy::class)->generateTemporaryPassword()
            : null;
        if ($temporary !== null) {
            $data['user_password'] = $temporary;
        }

        $providerId = $service->create($data, $request->user()?->id);

        return to_route('transport.providers.show', $providerId)->with('flash', array_filter([
            'type' => 'success',
            'message' => __('transport.provider_created').($temporary ? ' '.__('password-policy.temporary_password_created') : ''),
            'temporary_password' => $temporary,
        ]));
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
        ]);
    }

    public function update(UpdateTransportProviderRequest $request, Provider $provider): RedirectResponse
    {
        $this->assertTransportProvider($provider);
        // Only what the form sent: a field it leaves out keeps its stored value.
        $data = collect($request->validated())->only(array_keys($request->all()));

        if ($data->get('assigned_scope_type') === 'citywide') {
            $data->put('assigned_organization_id', null);
        }

        $profileKeys = ['license_number', 'registration_number', 'service_area_description_en', 'service_area_description_am'];

        $provider->update($data->except($profileKeys)->all() + ['updated_by' => $request->user()?->id]);
        $provider->transportProfile()->updateOrCreate(
            ['provider_id' => $provider->id],
            $data->only($profileKeys)->all() + [
                'status' => $data->get('status', $provider->transportProfile?->status ?? 'active'),
                'updated_by' => $request->user()?->id,
            ],
        );

        return to_route('transport.providers.show', $provider)->with('flash', ['type' => 'success', 'message' => __('transport.provider_updated')]);
    }

    public function destroy(Request $request, Provider $provider): RedirectResponse
    {
        abort_unless($request->user()?->can('transport-providers.delete'), 403);
        $this->assertTransportProvider($provider);

        // A provider that also runs another service (a cafeteria) is not
        // transport's to delete: that would lock its other portal users out.
        $otherServices = $provider->services()->whereHas('serviceType', fn ($query) => $query->where('code', '!=', 'transport'))->where('status', 'active')->exists();
        if ($otherServices) {
            return back()->with('flash', ['type' => 'error', 'message' => __('transport.provider_shared')]);
        }

        $provider->delete();

        return to_route('transport.providers.index')->with('flash', ['type' => 'success', 'message' => __('transport.provider_deleted')]);
    }

    private function assertTransportProvider(Provider $provider): void
    {
        abort_unless($provider->hasService('transport'), 404);
    }
}
