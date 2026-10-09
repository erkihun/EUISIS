<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransportDriverRequest;
use App\Http\Requests\UpdateTransportDriverRequest;
use App\Http\Resources\TransportDriverResource;
use App\Http\Resources\TransportVehicleResource;
use App\Models\Provider;
use App\Models\TransportDriver;
use App\Models\TransportVehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TransportDriverController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-drivers.viewAny'), 403);

        $drivers = TransportDriver::query()
            ->with(['provider', 'vehicle'])
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('full_name', ci_like_operator(), "%{$s}%")->orWhere('license_number', ci_like_operator(), "%{$s}%")))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(20);

        return Inertia::render('Transport/Drivers/Index', [
            'drivers' => TransportDriverResource::collection($drivers)->response()->getData(true),
            'filters' => $request->only('search', 'status'),
            'can' => [
                'create' => $user->can('transport-drivers.create'),
                'update' => $user->can('transport-drivers.update'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('transport-drivers.create'), 403);

        return Inertia::render('Transport/Drivers/Create', $this->formPayload());
    }

    public function store(StoreTransportDriverRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if (empty($data['provider_id'])) {
            throw ValidationException::withMessages(['provider_id' => __('transport.provider_required')]);
        }

        TransportDriver::query()->create($data + ['created_by' => $request->user()?->id]);

        return to_route('transport.drivers.index')->with('flash', ['type' => 'success', 'message' => __('transport.driver_created')]);
    }

    public function edit(Request $request, TransportDriver $driver): Response
    {
        abort_unless($request->user()?->can('transport-drivers.update'), 403);

        return Inertia::render('Transport/Drivers/Edit', [
            'driver' => (new TransportDriverResource($driver->load('vehicle')))->resolve(),
            ...$this->formPayload(),
        ]);
    }

    public function update(UpdateTransportDriverRequest $request, TransportDriver $driver): RedirectResponse
    {
        $driver->update($request->validated() + ['updated_by' => $request->user()?->id]);

        return to_route('transport.drivers.index')->with('flash', ['type' => 'success', 'message' => __('transport.driver_updated')]);
    }

    /** @return array<string, mixed> */
    private function formPayload(): array
    {
        return [
            'providers' => Provider::query()
                ->whereHas('services.serviceType', fn ($query) => $query->where('code', 'transport'))
                ->orderBy('name_en')
                ->get(['id', 'provider_code', 'name_en', 'name_am']),
            'vehicles' => TransportVehicleResource::collection(TransportVehicle::query()->orderBy('vehicle_code')->get())->resolve(),
        ];
    }
}
