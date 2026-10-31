<?php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Permission;
use Modules\Court\Domain\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class RolesController extends Controller
{
    public function __construct()
    {
        // Enforce permission for all actions in this controller
        $this->middleware('court.perm:roles.manage');
    }

    public function index()
    {
        $roles = Role::withCount('users')
            ->with('permissions')
            ->orderBy('name')
            ->paginate(10);

        // The register (React, Court/Roles/Index).
        return Inertia::render('Court/Roles/Index', [
            'roles' => $roles->through(fn (Role $role): array => [
                'id' => (int) $role->id,
                'name' => (string) $role->name,
                'description' => $role->description,
                'permissions' => $role->permissions->pluck('name')->values()->all(),
                'users_count' => (int) $role->users_count,
                // Why Delete is disabled: the page shows roles.index.cannot_delete_{admin,assigned}.
                'delete_blocked' => match ($this->deleteBlockedReason($role, (int) $role->users_count)['key'] ?? null) {
                    'court::roles.index.cannot_delete_admin' => 'admin',
                    'court::roles.index.cannot_delete_assigned' => 'assigned',
                    default => null,
                },
            ]),
        ]);
    }

    /**
     * Why a role cannot be deleted, as a flash translation, or null when it can.
     *
     * @return array{key: string, replace: array<string, int>}|null
     */
    private function deleteBlockedReason(Role $role, int $holders): ?array
    {
        if (mb_strtolower(trim((string) $role->name)) === 'admin') {
            return ['key' => 'court::roles.index.cannot_delete_admin', 'replace' => []];
        }
        if ($holders > 0) {
            return ['key' => 'court::roles.index.cannot_delete_assigned', 'replace' => ['count' => $holders]];
        }

        return null;
    }

    public function create()
    {
        $perms = Permission::orderBy('name')->get();

        // The create form (React, Court/Roles/Create) with the permissions grouped by module.
        return Inertia::render('Court/Roles/Create', [
            'permissionsCount' => $perms->count(),
            'permissionGroups' => $this->permissionGroupsPayload($perms),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:255', 'unique:court_roles,name'],
            'description'  => ['nullable', 'string', 'max:255'],
            'permissions'  => ['array'],
            'court.permissions.*' => ['integer', 'exists:court_permissions,id'],
        ]);

        $role = Role::create([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        $role->permissions()->sync($data['permissions'] ?? []);

        return redirect()->route('court.roles.index')->with('ok', [
            'key' => 'court::messages.success.created',
            'replace' => ['resource' => __('court::messages.resources.role')],
        ]);
    }

    public function edit(Role $role)
    {
        $role->load('permissions');
        $perms = Permission::orderBy('name')->get();

        // The edit form (React, Court/Roles/Edit): the role, its permissions ticked among all of them.
        return Inertia::render('Court/Roles/Edit', [
            'role' => [
                'id' => (int) $role->id,
                'name' => (string) $role->name,
                'description' => $role->description,
                'permission_ids' => $role->permissions->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'users_count' => (int) $role->users()->count(),
            ],
            'permissionsCount' => $perms->count(),
            'permissionGroups' => $this->permissionGroupsPayload($perms),
        ]);
    }

    /** The grouped permissions as plain arrays for the React forms. */
    private function permissionGroupsPayload(Collection $perms): array
    {
        return $this->groupPermissions($perms)
            ->map(fn (array $group): array => [
                'key' => (string) $group['key'],
                'label' => (string) $group['label'],
                'permissions' => collect($group['permissions'])
                    ->map(fn (Permission $perm): array => [
                        'id' => (int) $perm->id,
                        'name' => (string) $perm->name,
                        'label' => $perm->labelLocalized ?: null,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    public function update(Request $request, Role $role)
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:255', Rule::unique('court_roles', 'name')->ignore($role->id)],
            'description'  => ['nullable', 'string', 'max:255'],
            'permissions'  => ['array'],
            'court.permissions.*' => ['integer', 'exists:court_permissions,id'],
        ]);

        $role->update([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        $role->permissions()->sync($data['permissions'] ?? []);

        return redirect()->route('court.roles.index')->with('ok', [
            'key' => 'court::messages.success.updated',
            'replace' => ['resource' => __('court::messages.resources.role')],
        ]);
    }

    public function destroy(Role $role)
    {
        // The admin role grants everything and backs every "admin" check; a role
        // still held by staff would silently strip their access. The list
        // disables Delete for both (RolesController::index sends why).
        if ($reason = $this->deleteBlockedReason($role, $role->users()->count())) {
            return back()->with('error', $reason);
        }

        $role->delete();
        return back()->with('ok', [
            'key' => 'court::messages.success.deleted',
            'replace' => ['resource' => __('court::messages.resources.role')],
        ]);
    }

    private function groupPermissions(Collection $perms): Collection
    {
        $grouped = [];

        foreach ($perms as $perm) {
            $groupKey = $this->normalizePermissionGroupKey(Str::before($perm->name, '.'));
            $grouped[$groupKey][] = $perm;
        }

        $orderedLabels = collect($this->permissionGroupLabels())
            ->filter(fn ($_, $key) => isset($grouped[$key]));

        $ordered = $orderedLabels->map(function ($label, $key) use ($grouped) {
            return [
                'key' => $key,
                'label' => $label,
                'permissions' => collect($grouped[$key])->sortBy('name'),
            ];
        });

        $remaining = collect($grouped)
            ->except($ordered->pluck('key')->all())
            ->map(fn ($items, $key) => [
                'key' => $key,
                'label' => Str::headline(str_replace(['-', '_'], ' ', $key)),
                'permissions' => collect($items)->sortBy('name'),
            ]);

        return $ordered->concat($remaining);
    }

    private function normalizePermissionGroupKey(string $key): string
    {
        $aliases = [
            'decisions' => 'decision',
        ];

        return $aliases[$key] ?? $key;
    }

    private function permissionGroupLabels(): array
    {
        return [
            'cases' => __('court::roles.groups.cases'),
            'appeals' => __('court::roles.groups.appeals'),
            'decision' => __('court::roles.groups.decisions'),
            'bench-notes' => __('court::roles.groups.bench_notes'),
            'letters' => __('court::roles.groups.letters'),
            'hearing' => __('court::roles.groups.hearings'),
            'file' => __('court::roles.groups.files'),
            'message' => __('court::roles.groups.messaging'),
            'reports' => __('court::roles.groups.reports'),
            'settings' => __('court::roles.groups.system'),
            'teams' => __('court::roles.groups.teams'),
            'users' => __('court::roles.groups.users'),
            'roles' => __('court::roles.groups.roles'),
            'permissions' => __('court::roles.groups.permissions'),
            'templates' => __('court::roles.groups.templates'),
            'applicants' => __('court::roles.groups.applicants'),
            'notes' => __('court::roles.groups.notes'),
        ];
    }
}
