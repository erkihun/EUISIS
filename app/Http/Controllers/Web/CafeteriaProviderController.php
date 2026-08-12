<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Cafeteria\ArchiveCafeteriaProviderAction;
use App\Actions\Cafeteria\CreateCafeteriaProviderAction;
use App\Actions\Cafeteria\RestoreCafeteriaProviderAction;
use App\Actions\Cafeteria\UpdateCafeteriaProviderAction;
use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCafeteriaProviderRequest;
use App\Http\Requests\UpdateCafeteriaProviderRequest;
use App\Http\Resources\CafeteriaProviderResource;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaProviderAssignment;
use App\Models\Organization;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CafeteriaProviderController extends Controller
{
    public function __construct(private readonly OrganizationScopeService $orgScope) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CafeteriaProvider::class);

        $isActiveFilter = $request->string('is_active')->toString();

        $query = CafeteriaProvider::query()
            ->when($isActiveFilter === '0', fn ($q) => $q->onlyTrashed())
            ->when($isActiveFilter === '', fn ($q) => $q->withoutTrashed())
            ->when($request->string('search')->toString() !== '', function ($q) use ($request): void {
                $search = $request->string('search')->toString();
                $q->where(function ($nested) use ($search): void {
                    $nested->where('code', ci_like_operator(), "%{$search}%")
                        ->orWhere('name_en', ci_like_operator(), "%{$search}%")
                        ->orWhere('name_am', ci_like_operator(), "%{$search}%");
                });
            })
            ->orderBy('name_en');

        $providers = $query->paginate(30)->withQueryString();

        return Inertia::render('Cafeteria/Providers/Index', [
            'providers' => CafeteriaProviderResource::collection($providers)->resolve(),
            'meta' => [
                'current_page' => $providers->currentPage(),
                'last_page' => $providers->lastPage(),
                'total' => $providers->total(),
                'per_page' => $providers->perPage(),
            ],
            'filters' => $request->only(['search', 'is_active']),
            'can' => [
                'create' => $request->user()?->can('create', CafeteriaProvider::class) ?? false,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', CafeteriaProvider::class);

        return Inertia::render('Cafeteria/Providers/Create', [
            'organizations' => $this->organizationOptions($request),
        ]);
    }

    public function store(StoreCafeteriaProviderRequest $request, CreateCafeteriaProviderAction $action): RedirectResponse
    {
        $provider = $action->execute($request->validated(), $request->user(), $request);

        return to_route('cafeteria.providers.show', $provider)
            ->with('flash', ['message' => __('cafeteria.providerCreated'), 'type' => 'success']);
    }

    public function show(CafeteriaProvider $cafeteriaProvider): Response
    {
        $this->authorize('view', $cafeteriaProvider);

        $cafeteriaProvider->load([
            'branches.organization:id,name_en,name_am,code',
        ]);

        $adminUsers = CafeteriaProviderAssignment::query()
            ->where('cafeteria_provider_id', $cafeteriaProvider->id)
            ->with([
                'user:id,name,email,status',
                'branch:id,code,name_en',
                'organization:id,name_en,code',
            ])
            ->latest()
            ->get()
            ->map(fn (CafeteriaProviderAssignment $a): array => [
                'id' => $a->id,
                'provider_role' => $a->provider_role ?? $a->role,
                'is_active' => (bool) $a->is_active,
                'effective_from' => $a->effective_from?->toDateString(),
                'effective_to' => $a->effective_to?->toDateString(),
                'user' => [
                    'name' => $a->user?->name,
                    'email' => $a->user?->email,
                    'status' => $a->user?->status,
                ],
                'branch' => $a->branch ? [
                    'code' => $a->branch->code,
                    'name_en' => $a->branch->name_en,
                ] : null,
                'organization' => $a->organization ? [
                    'name_en' => $a->organization->name_en,
                    'code' => $a->organization->code,
                ] : null,
            ])
            ->values()
            ->all();

        $branches = $cafeteriaProvider->branches->map(fn ($b) => [
            'id' => $b->id,
            'code' => $b->code,
            'name_en' => $b->name_en,
            'name_am' => $b->name_am,
            'location' => $b->location,
            'contact_person' => $b->contact_person,
            'phone_number' => $b->phone_number,
            'is_active' => $b->is_active,
            'organization' => $b->organization ? [
                'name_en' => $b->organization->name_en,
                'code' => $b->organization->code,
            ] : null,
        ])->values()->all();

        return Inertia::render('Cafeteria/Providers/Show', [
            'provider' => (new CafeteriaProviderResource($cafeteriaProvider))->resolve(),
            'adminUsers' => $adminUsers,
            'branches' => $branches,
            'can' => [
                'update' => request()->user()?->can('update', $cafeteriaProvider) ?? false,
                'manageUsers' => request()->user()?->can('cafeteria_settings.update') ?? false,
            ],
        ]);
    }

    public function edit(Request $request, CafeteriaProvider $cafeteriaProvider): Response
    {
        $this->authorize('update', $cafeteriaProvider);

        $cafeteriaProvider->load('organization:id,name_en,name_am,code');

        return Inertia::render('Cafeteria/Providers/Edit', [
            'provider' => (new CafeteriaProviderResource($cafeteriaProvider))->resolve(),
            'organizations' => $this->organizationOptions($request),
        ]);
    }

    public function update(UpdateCafeteriaProviderRequest $request, CafeteriaProvider $cafeteriaProvider, UpdateCafeteriaProviderAction $action): RedirectResponse
    {
        $action->execute($cafeteriaProvider, $request->validated(), $request->user(), $request);

        return to_route('cafeteria.providers.show', $cafeteriaProvider)
            ->with('flash', ['message' => __('cafeteria.providerUpdated'), 'type' => 'success']);
    }

    public function archive(Request $request, CafeteriaProvider $cafeteriaProvider, ArchiveCafeteriaProviderAction $action): RedirectResponse
    {
        $this->authorize('archive', $cafeteriaProvider);

        $action->execute($cafeteriaProvider, $request->user(), $request->string('reason')->toString() ?: null, $request);

        return to_route('cafeteria.providers.index')
            ->with('flash', ['message' => __('cafeteria.providerArchived'), 'type' => 'success']);
    }

    public function restore(Request $request, string $cafeteriaProvider, RestoreCafeteriaProviderAction $action): RedirectResponse
    {
        $provider = CafeteriaProvider::query()->withTrashed()->findOrFail($cafeteriaProvider);

        $this->authorize('restore', $provider);

        $action->execute($provider, $request->user(), $request);

        return back()->with('flash', ['message' => __('cafeteria.providerRestored'), 'type' => 'success']);
    }

    /** @return array<int, array<string, string|null>> */
    private function organizationOptions(Request $request): array
    {
        $user = $request->user();
        $query = Organization::query()
            ->where('status', OrganizationStatus::Active)
            ->orderBy('name_en');

        $isSuperAdmin = $user?->hasRole('Super Admin') || $user?->hasRole('City Admin');
        if (! $isSuperAdmin && $user !== null) {
            $allowedIds = $this->orgScope->accessibleOrganizationIds($user);
            if ($allowedIds->isNotEmpty()) {
                $query->whereIn('id', $allowedIds);
            }
        }

        return $query->get(['id', 'name_en', 'name_am', 'code'])->toArray();
    }
}
