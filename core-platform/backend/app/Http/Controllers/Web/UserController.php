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
 * EUISIS Users/* over Core accounts. A Core account has one role and at most
 * one scope organization (ADR 0006): the role list offers the three Core
 * roles, the highest one chosen wins, and the scope card holds one
 * organization (the account then sees it and everything under it).
 */
class UserController extends Controller
{
    /** Role id (the form's numeric id) => Core role, lowest to highest. */
    private const ROLES = [1 => 'core_viewer', 2 => 'core_officer', 3 => 'core_admin'];

    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $search = trim($request->string('search')->toString());
        $status = $request->string('status')->toString();
        $role = $request->string('role')->toString();
        $roleKey = array_search($role, array_map(fn (string $r): string => $this->roleName($r), self::ROLES), true);

        $page = User::query()
            ->when(! $actor->isCityWide(), fn ($q) => $q->where('scope_organization_id', $actor->scope_organization_id))
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->when(in_array($status, ['active', 'inactive'], true), fn ($q) => $q->where('is_active', $status === 'active'))
            ->when($roleKey !== false, fn ($q) => $q->where('role', self::ROLES[$roleKey]))
            ->orderBy('name')->orderBy('id')->paginate(50)->withQueryString();

        return Inertia::render('Users/Index', [
            'users' => $page->getCollection()->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->is_active ? 'active' : 'inactive',
                'phone_number' => null,
                'gender' => null,
                'last_login_at' => null,
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
            'roleOptions' => array_values(array_map(fn (string $r): string => $this->roleName($r), self::ROLES)),
            'scopedUserManagement' => ! $actor->isCityWide(),
            'can' => ['create' => $actor->can('users.create')],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Users/Create', [
            'roles' => $this->roles(),
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
            'role_ids.*' => ['integer', 'in:1,2,3'],
            'organization_scope_ids' => ['nullable', 'array', 'max:1'],
            'organization_scope_ids.*' => ['uuid'],
        ]);
        $scope = $this->scopeFor($request->user(), $data['organization_scope_ids'][0] ?? null);
        $result = $save->execute(null, [
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => self::ROLES[max($data['role_ids'])],
            'scope_organization_id' => $scope,
            'is_active' => $data['status'] === 'active',
        ], $request->user());
        $temporary = $result['password'];
        if (filled($data['password'] ?? null)) {
            $result['user']->update(['password' => Hash::make($data['password'])]);
            $temporary = null;
        }

        return $this->withTemporaryPassword(to_route('users.index'), $temporary, __('users.created'));
    }

    public function edit(Request $request, User $user): Response
    {
        $this->assertManageable($request->user(), $user);
        $scope = $user->scope_organization_id === null ? null : Organization::query()->find($user->scope_organization_id, ['id', 'name_en', 'name_am']);

        return Inertia::render('Users/Edit', [
            'user' => [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'status' => $user->is_active ? 'active' : 'inactive',
                'national_id' => null, 'phone_number' => null, 'gender' => null, 'profile_photo_url' => null,
                'organization_scopes' => $scope === null ? [] : [[
                    'id' => $scope->id,
                    'organization' => $scope->only(['id', 'name_en', 'name_am']),
                    'scope_type' => 'subtree',
                    'effective_from' => null,
                    'effective_to' => null,
                    'is_active' => true,
                ]],
            ],
            'roles' => $this->roles(),
            'userRoles' => [$this->roleName($user->role)],
            'organizations' => $this->organizations($request->user())->map(fn (Organization $o): array => $o->only(['id', 'name_en', 'name_am']) + ['status' => 'active'])->values(),
            'can' => ['assignOrganizationScopes' => $request->user()->can('users.update')],
            'organizationScopeUnavailable' => false,
        ]);
    }

    public function update(Request $request, User $user, SaveUser $save): RedirectResponse
    {
        $this->assertManageable($request->user(), $user);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['nullable', 'confirmed', Password::min(12)],
            'generate_temporary_password' => ['nullable', 'boolean'],
            'status' => ['required', 'in:active,inactive'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string'],
        ]);
        $names = array_map(fn (string $r): string => $this->roleName($r), self::ROLES);
        $chosen = array_keys(array_intersect($names, $data['roles']));
        if ($chosen === []) {
            throw ValidationException::withMessages(['roles' => __('validation.required', ['attribute' => 'roles'])]);
        }
        $save->execute($user, [
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => self::ROLES[max($chosen)],
            'is_active' => $data['status'] === 'active',
        ], $request->user());
        $temporary = null;
        if ($request->boolean('generate_temporary_password')) {
            $temporary = $save->resetPassword($user, $request->user());
        } elseif (filled($data['password'] ?? null)) {
            $user->update(['password' => Hash::make($data['password'])]);
        }

        return $this->withTemporaryPassword(to_route('users.index'), $temporary, __('users.updated'));
    }

    public function deactivate(Request $request, User $user, SaveUser $save): RedirectResponse
    {
        $this->assertManageable($request->user(), $user);
        $save->execute($user, ['email' => $user->email, 'is_active' => false], $request->user());

        return back()->with('flash', ['message' => __('users.deactivated'), 'type' => 'success']);
    }

    public function restore(Request $request, User $user, SaveUser $save): RedirectResponse
    {
        $this->assertManageable($request->user(), $user);
        $save->execute($user, ['email' => $user->email, 'is_active' => true], $request->user());

        return back()->with('flash', ['message' => __('users.restored'), 'type' => 'success']);
    }

    /** The scope card: the one organization the account is scoped to. */
    public function storeScope(Request $request, User $user, SaveUser $save): RedirectResponse
    {
        $this->assertManageable($request->user(), $user);
        $data = $request->validate(['organization_id' => ['required', 'uuid']]);
        $save->execute($user, ['email' => $user->email, 'scope_organization_id' => $this->scopeFor($request->user(), $data['organization_id'])], $request->user());

        return back()->with('flash', ['message' => __('users.userOrganizationScopes.saved'), 'type' => 'success']);
    }

    public function destroyScope(Request $request, User $user, SaveUser $save): RedirectResponse
    {
        $this->assertManageable($request->user(), $user);
        if (! $request->user()->isCityWide()) {
            throw ValidationException::withMessages(['organization_id' => __('Only a citywide administrator can make an account citywide.')]);
        }
        $save->execute($user, ['email' => $user->email, 'scope_organization_id' => null], $request->user());

        return back()->with('flash', ['message' => __('users.userOrganizationScopes.removed'), 'type' => 'success']);
    }

    /** @return list<array{id: int, name: string, scope: string}> */
    private function roles(): array
    {
        return array_values(array_map(fn (int $id, string $role): array => ['id' => $id, 'name' => $this->roleName($role), 'scope' => 'organization'], array_keys(self::ROLES), self::ROLES));
    }

    private function roleName(string $role): string
    {
        return CorePermissions::roleName(new User(['role' => $role]));
    }

    /** @return Collection<int, Organization> */
    private function organizations(User $actor)
    {
        return Organization::query()->where('status', 'active')
            ->when(! $actor->isCityWide(), fn ($q) => $q->whereIn('id', app(OrganizationScope::class)->organizationIds($actor) ?? []))
            ->orderBy('name_en')->get(['id', 'name_en', 'name_am']);
    }

    /** A scoped administrator places accounts inside their own organizations only. */
    private function scopeFor(User $actor, ?string $organizationId): ?string
    {
        if ($organizationId === null) {
            return $actor->isCityWide() ? null : $actor->scope_organization_id;
        }
        if (! app(OrganizationScope::class)->allows($actor, $organizationId)) {
            throw ValidationException::withMessages(['organization_scope_ids' => __('The organization is outside your organizations.')]);
        }

        return $organizationId;
    }

    private function assertManageable(User $actor, User $user): void
    {
        abort_unless($actor->isCityWide() || ($user->scope_organization_id !== null && app(OrganizationScope::class)->allows($actor, (string) $user->scope_organization_id)), 403);
    }

    private function withTemporaryPassword(RedirectResponse $redirect, ?string $temporary, string $message): RedirectResponse
    {
        if ($temporary === null) {
            return $redirect->with('flash', ['message' => $message, 'type' => 'success']);
        }

        return $redirect->with('flash', ['message' => $message.' '.__('password-policy.temporary_password_created'), 'type' => 'success', 'temporary_password' => $temporary]);
    }
}
