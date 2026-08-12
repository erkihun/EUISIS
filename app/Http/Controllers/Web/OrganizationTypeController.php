<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\OrganizationTypes\CreateOrganizationTypeAction;
use App\Actions\OrganizationTypes\DeleteOrganizationTypeAction;
use App\Actions\OrganizationTypes\RestoreOrganizationTypeAction;
use App\Actions\OrganizationTypes\UpdateOrganizationTypeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationTypeShowRequest;
use App\Http\Requests\OrganizationTypeStoreRequest;
use App\Http\Requests\OrganizationTypeUpdateRequest;
use App\Models\OrganizationType;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationTypeController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', OrganizationType::class);

        /** @var User|null $user */
        $user = Auth::user();
        $types = OrganizationType::query()
            ->orderBy('level_order')
            ->orderBy('sort_order')
            ->orderBy('name_en')
            ->get();

        return Inertia::render('OrganizationTypes/Index', [
            'types' => $types->map(fn (OrganizationType $t) => [
                'id' => $t->id,
                'code' => $t->code,
                'prefix' => $t->prefix,
                'name_en' => $t->name_en,
                'name_am' => $t->name_am,
                'description_en' => $t->description_en,
                'is_active' => $t->is_active,
                'sort_order' => $t->sort_order,
                'level_order' => $t->level_order,
                'category' => $t->category,
                'parent_allowed_types' => $t->parent_allowed_types ?? [],
                'organizations_count' => $t->organizations()->count(),
                'can' => [
                    'update' => $user?->can('update', $t) ?? false,
                    'delete' => $user?->can('delete', $t) ?? false,
                ],
            ]),
            'can' => [
                'create' => $user?->can('create', OrganizationType::class) ?? false,
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', OrganizationType::class);

        return Inertia::render('OrganizationTypes/Create', [
            'allTypes' => $this->allTypesForSelect(),
        ]);
    }

    public function show(OrganizationTypeShowRequest $request, OrganizationType $organizationType): Response
    {
        return Inertia::render('OrganizationTypes/Show', [
            'type' => [
                'id' => $organizationType->id,
                'code' => $organizationType->code,
                'prefix' => $organizationType->prefix,
                'name_en' => $organizationType->name_en,
                'name_am' => $organizationType->name_am,
                'description_en' => $organizationType->description_en,
                'description_am' => $organizationType->description_am,
                'is_active' => $organizationType->is_active,
                'sort_order' => $organizationType->sort_order,
                'level_order' => $organizationType->level_order,
                'category' => $organizationType->category,
                'parent_allowed_types' => $organizationType->parent_allowed_types ?? [],
                'organizations_count' => $organizationType->organizations()->count(),
                'created_at' => $organizationType->created_at?->toISOString(),
                'updated_at' => $organizationType->updated_at?->toISOString(),
                'can' => [
                    'update' => $request->user()?->can('update', $organizationType) ?? false,
                    'delete' => $request->user()?->can('delete', $organizationType) ?? false,
                ],
            ],
        ]);
    }

    public function store(
        OrganizationTypeStoreRequest $request,
        CreateOrganizationTypeAction $action,
    ): RedirectResponse {
        $action->execute($request->validated(), $request->user());

        return to_route('organization-types.index')
            ->with('success', __('organization-types.created'));
    }

    public function edit(OrganizationType $organizationType): Response
    {
        $this->authorize('update', $organizationType);

        return Inertia::render('OrganizationTypes/Edit', [
            'type' => $organizationType,
            'allTypes' => $this->allTypesForSelect(),
        ]);
    }

    public function update(
        OrganizationTypeUpdateRequest $request,
        OrganizationType $organizationType,
        UpdateOrganizationTypeAction $action,
    ): RedirectResponse {
        $action->execute($request->validated(), $organizationType, $request->user());

        return to_route('organization-types.index')
            ->with('success', __('organization-types.updated'));
    }

    public function destroy(
        Request $request,
        OrganizationType $organizationType,
        DeleteOrganizationTypeAction $action,
    ): RedirectResponse {
        $this->authorize('delete', $organizationType);

        $action->execute($organizationType, $request->user());

        return to_route('organization-types.index')
            ->with('success', __('organization-types.deleted'));
    }

    public function restore(
        Request $request,
        string $organizationType,
        RestoreOrganizationTypeAction $action,
    ): RedirectResponse {
        $type = OrganizationType::withTrashed()->findOrFail($organizationType);

        $this->authorize('restore', $type);

        $action->execute($type, $request->user());

        return to_route('organization-types.index')
            ->with('success', __('recycle-bin.restored_successfully'));
    }

    /** @return array<int, array{code: string, name_en: string}> */
    private function allTypesForSelect(): array
    {
        return OrganizationType::query()
            ->orderBy('level_order')
            ->orderBy('name_en')
            ->get(['id', 'code', 'name_en'])
            ->map(fn (OrganizationType $t) => ['code' => $t->code, 'name_en' => $t->name_en])
            ->values()
            ->all();
    }
}
