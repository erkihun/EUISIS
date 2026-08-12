<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\RoleStoreRequest;
use App\Http\Requests\RoleUpdateRequest;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct(private readonly WriteAuditLogAction $writeAuditLogAction) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Role::class);

        /** @var User $actor */
        $actor = Auth::user();

        $roles = Role::query()
            ->withCount('users')
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'users_count' => $r->users_count,
                'permissions' => $r->permissions->pluck('name')->toArray(),
                'is_super_admin' => $r->name === 'Super Admin',
                'can' => [
                    'update' => $actor?->can('update', $r) ?? false,
                    'delete' => $actor?->can('delete', $r) ?? false,
                    'assignPermissions' => $actor?->can('assignPermissions', $r) ?? false,
                ],
            ]);

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
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
            'can' => [
                'assignPermissions' => $actor->can('roles.assignPermissions'),
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
            $role = Role::create(['name' => $request->validated('name'), 'guard_name' => 'web']);
            $role->syncPermissions($permissions);

            $this->writeAuditLogAction->execute(
                AuditEventType::RoleCreated,
                $request->user(),
                null,
                null,
                newValues: ['name' => $role->name, 'permissions' => $request->validated('permissions', [])],
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
                'permissions' => $role->permissions->pluck('name')->toArray(),
            ],
            'permissions' => $this->groupedPermissions(),
            'can' => [
                'assignPermissions' => $actor->can('assignPermissions', $role),
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
            $role->update(['name' => $request->validated('name')]);
            $role->syncPermissions($newPermissions);

            $this->writeAuditLogAction->execute(
                AuditEventType::RoleUpdated,
                $request->user(),
                null,
                null,
                oldValues: ['name' => $role->getOriginal('name'), 'permissions' => $oldPermissions],
                newValues: ['name' => $role->name, 'permissions' => $newPermissions],
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
