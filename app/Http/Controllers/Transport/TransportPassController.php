<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransportPassRequest;
use App\Http\Requests\UpdateTransportPassRequest;
use App\Http\Resources\TransportPassResource;
use App\Http\Resources\TransportRouteResource;
use App\Models\Provider;
use App\Models\TransportPass;
use App\Models\TransportRoute;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransportPassController extends Controller
{
    public function index(Request $request, OrganizationScopeService $scope): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-passes.viewAny'), 403);

        $inScope = $scope->employeeIdsInScope($user);
        $passes = TransportPass::query()
            ->with(['employee', 'provider', 'route'])
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

    public function store(StoreTransportPassRequest $request): RedirectResponse
    {
        TransportPass::query()->create($request->validated() + [
            'issued_by' => $request->user()?->id,
            'issued_at' => now(),
        ]);

        return to_route('transport.passes.index')->with('flash', ['type' => 'success', 'message' => __('transport.pass_created')]);
    }

    public function edit(Request $request, TransportPass $pass, OrganizationScopeService $scope): Response
    {
        abort_unless($request->user()?->can('transport-passes.update'), 403);
        abort_unless($scope->canAccessEmployee($request->user(), $pass->employee()->with('currentAssignment')->firstOrFail()), 403);

        return Inertia::render('Transport/Passes/Edit', [
            'pass' => (new TransportPassResource($pass->load(['employee', 'provider', 'route'])))->resolve(),
            ...$this->formPayload(),
        ]);
    }

    public function update(UpdateTransportPassRequest $request, TransportPass $pass, OrganizationScopeService $scope): RedirectResponse
    {
        // The pass being changed must be in scope too, not only the employee it is moved to.
        abort_unless($scope->canAccessEmployee($request->user(), $pass->employee()->with('currentAssignment')->firstOrFail()), 403);
        $pass->update($request->validated());

        return to_route('transport.passes.index')->with('flash', ['type' => 'success', 'message' => __('transport.pass_updated')]);
    }

    /** @return array<string, mixed> */
    private function formPayload(): array
    {
        return [
            'providers' => Provider::query()
                ->whereHas('services.serviceType', fn ($query) => $query->where('code', 'transport'))
                ->orderBy('name_en')
                ->get(['id', 'provider_code', 'name_en', 'name_am']),
            // Each route carries provider_id; the form lists only the chosen provider's.
            'routes' => TransportRouteResource::collection(TransportRoute::query()->where('is_active', true)->orderBy('name_en')->get())->resolve(),
            // Employees are searched as you type (lookup.employees, scoped), never listed.
        ];
    }
}
