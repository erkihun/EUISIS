<?php

declare(strict_types=1);

namespace App\Services\ProviderPortal;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Provider;
use App\Models\ProviderUser;
use App\Models\ProviderUserServicePermission;
use App\Models\ServiceType;
use App\Models\User;
use App\Security\Passwords\PasswordLifecycle;
use App\Security\Passwords\PasswordPolicy;
use App\Support\ProviderPortal\ProviderUserPermissionCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Provider portal accounts (`provider_users`, the `provider` guard) as an
 * administrator manages them. These are the accounts that sign in at
 * /provider/portal/login; a password an administrator sets or generates must
 * be changed at first sign-in.
 */
class ProviderUserAccountService
{
    public function __construct(
        private readonly WriteAuditLogAction $audit,
        private readonly PasswordPolicy $policy,
        private readonly PasswordLifecycle $lifecycle,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ProviderUser, 1: string|null} the account and, when generated, its one-time password
     */
    public function create(array $data, User $actor, ?Request $request = null): array
    {
        return DB::transaction(function () use ($data, $actor, $request): array {
            $provider = Provider::query()->findOrFail($data['provider_id']);
            $temporary = blank($data['password'] ?? null) ? $this->policy->generateTemporaryPassword() : null;

            $account = ProviderUser::query()->create([
                'provider_id' => $provider->id,
                'name' => trim((string) $data['name']),
                'email' => $data['email'] ?? null,
                'username' => $data['username'] ?? null,
                'phone_number' => $data['phone_number'] ?? null,
                'password' => Hash::make($temporary ?? (string) $data['password']),
                'provider_role' => $data['provider_role'],
                'status' => $data['status'] ?? 'active',
                'portal_enabled' => (bool) ($data['portal_enabled'] ?? true),
                // Someone other than the holder knows this password.
                'must_change_password' => true,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->syncPermissions($account, $provider, $data['service_permissions'] ?? [], $actor);

            $this->audit->execute(AuditEventType::ProviderUserCreated, $actor, $account, null,
                newValues: $this->auditable($account), request: $request);

            return [$account, $temporary];
        });
    }

    /**
     * The provider an account belongs to never changes: a person moving to
     * another company gets a new account there.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(ProviderUser $account, array $data, User $actor, ?Request $request = null): ProviderUser
    {
        return DB::transaction(function () use ($account, $data, $actor, $request): ProviderUser {
            $old = $this->auditable($account);
            $provider = Provider::withTrashed()->findOrFail($account->provider_id);

            $account->fill([
                'name' => trim((string) $data['name']),
                'email' => $data['email'] ?? null,
                'username' => $data['username'] ?? null,
                'phone_number' => $data['phone_number'] ?? null,
                'provider_role' => $data['provider_role'],
                'portal_enabled' => array_key_exists('portal_enabled', $data) ? (bool) $data['portal_enabled'] : $account->portal_enabled,
                'updated_by' => $actor->id,
            ])->save();

            $this->syncPermissions($account, $provider, $data['service_permissions'] ?? [], $actor);

            $this->audit->execute(AuditEventType::ProviderUserUpdated, $actor, $account, null,
                oldValues: $old, newValues: $this->auditable($account->fresh()), request: $request);

            return $account;
        });
    }

    public function suspend(ProviderUser $account, ?string $reason, User $actor, ?Request $request = null): void
    {
        $account->forceFill([
            'status' => 'suspended',
            'suspended_at' => now(),
            'suspended_by' => $actor->id,
            'suspension_reason' => $reason,
            'updated_by' => $actor->id,
        ])->save();

        // The portal re-checks the account on every request, so an open session ends at its next click.
        $this->audit->execute(AuditEventType::ProviderUserSuspended, $actor, $account, null,
            newValues: ['status' => 'suspended'], reason: $reason, request: $request);
    }

    public function activate(ProviderUser $account, User $actor, ?Request $request = null): void
    {
        $account->forceFill([
            'status' => 'active',
            'suspended_at' => null,
            'suspended_by' => null,
            'suspension_reason' => null,
            'updated_by' => $actor->id,
        ])->save();

        $this->audit->execute(AuditEventType::ProviderUserActivated, $actor, $account, null,
            newValues: ['status' => 'active'], request: $request);
    }

    /** @return string|null the one-time password, when one was generated */
    public function resetPassword(ProviderUser $account, ?string $password, User $actor): ?string
    {
        if (blank($password)) {
            return $this->lifecycle->assignTemporaryPassword($account, $actor, 'administrator_reset_provider_password');
        }

        $this->lifecycle->change($account, $password, AuditEventType::AdminPasswordReset, PasswordLifecycle::KIND_ADMIN_RESET,
            mustChange: true, actor: $actor, reason: 'administrator_reset_provider_password');

        return null;
    }

    public function delete(ProviderUser $account, User $actor, ?Request $request = null): void
    {
        $account->forceFill(['updated_by' => $actor->id])->save();
        $account->delete();

        $this->audit->execute(AuditEventType::ProviderUserDeleted, $actor, $account, null,
            oldValues: $this->auditable($account), request: $request);
    }

    public function restore(ProviderUser $account, User $actor, ?Request $request = null): void
    {
        $account->restore();
        $account->forceFill(['updated_by' => $actor->id])->save();

        $this->audit->execute(AuditEventType::ProviderUserRestored, $actor, $account, null,
            newValues: $this->auditable($account), request: $request);
    }

    /**
     * Operators get exactly the listed keys; owners and managers hold every
     * permission implicitly, so they keep none. A key the provider's services
     * do not offer is refused.
     *
     * @param  list<string>  $keys
     */
    private function syncPermissions(ProviderUser $account, Provider $provider, array $keys, User $actor): void
    {
        $keys = $account->provider_role === 'operator' ? array_values(array_unique($keys)) : [];
        $offered = ProviderUserPermissionCatalog::forProvider($provider);

        $refused = array_diff($keys, $offered);
        if ($refused !== []) {
            throw ValidationException::withMessages(['service_permissions' => __('provider-users.permission_not_offered')]);
        }

        $account->servicePermissions()->whereNotIn('permission_key', $keys)->delete();

        foreach ($keys as $key) {
            ProviderUserServicePermission::query()->firstOrCreate(
                ['provider_user_id' => $account->id, 'permission_key' => $key],
                [
                    'service_type_id' => ServiceType::query()->where('code', ProviderUserPermissionCatalog::serviceOf($key))->value('id'),
                    'is_allowed' => true,
                    'granted_by' => $actor->id,
                    'granted_at' => now(),
                ],
            );
        }
    }

    /** @return array<string, mixed> no password material */
    private function auditable(ProviderUser $account): array
    {
        return [
            'provider_id' => $account->provider_id,
            'name' => $account->name,
            'email' => $account->email,
            'username' => $account->username,
            'provider_role' => $account->provider_role,
            'status' => $account->status,
            'portal_enabled' => $account->portal_enabled,
            'service_permissions' => $account->servicePermissions()->pluck('permission_key')->all(),
        ];
    }
}
