<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransportPassRequest;
use App\Http\Requests\UpdateTransportPassRequest;
use App\Http\Resources\TransportPassResource;
use App\Http\Resources\TransportRouteResource;
use App\Models\Employee;
use App\Models\Provider;
use App\Models\TransportPass;
use App\Models\TransportRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransportPassController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user?->can('transport-passes.viewAny'), 403);

        $passes = TransportPass::query()
            ->with(['employee', 'provider', 'route'])
            ->when($request->search, fn ($q, $s) => $q->whereHas('employee', fn ($q) => $q->where('full_name', ci_like_operator(), "%{$s}%")->orWhere('employee_number', ci_like_operator(), "%{$s}%")))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(20);

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

    public function edit(Request $request, TransportPass $pass): Response
    {
        abort_unless($request->user()?->can('transport-passes.update'), 403);

        return Inertia::render('Transport/Passes/Edit', [
            'pass' => (new TransportPassResource($pass->load(['employee', 'provider', 'route'])))->resolve(),
            ...$this->formPayload(),
        ]);
    }

    public function update(UpdateTransportPassRequest $request, TransportPass $pass): RedirectResponse
    {
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
            'routes' => TransportRouteResource::collection(TransportRoute::query()->orderBy('name_en')->get())->resolve(),
            'employees' => Employee::query()
                ->orderBy('full_name')
                ->limit(500)
                ->get(['id', 'employee_number', 'full_name']),
        ];
    }
}
