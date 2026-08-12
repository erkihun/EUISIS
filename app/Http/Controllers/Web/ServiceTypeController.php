<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\ServiceTypes\ArchiveServiceTypeAction;
use App\Actions\ServiceTypes\CreateServiceTypeAction;
use App\Actions\ServiceTypes\RestoreServiceTypeAction;
use App\Actions\ServiceTypes\UpdateServiceTypeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServiceTypeRequest;
use App\Http\Requests\UpdateServiceTypeRequest;
use App\Http\Resources\ServiceTypeResource;
use App\Models\ServiceType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServiceTypeController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ServiceType::class);

        $serviceTypes = ServiceType::query()
            ->withCount(['providers', 'entitlementRules'])
            ->when($request->string('search')->toString() !== '', function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function ($nested) use ($search): void {
                    $nested->where('code', ci_like_operator(), "%{$search}%")
                        ->orWhere('name_en', ci_like_operator(), "%{$search}%")
                        ->orWhere('name_am', ci_like_operator(), "%{$search}%");
                });
            })
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name_en')
            ->get();

        return Inertia::render('ServiceTypes/Index', [
            'serviceTypes' => ServiceTypeResource::collection($serviceTypes)->resolve(),
            'filters' => $request->only(['search', 'is_active']),
            'can' => [
                'create' => $request->user()?->can('create', ServiceType::class) ?? false,
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', ServiceType::class);

        return Inertia::render('ServiceTypes/Create');
    }

    public function store(StoreServiceTypeRequest $request, CreateServiceTypeAction $action): RedirectResponse
    {
        $serviceType = $action->execute($request->validated(), $request->user());

        return to_route('service-types.show', $serviceType)
            ->with('flash', ['message' => __('service-types.created'), 'type' => 'success']);
    }

    public function show(ServiceType $serviceType): Response
    {
        $this->authorize('view', $serviceType);

        $serviceType->loadCount(['providers', 'entitlementRules', 'entitlements']);

        return Inertia::render('ServiceTypes/Show', [
            'serviceType' => (new ServiceTypeResource($serviceType))->resolve() + [
                'entitlements_count' => $serviceType->entitlements_count,
            ],
        ]);
    }

    public function edit(ServiceType $serviceType): Response
    {
        $this->authorize('update', $serviceType);

        return Inertia::render('ServiceTypes/Edit', [
            'serviceType' => (new ServiceTypeResource($serviceType))->resolve(),
        ]);
    }

    public function update(UpdateServiceTypeRequest $request, ServiceType $serviceType, UpdateServiceTypeAction $action): RedirectResponse
    {
        $action->execute($serviceType, $request->validated(), $request->user());

        return to_route('service-types.show', $serviceType)
            ->with('flash', ['message' => __('service-types.updated'), 'type' => 'success']);
    }

    public function archive(Request $request, ServiceType $serviceType, ArchiveServiceTypeAction $action): RedirectResponse
    {
        $this->authorize('archive', $serviceType);

        $action->execute($serviceType, $request->user(), $request->string('reason')->toString() ?: null, $request);

        return to_route('service-types.index')->with('flash', ['message' => __('recycle-bin.deleted_successfully'), 'type' => 'success']);
    }

    public function restore(Request $request, string $serviceType, RestoreServiceTypeAction $action): RedirectResponse
    {
        $serviceType = ServiceType::query()->withTrashed()->findOrFail($serviceType);

        $this->authorize('restore', $serviceType);

        $action->execute($serviceType, $request->user(), $request);

        return back()->with('flash', ['message' => __('recycle-bin.restored_successfully'), 'type' => 'success']);
    }
}
