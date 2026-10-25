<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Organization;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Core accounts, managed by an administrator: role, an explicit scope (ADR
 * 0008: citywide, subtree or self of an organization, or none = no
 * organizational access) and whether it may sign in. Only a city-wide
 * administrator grants city-wide access; a scoped administrator places
 * accounts inside their own organizations. A new account and a password reset
 * get a one-time password, returned once and never stored in clear; any change
 * of access ends the account's sign-ins. An administrator cannot lock
 * themselves out, and the last city-wide administrator cannot be removed.
 */
final readonly class SaveUser
{
    public function __construct(private AuditWriter $audit, private OrganizationScope $scope) {}

    /**
     * @param  array{name: string, email: string, role: string, scope_type?: ?string, scope_organization_id?: ?string, is_active?: bool}  $data
     * @return array{user: User, password: ?string}
     */
    public function execute(?User $user, array $data, User $actor): array
    {
        return DB::transaction(function () use ($user, $data, $actor): array {
            abort_unless($actor->is_active && $actor->role === 'core_admin', 403);
            if ($user !== null) {
                $user = User::query()->lockForUpdate()->findOrFail($user->id);
                abort_unless($this->manages($actor, $user), 403);
            }
            [$type, $organizationId] = $this->targetScope($data, $user);
            // Grants beyond the administrator's own reach are refused outright (the forms check first, with field messages).
            abort_if($type === User::SCOPE_CITYWIDE && ! $actor->isCityWide(), 403, 'Only a city-wide administrator can grant city-wide access.');
            abort_if($organizationId !== null && ! $this->scope->allows($actor, $organizationId), 403, 'You do not have access to this organization.');
            $data['scope_type'] = $type;
            $data['scope_organization_id'] = $organizationId;

            if (User::query()->where('email', $data['email'])->when($user, fn ($q) => $q->whereKeyNot($user->id))->exists()) {
                throw ValidationException::withMessages(['email' => 'An account with this e-mail already exists.']);
            }
            if ($user !== null && $user->is($actor) && (($data['role'] ?? $user->role) !== 'core_admin' || ($data['is_active'] ?? true) === false
                || $type !== $user->scope_type || (string) $organizationId !== (string) $user->scope_organization_id)) {
                throw ValidationException::withMessages(['role' => 'You cannot remove or narrow your own administrator access.']);
            }
            if ($user !== null && $this->isCityAdministrator($user)
                && ! $this->isCityAdministrator(new User(['role' => $data['role'] ?? $user->role, 'scope_type' => $type, 'is_active' => $data['is_active'] ?? $user->is_active]))
                && ! User::query()->whereKeyNot($user->id)->where('role', 'core_admin')->where('scope_type', User::SCOPE_CITYWIDE)
                    ->where('is_active', true)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['role' => 'This is the last active city-wide administrator; appoint another one first.']);
            }

            $password = null;
            $old = $user?->only(['name', 'email', 'role', 'scope_type', 'scope_organization_id', 'is_active']);
            if ($user === null) {
                $password = Str::password(16);
                $user = new User(['password' => $password, 'is_active' => true]);
                $user->must_change_password = true;
            }
            $user->fill($data)->save();
            if ($old !== null && ($old['is_active'] !== $user->is_active || $old['role'] !== $user->role
                || $old['scope_type'] !== $user->scope_type || $old['scope_organization_id'] !== $user->scope_organization_id)) {
                // Access changed: every sign-in of the account ends (API tokens and browser sessions).
                $user->tokens()->delete();
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            $this->audit->writeFor($old === null ? 'user.created' : 'user.updated', $actor, 'User', (string) $user->id, $organizationId, $old,
                $user->only(['name', 'email', 'role', 'scope_type', 'scope_organization_id', 'is_active']));

            return ['user' => $user, 'password' => $password];
        });
    }

    public function resetPassword(User $user, User $actor): string
    {
        return DB::transaction(function () use ($user, $actor): string {
            abort_unless($actor->is_active && $actor->role === 'core_admin', 403);
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($this->manages($actor, $user), 403);
            $password = Str::password(16);
            $user->forceFill(['password' => $password, 'must_change_password' => true])->save();
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $this->audit->writeFor('user.password_reset', $actor, 'User', (string) $user->id, null, null, null);

            return $password;
        });
    }

    /** City-wide administrators manage every account; a scoped one only accounts inside their organizations. */
    public function manages(User $actor, User $user): bool
    {
        return $actor->isCityWide()
            || ($user->scope_organization_id !== null && in_array($user->scope_type, User::ORGANIZATION_SCOPES, true)
                && $this->scope->allows($actor, (string) $user->scope_organization_id));
    }

    /**
     * The scope the account will have. A caller that sends only an
     * organization gets subtree; sending no organization and no scope type
     * means no organizational access, never the whole city.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: ?string, 1: ?string}
     */
    private function targetScope(array $data, ?User $user): array
    {
        $type = match (true) {
            array_key_exists('scope_type', $data) => $data['scope_type'],
            array_key_exists('scope_organization_id', $data) => $data['scope_organization_id'] === null ? null : User::SCOPE_SUBTREE,
            default => $user?->scope_type,
        };
        $organizationId = array_key_exists('scope_organization_id', $data) ? $data['scope_organization_id'] : $user?->scope_organization_id;

        if ($type !== null && $type !== User::SCOPE_CITYWIDE && ! in_array($type, User::ORGANIZATION_SCOPES, true)) {
            throw ValidationException::withMessages(['scope_type' => 'Unknown scope type.']);
        }
        if (in_array($type, User::ORGANIZATION_SCOPES, true)) {
            if ($organizationId === null || ! Organization::query()->whereKey($organizationId)->exists()) {
                throw ValidationException::withMessages(['scope_organization_id' => 'Choose the organization of the scope.']);
            }

            return [$type, (string) $organizationId];
        }

        return [$type, null];
    }

    private function isCityAdministrator(User $user): bool
    {
        return $user->role === 'core_admin' && $user->isCityWide() && (bool) $user->is_active;
    }
}
