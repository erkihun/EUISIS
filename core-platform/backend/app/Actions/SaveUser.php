<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Organization;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Core accounts, managed by an administrator: role, the organization the
 * account is scoped to (none = the whole city) and whether it may sign in.
 * A new account and a password reset get a one-time password, returned once
 * and never stored in clear; a reset or deactivation ends the account's
 * sign-ins. An administrator cannot lock themselves out.
 */
final readonly class SaveUser
{
    public function __construct(private AuditWriter $audit) {}

    /**
     * @param  array{name: string, email: string, role: string, scope_organization_id?: ?string, is_active?: bool}  $data
     * @return array{user: User, password: ?string}
     */
    public function execute(?User $user, array $data, User $actor): array
    {
        return DB::transaction(function () use ($user, $data, $actor): array {
            if (User::query()->where('email', $data['email'])->when($user, fn ($q) => $q->whereKeyNot($user->id))->exists()) {
                throw ValidationException::withMessages(['email' => 'An account with this e-mail already exists.']);
            }
            if (($data['scope_organization_id'] ?? null) !== null && ! Organization::query()->whereKey($data['scope_organization_id'])->exists()) {
                throw ValidationException::withMessages(['scope_organization_id' => 'Choose an existing organization.']);
            }
            if ($user !== null && $user->is($actor) && (($data['role'] ?? $user->role) !== 'core_admin' || ($data['is_active'] ?? true) === false)) {
                throw ValidationException::withMessages(['role' => 'You cannot remove your own administrator access.']);
            }

            $password = null;
            $old = $user?->only(['name', 'email', 'role', 'scope_organization_id', 'is_active']);
            if ($user === null) {
                $password = Str::password(16);
                $user = new User(['password' => $password, 'is_active' => true]);
            }
            $user->fill($data)->save();
            if ($old !== null && ($old['is_active'] && ! $user->is_active)) {
                $user->tokens()->delete();
            }
            $this->audit->writeFor($old === null ? 'user.created' : 'user.updated', $actor, 'User', (string) $user->id, null, $old,
                $user->only(['name', 'email', 'role', 'scope_organization_id', 'is_active']));

            return ['user' => $user, 'password' => $password];
        });
    }

    public function resetPassword(User $user, User $actor): string
    {
        return DB::transaction(function () use ($user, $actor): string {
            $password = Str::password(16);
            $user->update(['password' => $password]);
            $user->tokens()->delete();
            $this->audit->writeFor('user.password_reset', $actor, 'User', (string) $user->id, null, null, null);

            return $password;
        });
    }
}
