<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\ManageRoles;
use App\Http\Controllers\Controller;
use App\Models\CoreRole;
use App\Models\User;
use App\Support\CorePermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * EUISIS Roles/Index, Create and Edit over the Core roles (ADR 0009). The
 * catalog is grouped as CorePermissions::CATALOG; changes are ManageRoles'.
 */
class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = ['search' => $request->string('search')->toString(), 'scope' => $request->string('scope')->toString()];
        /** @var User $user */
        $user = $request->user();
        $page = CoreRole::query()->with('permissions:core_role_id,permission')
            ->when($filters['search'] !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('name_en', 'like', '%'.$filters['search'].'%')->orWhere('key', 'like', '%'.$filters['search'].'%')))
            ->when(in_array($filters['scope'], ['scoped', 'global'], true), fn ($q) => $q->where('scope_type', $filters['scope']))
            ->orderBy('id')->paginate(25)->withQueryString();
        $holders = User::query()->whereIn('role', collect($page->items())->pluck('key'))->groupBy('role')->selectRaw('role, count(*) as total')->pluck('total', 'role');
        $manage = $this->manages($user);

        return Inertia::render('Roles/Index', [
            'roles' => [
                'data' => collect($page->items())->map(fn (CoreRole $role): array => [
                    'id' => $role->id,
                    'name' => $this->name($role),
                    'scope_type' => $role->scope_type,
                    'users_count' => (int) ($holders[$role->key] ?? 0),
                    'permissions' => $role->permissionNames(),
                    'is_super_admin' => false,
                    'can' => ['update' => $manage, 'delete' => $manage && ! $role->is_system && ! isset($holders[$role->key]), 'assignPermissions' => $manage],
                ])->values(),
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'from' => $page->firstItem(), 'to' => $page->lastItem(),
            ],
            'filters' => $filters,
            'can' => ['create' => $manage],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($this->manages($request->user()), 403);

        return Inertia::render('Roles/Create', ['permissions' => $this->catalog(), 'can' => ['assignPermissions' => true, 'manageGlobalRoles' => true]]);
    }

    public function store(Request $request, ManageRoles $roles): RedirectResponse
    {
        $roles->create($this->data($request), $request->user());

        return to_route('roles.index')->with('flash', ['message' => __('roles.created'), 'type' => 'success']);
    }

    public function edit(Request $request, CoreRole $role): Response
    {
        abort_unless($this->manages($request->user()), 403);

        return Inertia::render('Roles/Edit', [
            'role' => ['id' => $role->id, 'name' => $role->name_en, 'scope_type' => $role->scope_type, 'permissions' => $role->load('permissions')->permissionNames()],
            'permissions' => $this->catalog(),
            'can' => ['assignPermissions' => true, 'manageGlobalRoles' => ! $role->is_system],
        ]);
    }

    public function update(Request $request, CoreRole $role, ManageRoles $roles): RedirectResponse
    {
        $roles->update($role, $this->data($request), $request->user());

        return to_route('roles.index')->with('flash', ['message' => __('roles.updated'), 'type' => 'success']);
    }

    public function destroy(Request $request, CoreRole $role, ManageRoles $roles): RedirectResponse
    {
        $roles->delete($role, $request->user());

        return to_route('roles.index')->with('flash', ['message' => __('roles.deleted'), 'type' => 'success']);
    }

    /** @return array{name: string, scope_type: string, permissions: list<string>} */
    private function data(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scope_type' => ['required', Rule::in(['scoped', 'global'])],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(CorePermissions::all())],
        ]);
    }

    private function manages(?User $user): bool
    {
        return $user !== null && $user->is_active && $user->isCityWide() && $user->can('roles.manage');
    }

    private function name(CoreRole $role): string
    {
        return app()->getLocale() === 'am' && $role->name_am ? $role->name_am : $role->name_en;
    }

    /** @return array<string, list<array<string, mixed>>> group => permissions, as the EUISIS role editor lists them */
    private function catalog(): array
    {
        return collect(CorePermissions::CATALOG)->map(fn (array $permissions): array => collect($permissions)->map(fn (array $labels, string $name): array => [
            'name' => $name,
            'label_en' => $labels[0],
            'label_am' => $labels[1],
            'description_en' => in_array($name, CorePermissions::CITYWIDE_ONLY, true) ? 'Counts only for a city-wide account.' : null,
            'description_am' => in_array($name, CorePermissions::CITYWIDE_ONLY, true) ? 'ለመላው ከተማ መለያ ብቻ ይሠራል።' : null,
            'is_system' => true,
        ])->values()->all())->all();
    }
}
