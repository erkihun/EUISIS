<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\RoleStoreRequest;
use App\Http\Requests\RoleUpdateRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function __construct(private readonly WriteAuditLogAction $writeAuditLogAction) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Role::class);

        /** @var User $actor */
        $actor = Auth::user();

        // The same filter bar as every other list: search by name and the role's scope, kept in the query string.
        // Module = PermissionCatalog category: a role matches when it holds any permission of that module.
        $scope = $request->string('scope')->toString();
        $modules = PermissionCatalog::modules();
        $module = $request->string('module')->toString();
        $filters = [
            'search' => $request->string('search')->trim()->toString(),
            'scope' => in_array($scope, ['global', 'scoped'], true) ? $scope : '',
            'module' => array_key_exists($module, $modules) ? $module : '',
        ];
        $groupModule = self::groupModuleMap($modules);

        $base = Role::query()
            ->when($filters['search'] !== '', fn ($query) => $query->where('name', ci_like_operator(), '%'.$filters['search'].'%'))
            ->when($filters['module'] !== '', fn ($query) => $query->whereHas(
                'permissions',
                fn ($q) => $q->whereIn('group', $modules[$filters['module']]['groups']),
            ));

        $scopeCounts = (clone $base)->toBase()
            ->selectRaw('scope_type, count(*) as total')
            ->groupBy('scope_type')
            ->pluck('total', 'scope_type');

        $roles = (clone $base)
            ->withCount('users')
            ->with('permissions:id,name,group')
            ->when($filters['scope'] !== '', fn ($query) => $query->where('scope_type', $filters['scope']))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'name_am' => $r->name_am,
                'scope_type' => $r->scope_type->value,
                'users_count' => $r->users_count,
                'permissions' => $r->permissions->pluck('name')->toArray(),
                // Module key => number of the role's permissions in it, largest first.
                'modules' => $r->permissions
                    ->countBy(fn ($p): string => $groupModule[$p->group] ?? 'other')
                    ->sortDesc()
                    ->all(),
                'is_super_admin' => $r->name === 'Super Admin',
                'can' => [
                    'update' => $actor?->can('update', $r) ?? false,
                    'delete' => $actor?->can('delete', $r) ?? false,
                    'assignPermissions' => $actor?->can('assignPermissions', $r) ?? false,
                ],
            ]);

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'filters' => $filters,
            'modules' => self::moduleList($modules),
            'scopeCounts' => [
                'global' => (int) ($scopeCounts['global'] ?? 0),
                'scoped' => (int) ($scopeCounts['scoped'] ?? 0),
            ],
            'can' => [
                'create' => $actor?->can('create', Role::class) ?? false,
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Role::class);

        /** @var User $actor */
        $actor = Auth::user();

        return Inertia::render('Roles/Create', [
            'permissions' => $this->groupedPermissions(),
            'modules' => self::moduleList(PermissionCatalog::modules()),
            'can' => [
                'assignPermissions' => $actor->can('roles.assignPermissions'),
                'manageGlobalRoles' => $actor->hasAnyRole(['Super Admin', 'System Admin']),
            ],
        ]);
    }

    public function store(RoleStoreRequest $request): RedirectResponse
    {
        $permissions = $request->validated('permissions', []);

        // Creating a role always seeds its initial permission set, so this is
        // an assignment action in substance even though "create" is the
        // triggering route — require the dedicated roles.assignPermissions
        // permission, not just roles.create, before anything is persisted.
        // No Role instance exists yet, so this checks the raw permission
        // rather than going through RolePolicy::assignPermissions() (which is
        // keyed to an existing role, e.g. to protect "Super Admin" by name).
        if ($permissions !== [] && ! $request->user()?->can('roles.assignPermissions')) {
            abort(403);
        }

        DB::transaction(function () use ($request, $permissions): void {
            $role = Role::create([
                'name' => $request->validated('name'),
                'name_am' => $request->validated('name_am'),
                'guard_name' => 'web',
                'scope_type' => $request->validated('scope_type'),
            ]);
            $role->syncPermissions($permissions);

            $this->writeAuditLogAction->execute(
                AuditEventType::RoleCreated,
                $request->user(),
                null,
                null,
                newValues: [
                    'name' => $role->name,
                    'scope_type' => $role->scope_type->value,
                    'permissions' => $request->validated('permissions', []),
                ],
            );
        });

        return to_route('roles.index')
            ->with('flash', ['message' => 'Role created.', 'type' => 'success']);
    }

    public function edit(Role $role): Response
    {
        $this->authorize('update', $role);

        /** @var User $actor */
        $actor = Auth::user();

        return Inertia::render('Roles/Edit', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'name_am' => $role->name_am,
                'scope_type' => $role->scope_type->value,
                'permissions' => $role->permissions->pluck('name')->toArray(),
            ],
            'permissions' => $this->groupedPermissions(),
            'modules' => self::moduleList(PermissionCatalog::modules()),
            'can' => [
                'assignPermissions' => $actor->can('assignPermissions', $role),
                'manageGlobalRoles' => $actor->hasAnyRole(['Super Admin', 'System Admin']),
            ],
        ]);
    }

    public function update(RoleUpdateRequest $request, Role $role): RedirectResponse
    {
        $newPermissions = $request->validated('permissions', []);
        $oldPermissionNames = $role->permissions->pluck('name')->sort()->values()->all();
        $permissionsChanging = $oldPermissionNames !== collect($newPermissions)->sort()->values()->all();

        // roles.update lets an actor rename a role; changing WHAT it grants is
        // a distinct, more sensitive action and requires roles.assignPermissions.
        if ($permissionsChanging && ! $request->user()?->can('assignPermissions', $role)) {
            abort(403);
        }

        DB::transaction(function () use ($request, $role, $newPermissions): void {
            $oldPermissions = $role->permissions->pluck('name')->toArray();
            $oldScopeType = $role->scope_type->value;
            $role->update([
                'name' => $request->validated('name'),
                'name_am' => $request->validated('name_am'),
                'scope_type' => $request->validated('scope_type'),
            ]);
            $role->syncPermissions($newPermissions);

            $this->writeAuditLogAction->execute(
                AuditEventType::RoleUpdated,
                $request->user(),
                null,
                null,
                oldValues: ['name' => $role->getOriginal('name'), 'scope_type' => $oldScopeType, 'permissions' => $oldPermissions],
                newValues: ['name' => $role->name, 'scope_type' => $role->scope_type->value, 'permissions' => $newPermissions],
            );
        });

        return to_route('roles.index')
            ->with('flash', ['message' => 'Role updated.', 'type' => 'success']);
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('delete', $role);

        DB::transaction(function () use ($request, $role): void {
            $this->writeAuditLogAction->execute(
                AuditEventType::RoleDeleted,
                $request->user(),
                null,
                null,
                oldValues: ['name' => $role->name],
            );
            $role->delete();
        });

        return to_route('roles.index')
            ->with('flash', ['message' => 'Role deleted.', 'type' => 'success']);
    }

    /**
     * @param  array<string, array{name: string, groups: list<string>}>  $modules
     * @return array<string, string> group => module key
     */
    private static function groupModuleMap(array $modules): array
    {
        $map = [];
        foreach ($modules as $key => $module) {
            foreach ($module['groups'] as $group) {
                $map[$group] = $key;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, array{name: string, groups: list<string>}>  $modules
     * @return list<array{key: string, name: string, groups: list<string>}>
     */
    private static function moduleList(array $modules): array
    {
        return collect($modules)
            ->map(static fn (array $m, string $key): array => ['key' => $key] + $m)
            ->values()
            ->all();
    }

    private function groupedPermissions(): array
    {
        return Permission::query()
            ->orderBy('group')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'label_en', 'label_am', 'description_en', 'description_am', 'group', 'sort_order', 'is_system'])
            ->groupBy(fn ($p) => $p->group ?? explode('.', $p->name)[0])
            ->map(fn ($group) => $group->map(fn ($p) => [
                'name' => $p->name,
                'label_en' => $p->label_en,
                'label_am' => $p->label_am,
                'description_en' => $p->description_en,
                'description_am' => $p->description_am,
                'is_system' => (bool) $p->is_system,
            ])->values()->toArray())
            ->toArray();
    }
}
