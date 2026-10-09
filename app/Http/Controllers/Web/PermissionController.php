<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePermissionRequest;
use App\Http\Requests\UpdatePermissionRequest;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class PermissionController extends Controller
{
    public function __construct(private readonly WriteAuditLogAction $writeAuditLogAction) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Permission::class);

        $actor = Auth::user();

        $query = Permission::query()
            ->withCount('roles')
            ->orderBy('group')
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(static function ($q) use ($search): void {
                $q->where('name', ci_like_operator(), "%{$search}%")
                    ->orWhere('group', ci_like_operator(), "%{$search}%")
                    ->orWhere('label_en', ci_like_operator(), "%{$search}%")
                    ->orWhere('label_am', ci_like_operator(), "%{$search}%")
                    ->orWhere('description_en', ci_like_operator(), "%{$search}%")
                    ->orWhere('description_am', ci_like_operator(), "%{$search}%");
            });
        }

        if ($group = $request->string('group')->trim()->value()) {
            $query->where('group', $group);
        }

        if ($guard = $request->string('guard')->trim()->value()) {
            $query->where('guard_name', $guard);
        }

        $paginated = $query->paginate(25)->withQueryString();

        // Cheap aggregates only — no per-user computations.
        $groupCounts = Permission::query()
            ->whereNotNull('group')
            ->select('group')
            ->selectRaw('count(*) as total')
            ->groupBy('group')
            ->orderBy('group')
            ->pluck('total', 'group');

        $stats = [
            'total' => Permission::query()->count(),
            'groups' => $groupCounts->count(),
            'system' => Permission::query()->where('is_system', true)->count(),
        ];

        $guards = Permission::query()
            ->distinct()
            ->orderBy('guard_name')
            ->pluck('guard_name')
            ->values()
            ->toArray();

        return Inertia::render('Permissions/Index', [
            'permissions' => [
                'data' => PermissionResource::collection($paginated)->resolve(),
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
            ],
            'groups' => $groupCounts->keys()->values()->toArray(),
            'groupCounts' => $groupCounts->toArray(),
            'guards' => $guards,
            'stats' => $stats,
            'filters' => [
                'search' => $request->string('search')->trim()->value(),
                'group' => $request->string('group')->trim()->value(),
                'guard' => $request->string('guard')->trim()->value(),
            ],
            'can' => [
                'create' => $actor?->can('create', Permission::class) ?? false,
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Permission::class);

        $groups = Permission::query()
            ->whereNotNull('group')
            ->distinct()
            ->orderBy('group')
            ->pluck('group')
            ->values()
            ->toArray();

        return Inertia::render('Permissions/Create', [
            'groups' => $groups,
        ]);
    }

    public function store(StorePermissionRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $permission = Permission::create(array_merge(
            ['guard_name' => 'web', 'is_system' => false],
            $data,
        ));

        $this->writeAuditLogAction->execute(
            AuditEventType::PermissionCreated,
            $request->user(),
            null,
            null,
            newValues: ['name' => $permission->name, 'group' => $permission->group],
        );

        return to_route('permissions.index')
            ->with('flash', ['message' => __('permissions.created'), 'type' => 'success']);
    }

    public function show(Permission $permission): Response
    {
        $this->authorize('view', $permission);

        $permission->loadCount('roles');
        $permission->load('roles:id,name');

        return Inertia::render('Permissions/Show', [
            'permission' => (new PermissionResource($permission))->resolve(),
            'roles' => $permission->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values(),
        ]);
    }

    public function edit(Permission $permission): Response
    {
        $this->authorize('update', $permission);

        $groups = Permission::query()
            ->whereNotNull('group')
            ->distinct()
            ->orderBy('group')
            ->pluck('group')
            ->values()
            ->toArray();

        return Inertia::render('Permissions/Edit', [
            'permission' => (new PermissionResource($permission))->resolve(),
            'groups' => $groups,
        ]);
    }

    public function update(UpdatePermissionRequest $request, Permission $permission): RedirectResponse
    {
        $this->authorize('update', $permission);

        $data = $request->validated();

        if ($permission->is_system && isset($data['name']) && $data['name'] !== $permission->name) {
            return back()->withErrors(['name' => __('permissions.cannotRenameSystemPermission')])->withInput();
        }

        $old = $permission->only(['name', 'label_en', 'description_en', 'group']);
        $permission->update($data);

        $this->writeAuditLogAction->execute(
            AuditEventType::PermissionUpdated,
            $request->user(),
            null,
            null,
            oldValues: $old,
            newValues: $permission->only(['name', 'label_en', 'description_en', 'group']),
        );

        return to_route('permissions.index')
            ->with('flash', ['message' => __('permissions.updated'), 'type' => 'success']);
    }

    public function destroy(Request $request, Permission $permission): RedirectResponse
    {
        $this->authorize('delete', $permission);

        if ($permission->is_system) {
            return back()->with('flash', ['message' => __('permissions.cannotDeleteSystemPermission'), 'type' => 'error']);
        }

        $name = $permission->name;
        $permission->delete();

        $this->writeAuditLogAction->execute(
            AuditEventType::PermissionDeleted,
            $request->user(),
            null,
            null,
            oldValues: ['name' => $name],
        );

        return to_route('permissions.index')
            ->with('flash', ['message' => __('permissions.deleted'), 'type' => 'success']);
    }
}
