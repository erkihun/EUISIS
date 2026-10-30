<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Providers\Domain\Models\Provider;
use Modules\Transport\Application\Services\TransportTripPlanner;
use Modules\Transport\Domain\Models\TransportDriver;
use Modules\Transport\Domain\Models\TransportRoute;
use Modules\Transport\Domain\Models\TransportSchedule;
use Modules\Transport\Domain\Models\TransportServiceCalendarDay;
use Modules\Transport\Domain\Models\TransportVehicle;
use Modules\Transport\Http\Requests\StoreTransportScheduleRequest;
use Modules\Transport\Http\Resources\TransportDriverResource;
use Modules\Transport\Http\Resources\TransportRouteResource;
use Modules\Transport\Http\Resources\TransportScheduleResource;
use Modules\Transport\Http\Resources\TransportVehicleResource;

class TransportScheduleController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-schedules.viewAny'), 403);

        $schedules = TransportSchedule::query()->with(['route', 'provider', 'defaultVehicle'])
            ->when($request->provider_id, fn ($q, $id) => $q->where('provider_id', $id))
            ->when($request->route_id, fn ($q, $id) => $q->where('transport_route_id', $id))
            ->orderBy('planned_departure')->paginate(25)->withQueryString();

        return Inertia::render('Transport/Schedules/Index', [
            'schedules' => TransportScheduleResource::collection($schedules)->response()->getData(true),
            'calendarDays' => TransportServiceCalendarDay::query()->with(['provider:id,provider_code,name_en,name_am', 'route:id,route_code,name_en,name_am'])
                ->whereDate('calendar_date', '>=', today()->subDays(7))->orderBy('calendar_date')->limit(60)->get(),
            'filters' => $request->only('provider_id', 'route_id'),
            'providers' => $this->providers(),
            'can' => ['create' => $user->can('transport-schedules.create'), 'update' => $user->can('transport-schedules.update'), 'plan' => $user->can('transport-trips.create')],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('transport-schedules.create'), 403);

        return Inertia::render('Transport/Schedules/Create', $this->formPayload());
    }

    public function store(StoreTransportScheduleRequest $request): RedirectResponse
    {
        TransportSchedule::query()->create($request->validated() + ['created_by' => $request->user()?->id]);

        return to_route('transport.schedules.index')->with('flash', ['type' => 'success', 'message' => __('transport.schedule_saved')]);
    }

    public function edit(Request $request, TransportSchedule $schedule): Response
    {
        abort_unless($request->user()?->can('transport-schedules.update'), 403);

        return Inertia::render('Transport/Schedules/Edit', ['schedule' => (new TransportScheduleResource($schedule->load(['route', 'provider'])))->resolve(), ...$this->formPayload()]);
    }

    public function update(StoreTransportScheduleRequest $request, TransportSchedule $schedule): RedirectResponse
    {
        $schedule->update($request->validated() + ['updated_by' => $request->user()?->id]);

        return to_route('transport.schedules.index')->with('flash', ['type' => 'success', 'message' => __('transport.schedule_saved')]);
    }

    /** Plan trips for a date from the schedules (the scheduler does this nightly; here for an operator). */
    public function plan(Request $request, TransportTripPlanner $planner): RedirectResponse
    {
        abort_unless($request->user()?->can('transport-trips.create'), 403);
        $data = $request->validate(['date' => ['required', 'date'], 'provider_id' => ['nullable', 'uuid', 'exists:providers,id']]);
        $counts = $planner->planDate(Carbon::parse($data['date']), $data['provider_id'] ?? null);

        return back()->with('flash', ['type' => 'success', 'message' => __('transport.trips_planned', $counts)]);
    }

    public function storeCalendarDay(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('transport-schedules.update'), 403);
        $data = $request->validate([
            'calendar_date' => ['required', 'date'],
            'kind' => ['required', 'in:operating,suspended'],
            'provider_id' => ['nullable', 'uuid', 'exists:providers,id'],
            'transport_route_id' => ['nullable', 'uuid', 'exists:transport_routes,id'],
            'name_en' => ['nullable', 'string', 'max:160'],
            'name_am' => ['nullable', 'string', 'max:160'],
        ]);
        TransportServiceCalendarDay::query()->create($data + ['created_by' => $request->user()?->id]);

        return back()->with('flash', ['type' => 'success', 'message' => __('transport.calendar_day_saved')]);
    }

    public function destroyCalendarDay(Request $request, TransportServiceCalendarDay $calendarDay): RedirectResponse
    {
        abort_unless($request->user()?->can('transport-schedules.update'), 403);
        $calendarDay->delete();

        return back()->with('flash', ['type' => 'success', 'message' => __('transport.calendar_day_removed')]);
    }

    /** @return array<string, mixed> */
    private function formPayload(): array
    {
        return [
            'today' => today()->toDateString(),
            'providers' => $this->providers(),
            'routes' => TransportRouteResource::collection(TransportRoute::query()->where('is_active', true)->orderBy('name_en')->get())->resolve(),
            'vehicles' => TransportVehicleResource::collection(TransportVehicle::query()->where('status', 'active')->orderBy('vehicle_code')->get())->resolve(),
            'drivers' => TransportDriverResource::collection(TransportDriver::query()->where('status', 'active')->orderBy('full_name')->get())->resolve(),
        ];
    }

    private function providers()
    {
        return Provider::query()->whereHas('services.serviceType', fn ($q) => $q->where('code', 'transport'))->orderBy('name_en')->get(['id', 'provider_code', 'name_en', 'name_am']);
    }
}
