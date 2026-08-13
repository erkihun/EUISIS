<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Users\AssignRolesAction;
use App\Actions\Users\CreateUserAction;
use App\Actions\Users\DeactivateUserAction;
use App\Actions\Users\RestoreUserAction;
use App\Actions\Users\UpdateUserAction;
use App\Actions\Users\UploadUserProfilePhotoAction;
use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignRolesRequest;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\Users\AssignableUserRoleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(
        private readonly OrganizationScopeService $organizationScopeService,
        private readonly AssignableUserRoleService $assignableUserRoleService,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', User::class);

        /** @var User $actor */
        $actor = Auth::user();

        $usersQuery = User::query()->with('roles');
        $this->organizationScopeService->applyUserScope($usersQuery, $actor);

        $users = $usersQuery
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'status' => $u->status,
                'phone_number' => $u->phone_number,
                'gender' => $u->gender,
                'last_login_at' => $u->last_login_at?->toDateTimeString(),
                'created_at' => $u->created_at?->toDateString(),
                'roles' => $u->roles->pluck('name')->toArray(),
                'profile_photo_url' => $u->profilePhotoUrl(),
                'can' => [
                    'update' => $actor->can('update', $u),
                    'archive' => $actor->can('archive', $u),
                    'restore' => $actor->can('restore', $u),
                    'assignRoles' => $actor->can('assignRoles', $u),
                    'delete' => $actor->can('delete', $u),
                ],
            ]);

        return Inertia::render('Users/Index', [
            'users' => $users,
            'scopedUserManagement' => $this->organizationScopeService->isScopedOrganizationalAdmin($actor),
            'can' => [
                'create' => $actor->can('create', User::class),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', User::class);

        /** @var User $actor */
        $actor = Auth::user();

        return Inertia::render('Users/Create', [
            'roles' => $this->assignableRoles($actor),
            'statusOptions' => ['active', 'inactive'],
            'requiresOrganizationScope' => $this->organizationScopeService->isScopedOrganizationalAdmin($actor),
            'organizations' => Organization::query()
                ->where('status', 'active')
                // A scoped actor may only place a new user inside their own orgs.
                ->when(
                    ! $this->organizationScopeService->isUnrestricted($actor),
                    fn ($query) => $query->whereIn('id', $this->organizationScopeService->allowedOrganizationIds($actor)),
                )
                ->orderBy('name_en')
                ->get(['id', 'name_en', 'name_am']),
        ]);
    }

    /**
     * Roles the actor may offer in the assignment UI. A scoped actor (e.g.
     * Organizational Admin) never sees the elevated citywide roles — the
     * AssignRolesAction guard blocks assigning them regardless, this simply
     * keeps the dropdown honest.
     *
     * @return Collection<int, array{id: mixed, name: string}>
     */
    private function assignableRoles(User $actor): Collection
    {
        return $this->assignableUserRoleService->rolesFor($actor)
            ->map(fn ($role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'scope' => $this->assignableUserRoleService->isOrganizationScoped($role) ? 'organization' : 'global',
            ]);
    }

    public function store(UserStoreRequest $request, CreateUserAction $action, UploadUserProfilePhotoAction $photoAction): RedirectResponse
    {
        $validated = $request->validated();
        unset($validated['profile_photo']);

        /** @var User $actor */
        $actor = $request->user();

        $user = $action->execute($validated, $actor);

        if ($request->hasFile('profile_photo')) {
            $path = $photoAction->execute($user, $request->file('profile_photo'), $actor, $request);
            $user->update(['profile_photo_path' => $path]);
        }

        return to_route('users.index')
            ->with('flash', ['message' => __('users.created'), 'type' => 'success']);
    }

    public function edit(User $user): Response
    {
        $this->authorize('update', $user);

        /** @var User $actor */
        $actor = Auth::user();

        $user->load([
            'organizationScopes' => function ($query) use ($actor): void {
                if (! $this->organizationScopeService->isUnrestricted($actor)) {
                    $query->whereIn('organization_id', $this->organizationScopeService->allowedOrganizationIds($actor));
                }
            },
            'organizationScopes.organization',
        ]);

        return Inertia::render('Users/Edit', [
            'user' => array_merge(
                $user->only(['id', 'name', 'email', 'status', 'national_id', 'phone_number', 'gender']),
                [
                    'profile_photo_url' => $user->profilePhotoUrl(),
                    'organization_scopes' => $user->organizationScopes->map(fn ($s) => [
                        'id' => $s->id,
                        'organization' => $s->organization ? [
                            'id' => $s->organization->id,
                            'name_en' => $s->organization->name_en,
                            'name_am' => $s->organization->name_am,
                        ] : null,
                        'scope_type' => $s->scope_type?->value ?? $s->scope_type,
                        'effective_from' => $s->effective_from?->toDateString(),
                        'effective_to' => $s->effective_to?->toDateString(),
                        'is_active' => $s->is_active,
                    ])->values()->toArray(),
                ],
            ),
            'roles' => $this->assignableRoles($actor),
            'userRoles' => $user->getRoleNames()->toArray(),
            'organizations' => $this->scopeAssignableOrganizations($user),
            'can' => [
                'assignOrganizationScopes' => $actor->can('users.assignOrganizationScopes'),
            ],
        ]);
    }

    /**
     * Organizations selectable in the Organization Scopes section: every ACTIVE
     * organization, plus any organization this user is already scoped to even if
     * it has since become inactive — otherwise an existing scope would render
     * with a blank organization. The UI marks the latter as inactive and blocks
     * assigning them to new scopes.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function scopeAssignableOrganizations(User $user): Collection
    {
        $alreadyScopedIds = $user->organizationScopes
            ->pluck('organization_id')
            ->filter()
            ->unique()
            ->all();

        /** @var User $actor */
        $actor = Auth::user();

        return Organization::query()
            ->with('type:id,name_en,name_am')
            // A scoped actor (e.g. Organizational Admin) may only delegate
            // organizations inside their own accessible set. Already-assigned
            // organizations on the target still render so existing scopes stay
            // editable, but the actor cannot introduce a new out-of-scope one.
            ->when(
                ! $this->organizationScopeService->isUnrestricted($actor),
                fn ($query) => $query->whereIn('id', $this->organizationScopeService->allowedOrganizationIds($actor)),
            )
            ->where(function ($query) use ($alreadyScopedIds): void {
                $query->where('status', OrganizationStatus::Active->value);

                if ($alreadyScopedIds !== []) {
                    $query->orWhereIn('id', $alreadyScopedIds);
                }
            })
            ->orderBy('code')
            ->get(['id', 'organization_type_id', 'code', 'name_en', 'name_am', 'status'])
            ->map(fn (Organization $organization): array => [
                'id' => $organization->id,
                'code' => $organization->code,
                'name_en' => $organization->name_en,
                'name_am' => $organization->name_am,
                'organization_type_id' => $organization->organization_type_id,
                'status' => $organization->status instanceof \BackedEnum
                    ? $organization->status->value
                    : (string) $organization->status,
                'type' => $organization->type ? [
                    'name_en' => $organization->type->name_en,
                    'name_am' => $organization->type->name_am,
                ] : null,
            ])
            ->values();
    }

    public function update(UserUpdateRequest $request, User $user, UpdateUserAction $action, UploadUserProfilePhotoAction $photoAction): RedirectResponse
    {
        $validated = $request->validated();
        unset($validated['profile_photo']);

        /** @var User $actor */
        $actor = $request->user();

        $action->execute($validated, $user, $actor);

        if ($request->hasFile('profile_photo')) {
            $path = $photoAction->execute($user, $request->file('profile_photo'), $actor, $request);
            $user->update(['profile_photo_path' => $path]);
        }

        return to_route('users.index')
            ->with('flash', ['message' => __('users.updated'), 'type' => 'success']);
    }

    public function deactivate(Request $request, User $user, DeactivateUserAction $action): RedirectResponse
    {
        $this->authorize('archive', $user);

        /** @var User $actor */
        $actor = $request->user();

        $action->execute($user, $actor);

        return to_route('users.index')
            ->with('flash', ['message' => __('users.deactivated'), 'type' => 'success']);
    }

    public function restore(Request $request, User $user, RestoreUserAction $action): RedirectResponse
    {
        $this->authorize('restore', $user);

        /** @var User $actor */
        $actor = $request->user();

        $action->execute($user, $actor);

        return to_route('users.index')
            ->with('flash', ['message' => __('users.restored'), 'type' => 'success']);
    }

    public function assignRoles(AssignRolesRequest $request, User $user, AssignRolesAction $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $action->execute($user, $request->validated('roles', []), $actor);

        return to_route('users.index')
            ->with('flash', ['message' => __('users.roles_updated'), 'type' => 'success']);
    }
}
