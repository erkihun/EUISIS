<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\OrganizationUnitTypes\ArchiveOrganizationUnitTypeAction;
use App\Actions\OrganizationUnitTypes\CreateOrganizationUnitTypeAction;
use App\Actions\OrganizationUnitTypes\RestoreOrganizationUnitTypeAction;
use App\Actions\OrganizationUnitTypes\UpdateOrganizationUnitTypeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrganizationUnitTypeRequest;
use App\Http\Requests\UpdateOrganizationUnitTypeRequest;
use App\Http\Resources\OrganizationUnitTypeResource;
use App\Models\OrganizationUnitType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationUnitTypeController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', OrganizationUnitType::class);

        $user = Auth::user();
        $types = OrganizationUnitType::query()
            ->withTrashed()
            ->orderBy('sort_order')
            ->orderBy('name_en')
            ->get();

        return Inertia::render('OrganizationUnitTypes/Index', [
            'types' => $types->map(fn (OrganizationUnitType $t) => [
                'id' => $t->id,
                'code' => $t->code,
                'prefix' => $t->prefix,
                'name_en' => $t->name_en,
                'name_am' => $t->name_am,
                'description_en' => $t->description_en,
                'is_active' => $t->is_active,
                'sort_order' => $t->sort_order,
                'deleted_at' => $t->deleted_at?->toISOString(),
                'can' => [
                    'update' => $user?->can('update', $t) ?? false,
                    'archive' => $user?->can('archive', $t) ?? false,
                    'restore' => $user?->can('restore', $t) ?? false,
                ],
            ]),
            'can' => [
                'create' => $user?->can('create', OrganizationUnitType::class) ?? false,
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', OrganizationUnitType::class);

        return Inertia::render('OrganizationUnitTypes/Create');
    }

    public function store(
        StoreOrganizationUnitTypeRequest $request,
        CreateOrganizationUnitTypeAction $action,
    ): RedirectResponse {
        $action->execute($request->validated(), $request->user());

        return to_route('organization-unit-types.index')
            ->with('flash', ['message' => 'Organization unit type created.', 'type' => 'success']);
    }

    public function show(OrganizationUnitType $organizationUnitType): Response
    {
        $this->authorize('view', $organizationUnitType);

        return Inertia::render('OrganizationUnitTypes/Show', [
            'type' => (new OrganizationUnitTypeResource($organizationUnitType))->resolve(request()),
        ]);
    }

    public function edit(OrganizationUnitType $organizationUnitType): Response
    {
        $this->authorize('update', $organizationUnitType);

        return Inertia::render('OrganizationUnitTypes/Edit', [
            'type' => $organizationUnitType,
        ]);
    }

    public function update(
        UpdateOrganizationUnitTypeRequest $request,
        OrganizationUnitType $organizationUnitType,
        UpdateOrganizationUnitTypeAction $action,
    ): RedirectResponse {
        $action->execute($request->validated(), $organizationUnitType, $request->user());

        return to_route('organization-unit-types.index')
            ->with('flash', ['message' => 'Organization unit type updated.', 'type' => 'success']);
    }

    public function archive(
        Request $request,
        OrganizationUnitType $organizationUnitType,
        ArchiveOrganizationUnitTypeAction $action,
    ): RedirectResponse {
        $this->authorize('archive', $organizationUnitType);

        $action->execute($organizationUnitType, $request->user(), $request->string('reason')->toString() ?: null, $request);

        return to_route('organization-unit-types.index')
            ->with('flash', ['message' => __('recycle-bin.deleted_successfully'), 'type' => 'success']);
    }

    public function restore(
        Request $request,
        string $organizationUnitType,
        RestoreOrganizationUnitTypeAction $action,
    ): RedirectResponse {
        /** @var OrganizationUnitType|null $type */
        $type = OrganizationUnitType::withTrashed()->findOrFail($organizationUnitType);

        $this->authorize('restore', $type);

        $action->execute($type, $request->user(), $request);

        return to_route('organization-unit-types.index')
            ->with('flash', ['message' => __('recycle-bin.restored_successfully'), 'type' => 'success']);
    }

    public function options(): JsonResponse
    {
        $this->authorize('viewAny', OrganizationUnitType::class);

        $types = OrganizationUnitType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name_en')
            ->get(['id', 'code', 'name_en', 'name_am', 'sort_order']);

        return response()->json($types);
    }
}
