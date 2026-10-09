<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransportRouteRequest;
use App\Http\Requests\UpdateTransportRouteRequest;
use App\Http\Resources\TransportRouteResource;
use App\Models\Provider;
use App\Models\TransportRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TransportRouteController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-routes.viewAny'), 403);

        $routes = TransportRoute::query()
            ->with('provider')
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('name_en', ci_like_operator(), "%{$s}%")->orWhere('route_code', ci_like_operator(), "%{$s}%")))
            ->when($request->is_active !== null && $request->is_active !== '', fn ($q) => $q->where('is_active', (bool) $request->is_active))
            ->latest()
            ->paginate(20);

        return Inertia::render('Transport/Routes/Index', [
            'routes' => TransportRouteResource::collection($routes)->response()->getData(true),
            'filters' => $request->only('search', 'is_active'),
            'can' => [
                'create' => $user->can('transport-routes.create'),
                'update' => $user->can('transport-routes.update'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('transport-routes.create'), 403);

        return Inertia::render('Transport/Routes/Create', $this->formPayload());
    }

    public function store(StoreTransportRouteRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if (empty($data['provider_id'])) {
            throw ValidationException::withMessages(['provider_id' => __('transport.provider_required')]);
        }

        TransportRoute::query()->create($data + ['created_by' => $request->user()?->id]);

        return to_route('transport.routes.index')->with('flash', ['type' => 'success', 'message' => __('transport.route_created')]);
    }

    public function edit(Request $request, TransportRoute $route): Response
    {
        abort_unless($request->user()?->can('transport-routes.update'), 403);

        return Inertia::render('Transport/Routes/Edit', [
            'route' => (new TransportRouteResource($route))->resolve(),
            ...$this->formPayload(),
        ]);
    }

    public function update(UpdateTransportRouteRequest $request, TransportRoute $route): RedirectResponse
    {
        $route->update($request->validated() + ['updated_by' => $request->user()?->id]);

        return to_route('transport.routes.index')->with('flash', ['type' => 'success', 'message' => __('transport.route_updated')]);
    }

    /** @return array<string, mixed> */
    private function formPayload(): array
    {
        return [
            'providers' => $this->transportProviders(),
        ];
    }

    private function transportProviders(): Collection
    {
        return Provider::query()
            ->whereHas('services.serviceType', fn ($query) => $query->where('code', 'transport'))
            ->orderBy('name_en')
            ->get(['id', 'provider_code', 'name_en', 'name_am']);
    }
}
