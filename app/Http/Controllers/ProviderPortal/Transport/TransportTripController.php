<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransportTripRequest;
use App\Http\Requests\Transport\TransportDispatchRequest;
use App\Http\Requests\UpdateTransportTripRequest;
use App\Http\Resources\TransportDriverResource;
use App\Http\Resources\TransportRouteResource;
use App\Http\Resources\TransportTripResource;
use App\Http\Resources\TransportVehicleResource;
use App\Models\Provider;
use App\Models\ProviderUser;
use App\Models\TransportDriver;
use App\Models\TransportRoute;
use App\Models\TransportTrip;
use App\Models\TransportVehicle;
use App\Services\ProviderPortal\ProviderPortalContext;
use App\Services\Transport\TransportExportService;
use App\Services\Transport\TransportReportService;
use App\Services\Transport\TransportTripService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TransportTripController extends Controller
{
    public function index(Request $request, ProviderPortalContext $context): Response
    {
        $provider = $this->provider($request, $context);
        $date = $request->date ?: today()->toDateString();

        $trips = TransportTrip::query()->with(['route', 'vehicle', 'driver'])->where('provider_id', $provider->id)
            ->when($request->date_from || $request->date_to, fn ($q) => $q->whereBetween('trip_date', [$request->date_from ?: $date, ($request->date_to ?: $date).' 23:59:59']), fn ($q) => $q->whereDate('trip_date', $date))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('trip_date')->orderBy('departure_time')->paginate(50)->withQueryString();

        return Inertia::render('ProviderPortal/Transport/Trips/Index', [
            'trips' => TransportTripResource::collection($trips)->response()->getData(true),
            'filters' => ['date' => $date] + $request->only('date_from', 'date_to', 'status'),
            'today' => today()->toDateString(),
        ]);
    }

    public function show(Request $request, ProviderPortalContext $context, TransportTrip $trip, TransportReportService $reports): Response
    {
        $provider = $this->provider($request, $context);
        abort_if($trip->provider_id !== $provider->id, 403, __('transport.own_data_only'));
        /** @var ProviderUser $providerUser */
        $providerUser = auth('provider')->user();
        $canManifest = $providerUser->canUseServicePermission('provider.transport.manifests.view');
        $manifest = $canManifest ? $reports->manifest($trip, staffTier: false) : null;

        return Inertia::render('ProviderPortal/Transport/Trips/Show', [
            'trip' => (new TransportTripResource($trip->load(['route', 'vehicle', 'driver', 'vehicleChanges.fromVehicle', 'vehicleChanges.toVehicle'])))->resolve(),
            'manifest' => $manifest ? ['rows' => $manifest['rows'], 'totals' => $manifest['totals']] : null,
            'vehicles' => TransportVehicleResource::collection(TransportVehicle::query()->where('provider_id', $provider->id)->where('status', 'active')->orderBy('vehicle_code')->get())->resolve(),
            'drivers' => TransportDriverResource::collection(TransportDriver::query()->where('provider_id', $provider->id)->where('status', 'active')->orderBy('full_name')->get())->resolve(),
            'can' => ['dispatch' => $providerUser->canUseServicePermission('provider.transport.dispatch'), 'manifest' => $canManifest],
        ]);
    }

    public function dispatch(TransportDispatchRequest $request, ProviderPortalContext $context, TransportTrip $trip, TransportTripService $trips): RedirectResponse
    {
        $provider = $this->provider($request, $context);
        abort_if($trip->provider_id !== $provider->id, 403, __('transport.own_data_only'));
        $data = $request->validated();
        /** @var ProviderUser $actor */
        $actor = auth('provider')->user();
        $vehicle = isset($data['transport_vehicle_id']) ? TransportVehicle::query()->find($data['transport_vehicle_id']) : null;
        $driver = isset($data['transport_driver_id']) ? TransportDriver::query()->find($data['transport_driver_id']) : null;

        try {
            match ($data['action']) {
                'assign' => $trips->assign($trip, $vehicle, $driver, $actor, $request),
                'start' => $trips->start($trip, $actor, $request),
                'depart' => $trips->depart($trip, $actor, $request),
                'complete' => $trips->complete($trip, $actor, $request, $data['notes'] ?? null, isset($data['distance_km']) ? (string) $data['distance_km'] : null),
                'cancel' => $trips->cancel($trip, (string) $data['reason'], $actor, $request),
                'fail' => $trips->cancel($trip, (string) $data['reason'], $actor, $request, failed: true),
                'replace_vehicle' => $trips->replaceVehicle($trip, $vehicle, $driver, (string) $data['reason'], $actor, $request),
            };
        } catch (DomainException $e) {
            return back()->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back()->with('flash', ['type' => 'success', 'message' => __('transport.trip_updated')]);
    }

    public function manifestPdf(Request $request, ProviderPortalContext $context, TransportTrip $trip, TransportReportService $reports, TransportExportService $exports)
    {
        $provider = $this->provider($request, $context);
        abort_if($trip->provider_id !== $provider->id, 403, __('transport.own_data_only'));

        return $exports->manifestPdf($reports->manifest($trip, staffTier: false), $exports->locale($request), staffTier: false);
    }

    public function create(Request $request, ProviderPortalContext $context): Response
    {
        return Inertia::render('ProviderPortal/Transport/Trips/Create', $this->formPayload($request, $context));
    }

    public function store(StoreTransportTripRequest $request, ProviderPortalContext $context): RedirectResponse
    {
        $provider = $this->provider($request, $context);
        $data = $request->validated();
        $data['trip_number'] ??= $this->newTripNumber();

        TransportTrip::query()->create(array_merge($data, ['provider_id' => $provider->id]));

        return to_route('provider.portal.transport.trips.index')->with('flash', ['type' => 'success', 'message' => __('transport.trip_created')]);
    }

    public function edit(Request $request, ProviderPortalContext $context, TransportTrip $trip): Response
    {
        abort_if($trip->provider_id !== $this->provider($request, $context)->id, 403, __('transport.own_data_only'));

        return Inertia::render('ProviderPortal/Transport/Trips/Edit', [
            'trip' => (new TransportTripResource($trip->load(['route', 'vehicle', 'driver'])))->resolve(),
            ...$this->formPayload($request, $context),
        ]);
    }

    public function update(UpdateTransportTripRequest $request, ProviderPortalContext $context, TransportTrip $trip): RedirectResponse
    {
        abort_if($trip->provider_id !== $this->provider($request, $context)->id, 403, __('transport.own_data_only'));
        $trip->update($request->validated());

        return to_route('provider.portal.transport.trips.index')->with('flash', ['type' => 'success', 'message' => __('transport.trip_updated')]);
    }

    /** @return array<string, mixed> */
    private function formPayload(Request $request, ProviderPortalContext $context): array
    {
        $provider = $this->provider($request, $context);

        return [
            // The city's date, not the device's: a phone set to UTC would plan "today" on the wrong day.
            'today' => today()->toDateString(),
            'routes' => TransportRouteResource::collection(TransportRoute::query()->where('provider_id', $provider->id)->orderBy('name_en')->get())->resolve(),
            'vehicles' => TransportVehicleResource::collection(TransportVehicle::query()->where('provider_id', $provider->id)->orderBy('vehicle_code')->get())->resolve(),
            'drivers' => TransportDriverResource::collection(TransportDriver::query()->where('provider_id', $provider->id)->orderBy('full_name')->get())->resolve(),
        ];
    }

    private function provider(Request $request, ProviderPortalContext $context): Provider
    {
        $provider = $context->provider($request);
        abort_if($provider === null || ! $provider->hasService('transport'), 403, __('transport.provider_service_disabled'));

        return $provider;
    }

    /**
     * TRP-YYYYMMDD-XXXXXX with a random suffix: unique without counting (a
     * count collides under concurrency and after any deletion).
     */
    private function newTripNumber(): string
    {
        do {
            $number = 'TRP-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (TransportTrip::query()->where('trip_number', $number)->exists());

        return $number;
    }
}
