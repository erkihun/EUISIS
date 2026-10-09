<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransportVehicleRequest;
use App\Http\Requests\UpdateTransportVehicleRequest;
use App\Http\Resources\TransportRouteResource;
use App\Http\Resources\TransportVehicleResource;
use App\Models\Provider;
use App\Models\TransportRoute;
use App\Models\TransportVehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TransportVehicleController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-vehicles.viewAny'), 403);

        $vehicles = TransportVehicle::query()
            ->with(['provider', 'route'])
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('vehicle_code', ci_like_operator(), "%{$s}%")->orWhere('plate_number', ci_like_operator(), "%{$s}%")))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(20);

        return Inertia::render('Transport/Vehicles/Index', [
            'vehicles' => TransportVehicleResource::collection($vehicles)->response()->getData(true),
            'filters' => $request->only('search', 'status'),
            'can' => [
                'create' => $user->can('transport-vehicles.create'),
                'update' => $user->can('transport-vehicles.update'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('transport-vehicles.create'), 403);

        return Inertia::render('Transport/Vehicles/Create', $this->formPayload());
    }

    public function store(StoreTransportVehicleRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if (empty($data['provider_id'])) {
            throw ValidationException::withMessages(['provider_id' => __('transport.provider_required')]);
        }

        TransportVehicle::query()->create($data + ['created_by' => $request->user()?->id]);

        return to_route('transport.vehicles.index')->with('flash', ['type' => 'success', 'message' => __('transport.vehicle_created')]);
    }

    public function edit(Request $request, TransportVehicle $vehicle): Response
    {
        abort_unless($request->user()?->can('transport-vehicles.update'), 403);

        return Inertia::render('Transport/Vehicles/Edit', [
            'vehicle' => (new TransportVehicleResource($vehicle->load('route')))->resolve(),
            ...$this->formPayload(),
        ]);
    }

    public function update(UpdateTransportVehicleRequest $request, TransportVehicle $vehicle): RedirectResponse
    {
        $vehicle->update($request->validated() + ['updated_by' => $request->user()?->id]);

        return to_route('transport.vehicles.index')->with('flash', ['type' => 'success', 'message' => __('transport.vehicle_updated')]);
    }

    /** @return array<string, mixed> */
    private function formPayload(): array
    {
        return [
            'providers' => Provider::query()
                ->whereHas('services.serviceType', fn ($query) => $query->where('code', 'transport'))
                ->orderBy('name_en')
                ->get(['id', 'provider_code', 'name_en', 'name_am']),
            'routes' => TransportRouteResource::collection(TransportRoute::query()->orderBy('name_en')->get())->resolve(),
        ];
    }
}
