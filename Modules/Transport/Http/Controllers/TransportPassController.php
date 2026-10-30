<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Controllers;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Transport\Application\Services\TransportEmployeeNotifier;
use Modules\Transport\Domain\Models\TransportPass;
use Modules\Transport\Domain\Models\TransportRoute;
use Modules\Transport\Domain\Models\TransportRouteStop;
use Modules\Transport\Http\Requests\StoreTransportPassRequest;
use Modules\Transport\Http\Requests\UpdateTransportPassRequest;
use Modules\Transport\Http\Resources\TransportPassResource;
use Modules\Transport\Http\Resources\TransportRouteResource;
use Modules\Transport\Http\Resources\TransportRouteStopResource;

class TransportPassController extends Controller
{
    public function index(Request $request, OrganizationScopeService $scope): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-passes.viewAny'), 403);

        $inScope = $scope->employeeIdsInScope($user);
        $passes = TransportPass::query()
            ->with(['employee', 'provider', 'route', 'boardingStop', 'eveningStop'])
            ->when($inScope !== null, fn ($q) => $q->whereIn('employee_id', $inScope))
            ->when($request->string('search')->trim()->toString(), fn ($q, string $s) => $q->whereHas('employee', fn ($q) => $q->where(fn ($q) => $q
                ->where('full_name', ci_like_operator(), '%'.addcslashes($s, '%_\\').'%')
                ->orWhere('employee_number', ci_like_operator(), addcslashes($s, '%_\\').'%'))))
            ->when($request->string('status')->toString(), fn ($q, string $s) => $q->where('status', $s))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Transport/Passes/Index', [
            'passes' => TransportPassResource::collection($passes)->response()->getData(true),
            'filters' => $request->only('search', 'status'),
            'can' => [
                'create' => $user->can('transport-passes.create'),
                'update' => $user->can('transport-passes.update'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('transport-passes.create'), 403);

        return Inertia::render('Transport/Passes/Create', $this->formPayload());
    }

    public function store(StoreTransportPassRequest $request, WriteAuditLogAction $audit, TransportEmployeeNotifier $notifier): RedirectResponse
    {
        $pass = TransportPass::query()->create($request->validated() + [
            'issued_by' => $request->user()?->id,
            'issued_at' => now(),
        ]);
        $pass->load(['employee.currentAssignment', 'route']);

        $audit->execute(AuditEventType::TransportRouteAssignmentChanged, $request->user(), $pass, $pass->employee?->currentAssignment?->organization_id,
            newValues: ['action' => 'created'] + $pass->only(['employee_id', 'provider_id', 'transport_route_id', 'boarding_stop_id', 'evening_stop_id', 'valid_from', 'valid_until', 'status']), request: $request);
        if ($pass->status === 'active' && $pass->route !== null && $pass->employee !== null) {
            $notifier->routeAssigned($pass->employee, $pass->route);
        }

        return to_route('transport.passes.index')->with('flash', ['type' => 'success', 'message' => __('transport.pass_created')]);
    }

    public function edit(Request $request, TransportPass $pass, OrganizationScopeService $scope): Response
    {
        abort_unless($request->user()?->can('transport-passes.update'), 403);
        abort_unless($scope->canAccessEmployee($request->user(), $pass->employee()->with('currentAssignment')->firstOrFail()), 403);

        return Inertia::render('Transport/Passes/Edit', [
            'pass' => (new TransportPassResource($pass->load(['employee', 'provider', 'route', 'boardingStop', 'eveningStop'])))->resolve(),
            ...$this->formPayload(),
        ]);
    }

    public function update(UpdateTransportPassRequest $request, TransportPass $pass, OrganizationScopeService $scope, WriteAuditLogAction $audit, TransportEmployeeNotifier $notifier): RedirectResponse
    {
        // The pass being changed must be in scope too, not only the employee it is moved to.
        abort_unless($scope->canAccessEmployee($request->user(), $pass->employee()->with('currentAssignment')->firstOrFail()), 403);
        $before = $pass->only(['employee_id', 'provider_id', 'transport_route_id', 'boarding_stop_id', 'evening_stop_id', 'valid_from', 'valid_until', 'status']);
        $data = $request->validated();
        if (($data['status'] ?? null) === 'active' && $pass->status !== 'active') {
            $data += ['suspended_reason' => null, 'suspended_at' => null];
        }
        $pass->update($data);
        $pass->load(['employee.currentAssignment', 'route']);

        $audit->execute(AuditEventType::TransportRouteAssignmentChanged, $request->user(), $pass, $pass->employee?->currentAssignment?->organization_id,
            oldValues: $before, newValues: ['action' => 'updated'] + $pass->only(array_keys($before)), request: $request);
        if ($pass->route !== null && $pass->employee !== null && $pass->status === 'active' && ($before['transport_route_id'] !== $pass->transport_route_id || $before['boarding_stop_id'] !== $pass->boarding_stop_id || $before['evening_stop_id'] !== $pass->evening_stop_id)) {
            $notifier->routeAssigned($pass->employee, $pass->route, changed: true);
        }

        return to_route('transport.passes.index')->with('flash', ['type' => 'success', 'message' => __('transport.pass_updated')]);
    }

    /** @return array<string, mixed> */
    private function formPayload(): array
    {
        return [
            'today' => today()->toDateString(),
            'providers' => Provider::query()
                ->whereHas('services.serviceType', fn ($query) => $query->where('code', 'transport'))
                ->orderBy('name_en')
                ->get(['id', 'provider_code', 'name_en', 'name_am']),
            // Each route carries provider_id; the form lists only the chosen provider's.
            'routes' => TransportRouteResource::collection(TransportRoute::query()->where('is_active', true)->orderBy('name_en')->get())->resolve(),
            // Each stop carries transport_route_id; the form lists the chosen route's.
            'stops' => TransportRouteStopResource::collection(TransportRouteStop::query()->where('is_active', true)->orderBy('sequence')->get())->resolve(),
            // Employees are searched as you type (lookup.employees, scoped), never listed.
        ];
    }
}
