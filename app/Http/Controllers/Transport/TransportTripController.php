<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transport;

use App\Enums\Transport\TransportDirection;
use App\Enums\Transport\TransportTripStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transport\AdminTransportTripRequest;
use App\Http\Requests\Transport\TransportDispatchRequest;
use App\Http\Resources\TransportDriverResource;
use App\Http\Resources\TransportExceptionResource;
use App\Http\Resources\TransportRouteResource;
use App\Http\Resources\TransportTripResource;
use App\Http\Resources\TransportVehicleResource;
use App\Models\Provider;
use App\Models\TransportDriver;
use App\Models\TransportRoute;
use App\Models\TransportTrip;
use App\Models\TransportVehicle;
use App\Services\Transport\TransportExportService;
use App\Services\Transport\TransportReportService;
use App\Services\Transport\TransportTripService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Trips, dispatch and manifests for staff. Manifests are the staff tier (organization shown), within the viewer's scope. */
class TransportTripController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-trips.viewAny'), 403);
        $date = $request->date ?: today()->toDateString();

        $trips = TransportTrip::query()->with(['route', 'vehicle', 'driver', 'provider'])
            ->when($request->date_from || $request->date_to, fn ($q) => $q->whereBetween('trip_date', [$request->date_from ?: $date, ($request->date_to ?: $date).' 23:59:59']), fn ($q) => $q->whereDate('trip_date', $date))
            ->when($request->provider_id, fn ($q, $id) => $q->where('provider_id', $id))
            ->when($request->route_id, fn ($q, $id) => $q->where('transport_route_id', $id))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->direction, fn ($q, $d) => $q->where('direction', $d))
            ->orderBy('trip_date')->orderBy('departure_time')->paginate(30)->withQueryString();

        return Inertia::render('Transport/Trips/Index', [
            'trips' => TransportTripResource::collection($trips)->response()->getData(true),
            'filters' => ['date' => $date] + $request->only('date_from', 'date_to', 'provider_id', 'route_id', 'status', 'direction'),
            'providers' => $this->providers(),
            'statuses' => TransportTripStatus::values(),
            'can' => ['create' => $user->can('transport-trips.create'), 'dispatch' => $user->can('transport-trips.dispatch'), 'manifest' => $user->can('transport-manifests.view')],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('transport-trips.create'), 403);

        return Inertia::render('Transport/Trips/Create', [
            'today' => today()->toDateString(),
            'providers' => $this->providers(),
            'routes' => TransportRouteResource::collection(TransportRoute::query()->where('is_active', true)->orderBy('name_en')->get())->resolve(),
            'vehicles' => TransportVehicleResource::collection(TransportVehicle::query()->where('status', 'active')->orderBy('vehicle_code')->get())->resolve(),
            'drivers' => TransportDriverResource::collection(TransportDriver::query()->where('status', 'active')->orderBy('full_name')->get())->resolve(),
            'directions' => TransportDirection::values(),
        ]);
    }

    /** An extra trip, approved by the staff member who plans it. */
    public function store(AdminTransportTripRequest $request, TransportTripService $trips): RedirectResponse
    {
        $provider = Provider::query()->findOrFail($request->validated('provider_id'));
        $data = $request->safe()->except('provider_id') + ['is_extra' => true, 'approved_by' => $request->user()->id];
        $trip = $trips->plan($provider, $data, $request->user(), $request);

        return to_route('transport.trips.show', $trip)->with('flash', ['type' => 'success', 'message' => __('transport.trip_created')]);
    }

    public function show(Request $request, TransportTrip $trip, TransportReportService $reports): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-trips.view'), 403);
        $canManifest = $user->can('transport-manifests.view');
        $manifest = $canManifest ? $reports->manifest($trip, staffTier: true) : null;

        return Inertia::render('Transport/Trips/Show', [
            'trip' => (new TransportTripResource($trip->load(['route', 'vehicle', 'driver', 'provider', 'vehicleChanges.fromVehicle', 'vehicleChanges.toVehicle'])))->resolve(),
            'manifest' => $manifest ? ['rows' => $manifest['rows'], 'totals' => $manifest['totals']] : null,
            'exceptions' => TransportExceptionResource::collection($trip->exceptions()->with(['employee', 'device'])->latest('occurred_at')->get())->resolve(),
            'vehicles' => TransportVehicleResource::collection(TransportVehicle::query()->where('provider_id', $trip->provider_id)->where('status', 'active')->orderBy('vehicle_code')->get())->resolve(),
            'drivers' => TransportDriverResource::collection(TransportDriver::query()->where('provider_id', $trip->provider_id)->where('status', 'active')->orderBy('full_name')->get())->resolve(),
            'can' => ['dispatch' => $user->can('transport-trips.dispatch'), 'manifest' => $canManifest, 'void' => $user->can('transport-transactions.void')],
        ]);
    }

    public function dispatch(TransportDispatchRequest $request, TransportTrip $trip, TransportTripService $trips): RedirectResponse
    {
        $data = $request->validated();
        $actor = $request->user();
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

    public function manifestPdf(Request $request, TransportTrip $trip, TransportReportService $reports, TransportExportService $exports)
    {
        abort_unless($request->user()?->can('transport-manifests.view'), 403);

        return $exports->manifestPdf($reports->manifest($trip, staffTier: true), $exports->locale($request), staffTier: true);
    }

    private function providers()
    {
        return Provider::query()->whereHas('services.serviceType', fn ($q) => $q->where('code', 'transport'))->orderBy('name_en')->get(['id', 'provider_code', 'name_en', 'name_am']);
    }
}
