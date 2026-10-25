<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\SaveUser;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationScope;
use App\Support\CorePermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * EUISIS Users/* over Core accounts. A Core account has one role and one
 * explicit scope (ADR 0008). The role list offers the three Core roles twice:
 * as organization roles (EUISIS scope "organization"), which need an
 * organization in the scope card (the organization alone, or with everything
 * under it), and as city-wide roles (EUISIS scope "global"), the only way to
 * grant the whole city, and only by a city-wide administrator. Removing the
 * scope leaves the account without organizational access.
 */
class UserController extends Controller
{
    /** Role id (the form's numeric id) => [Core role, EUISIS role scope], lowest to highest. */
    private const ROLES = [
        1 => ['core_viewer', 'organization'], 2 => ['core_officer', 'organization'], 3 => ['core_admin', 'organization'],
        4 => ['core_viewer', 'global'], 5 => ['core_officer', 'global'], 6 => ['core_admin', 'global'],
    ];

    public function __construct(private readonly OrganizationScope $scope) {}

    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $search = trim($request->string('search')->toString());
        $status = $request->string('status')->toString();
        $role = $request->string('role')->toString();
        $roleKey = array_search($role, $this->roleNames(), true);

        $page = User::query()
            ->when(! $actor->isCityWide(), fn ($q) => $q->whereIn('scope_type', User::ORGANIZATION_SCOPES)
                ->whereIn('scope_organization_id', $this->scope->organizationIds($actor) ?? []))
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->when(in_array($status, ['active', 'inactive'], true), fn ($q) => $q->where('is_active', $status === 'active'))
            ->when($roleKey !== false, fn ($q) => $q->where('role', self::ROLES[$roleKey][0])
                ->when(self::ROLES[$roleKey][1] === 'global', fn ($g) => $g->where('scope_type', User::SCOPE_CITYWIDE), fn ($o) => $o->where(fn ($w) => $w->whereNull('scope_type')->orWhere('scope_type', '!=', User::SCOPE_CITYWIDE))))
            ->orderBy('name')->orderBy('id')->paginate(50)->withQueryString();

        return Inertia::render('Users/Index', [
            'users' => $page->getCollection()->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->is_active ? 'active' : 'inactive',
                'phone_number' => null,
                'gender' => null,
                'last_login_at' => $user->last_login_at?->toDateTimeString(),
                'created_at' => $user->created_at?->toDateString(),
                'roles' => [CorePermissions::roleName($user)],
                'profile_photo_url' => null,
                'can' => [
                    'update' => $actor->can('users.update'),
                    'archive' => $actor->can('users.update') && $user->is_active && ! $user->is($actor),
                    'restore' => $actor->can('users.restore') && ! $user->is_active,
                    'assignRoles' => $actor->can('users.update'),
                ],
            ])->values(),
            'users_pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'filters' => ['search' => $search, 'status' => in_array($status, ['active', 'inactive'], true) ? $status : '', 'role' => $role],
            'roleOptions' => array_values($this->roleNames()),
            'scopedUserManagement' => ! $actor->isCityWide(),
            'can' => ['create' => $actor->can('users.create')],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Users/Create', [
            'roles' => $this->roles($request->user()),
            'statusOptions' => ['active', 'inactive'],
            'requiresOrganizationScope' => ! $request->user()->isCityWide(),
            'organizations' => $this->organizations($request->user()),
        ]);
    }

    public function store(Request $request, SaveUser $save): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['nullable', 'confirmed', Password::min(12)],
            'status' => ['required', 'in:active,inactive'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'in:'.implode(',', array_keys(self::ROLES))],
            'organization_scope_ids' => ['nullable', 'array', 'max:1'],
            'organization_scope_ids.*' => ['uuid'],
            'scope_type' => ['nullable', 'in:self,subtree'],
        ]);
        [$role, $kind] = self::ROLES[max($data['role_ids'])];
        $result = $save->execute(null, [
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $role,
            ...$this->scopeFor($request->user(), $kind, $data['organization_scope_ids'][0] ?? null, $data['scope_type'] ?? null),
            'is_active' => $data['status'] === 'active',
        ], $request->user());
        $temporary = $result['password'];
        if (filled($data['password'] ?? null)) {
            $result['user']->update(['password' => Hash::make($data['password'])]);
            $temporary = null;
        }

        return $this->withTemporaryPassword(to_route('users.index'), $temporary, __('users.created'));
    }

    public function edit(Request $request, User $user, SaveUser $save): Response
    {
        abort_unless($save->manages($request->user(), $user), 403);
        $scope = $user->scope_organization_id === null ? null : Organization::query()->find($user->scope_organization_id, ['id', 'name_en', 'name_am']);

        return Inertia::render('Users/Edit', [
            'user' => [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'status' => $user->is_active ? 'active' : 'inactive',
                'national_id' => null, 'phone_number' => null, 'gender' => null, 'profile_photo_url' => null,
                // A city-wide account has no organization row: its city-wide role says so.
                'organization_scopes' => $user->isCityWide() || $scope === null ? [] : [[
                    'id' => $scope->id,
                    'organization' => $scope->only(['id', 'name_en', 'name_am']),
                    'scope_type' => $user->scope_type === User::SCOPE_SELF ? 'self' : 'subtree',
                    'effective_from' => null,
                    'effective_to' => null,
                    'is_active' => true,
                ]],
            ],
            'roles' => $this->roles($request->user()),
            'userRoles' => [CorePermissions::roleName($user)],
            'organizations' => $this->organizations($request->user())->map(fn (Organization $o): array => $o->only(['id', 'name_en', 'name_am']) + ['status' => 'active'])->values(),
            'can' => ['assignOrganizationScopes' => $request->user()->can('users.update')],
            'organizationScopeUnavailable' => false,
        ]);
    }

    public function update(Request $request, User $user, SaveUser $save): RedirectResponse
    {
        abort_unless($save->manages($request->user(), $user), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['nullable', 'confirmed', Password::min(12)],
            'generate_temporary_password' => ['nullable', 'boolean'],
            'status' => ['required', 'in:active,inactive'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string'],
        ]);
        $chosen = array_keys(array_intersect($this->roleNames(), $data['roles']));
        if ($chosen === []) {
            throw ValidationException::withMessages(['roles' => __('validation.required', ['attribute' => 'roles'])]);
        }
        [$role, $kind] = self::ROLES[max($chosen)];
        $scope = match (true) {
            $kind === 'global' => ['scope_type' => User::SCOPE_CITYWIDE, 'scope_organization_id' => null],
            ! $user->isCityWide() => [],
            // From city-wide to an organization role: the organization comes from the scope card first.
            default => throw ValidationException::withMessages(['roles' => __('Add an organization scope before choosing an organization role.')]),
        };
        $save->execute($user, [
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $role,
            ...$scope,
            'is_active' => $data['status'] === 'active',
        ], $request->user());
        $temporary = null;
        if ($request->boolean('generate_temporary_password')) {
            $temporary = $save->resetPassword($user, $request->user());
        } elseif (filled($data['password'] ?? null)) {
            // A password set by an administrator is replaced by its owner at the next sign-in.
            $user->forceFill(['password' => Hash::make($data['password']), 'must_change_password' => true])->save();
        }

        return $this->withTemporaryPassword(to_route('users.index'), $temporary, __('users.updated'));
    }

    public function deactivate(Request $request, User $user, SaveUser $save): RedirectResponse
    {
        abort_unless($save->manages($request->user(), $user), 403);
        $save->execute($user, ['email' => $user->email, 'is_active' => false], $request->user());

        return back()->with('flash', ['message' => __('users.deactivated'), 'type' => 'success']);
    }

    public function restore(Request $request, User $user, SaveUser $save): RedirectResponse
    {
        abort_unless($save->manages($request->user(), $user), 403);
        $save->execute($user, ['email' => $user->email, 'is_active' => true], $request->user());

        return back()->with('flash', ['message' => __('users.restored'), 'type' => 'success']);
    }

    /** The scope card: the one organization the account is scoped to, alone or with everything under it. */
    public function storeScope(Request $request, User $user, SaveUser $save): RedirectResponse
    {
        abort_unless($save->manages($request->user(), $user), 403);
        $data = $request->validate(['organization_id' => ['required', 'uuid'], 'scope_type' => ['nullable', 'in:self,subtree']]);
        $save->execute($user, [
            'email' => $user->email,
            ...$this->scopeFor($request->user(), 'organization', $data['organization_id'], $data['scope_type'] ?? null),
        ], $request->user());

        return back()->with('flash', ['message' => __('users.userOrganizationScopes.saved'), 'type' => 'success']);
    }

    /** Removing the scope leaves the account without organizational access (never the whole city). */
    public function destroyScope(Request $request, User $user, SaveUser $save): RedirectResponse
    {
        abort_unless($save->manages($request->user(), $user), 403);
        $save->execute($user, ['email' => $user->email, 'scope_type' => null, 'scope_organization_id' => null], $request->user());

        return back()->with('flash', ['message' => __('users.userOrganizationScopes.removed'), 'type' => 'success']);
    }

    /**
     * The roles a form offers; the city-wide ones only to a city-wide administrator.
     *
     * @return list<array{id: int, name: string, scope: string}>
     */
    private function roles(User $actor): array
    {
        $roles = [];
        foreach (self::ROLES as $id => [$role, $kind]) {
            if ($kind === 'organization' || $actor->isCityWide()) {
                $roles[] = ['id' => $id, 'name' => $this->roleNames()[$id], 'scope' => $kind];
            }
        }

        return $roles;
    }

    /** @return array<int, string> role id => the name the forms show */
    private function roleNames(): array
    {
        return array_map(fn (array $definition): string => CorePermissions::roleName(new User([
            'role' => $definition[0],
            'scope_type' => $definition[1] === 'global' ? User::SCOPE_CITYWIDE : User::SCOPE_SUBTREE,
        ])), self::ROLES);
    }

    /** @return Collection<int, Organization> */
    private function organizations(User $actor)
    {
        return Organization::query()->where('status', 'active')
            ->when(! $actor->isCityWide(), fn ($q) => $q->whereIn('id', $this->scope->organizationIds($actor) ?? []))
            ->orderBy('name_en')->get(['id', 'name_en', 'name_am']);
    }

    /**
     * The explicit scope a form asks for: a city-wide role is the whole city;
     * an organization role needs an organization (a scoped administrator's own
     * when none is chosen), alone (self) or with everything under it (subtree).
     *
     * @return array{scope_type: ?string, scope_organization_id: ?string}
     */
    private function scopeFor(User $actor, string $kind, ?string $organizationId, ?string $type): array
    {
        if ($kind === 'global') {
            return ['scope_type' => User::SCOPE_CITYWIDE, 'scope_organization_id' => null];
        }
        $organizationId ??= $actor->isCityWide() ? null : $actor->scope_organization_id;
        if ($organizationId === null) {
            throw ValidationException::withMessages(['organization_scope_ids' => __('Choose the organization of the scope, or a city-wide role.')]);
        }
        if (! $this->scope->allows($actor, $organizationId)) {
            throw ValidationException::withMessages(['organization_scope_ids' => __('You do not have access to this organization.')]);
        }

        return ['scope_type' => $type === 'self' ? User::SCOPE_SELF : User::SCOPE_SUBTREE, 'scope_organization_id' => $organizationId];
    }

    private function withTemporaryPassword(RedirectResponse $redirect, ?string $temporary, string $message): RedirectResponse
    {
        if ($temporary === null) {
            return $redirect->with('flash', ['message' => $message, 'type' => 'success']);
        }

        return $redirect->with('flash', ['message' => $message.' '.__('password-policy.temporary_password_created'), 'type' => 'success', 'temporary_password' => $temporary]);
    }
}
