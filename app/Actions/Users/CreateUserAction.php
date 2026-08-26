<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\Users\Concerns\GuardsSuperAdminAssignment;
use App\Enums\AuditEventType;
use App\Models\User;
use App\Services\Security\DefaultPasswordPolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

readonly class CreateUserAction
{
    use GuardsSuperAdminAssignment;

    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        private DefaultPasswordPolicyService $defaultPasswordPolicy,
    ) {}

    public function execute(array $attributes, User $actor): User
    {
        return DB::transaction(function () use ($attributes, $actor): User {
            $roles = $attributes['roles'] ?? [];
            $organizationIds = $attributes['organization_scope_ids'] ?? [];
            $organizationId = $organizationIds[0] ?? ($attributes['organization_id'] ?? null);
            $scopeType = $attributes['scope_type'] ?? 'self';
            unset(
                $attributes['roles'],
                $attributes['role_ids'],
                $attributes['organization_id'],
                $attributes['organization_scope_ids'],
                $attributes['scope_type'],
            );

            $this->guardSuperAdminAssignment($actor, [], $roles);

            if ($organizationId !== null) {
                $attributes['default_organization_id'] = $organizationId;
            }

            $submittedPassword = $attributes['password'] ?? null;
            $usesDefaultPassword = ! is_string($submittedPassword)
                || $submittedPassword === ''
                || $this->defaultPasswordPolicy->matches($submittedPassword);

            if ($usesDefaultPassword) {
                $defaultHash = $this->defaultPasswordPolicy->canSupplyInitialPassword()
                    ? $this->defaultPasswordPolicy->configuredHash()
                    : null;

                if ($defaultHash === null) {
                    throw ValidationException::withMessages([
                        'password' => __('users.default_password_unavailable'),
                    ]);
                }

                $attributes['password'] = $defaultHash;
            } else {
                $attributes['password'] = Hash::make($submittedPassword);
            }
            $attributes['status'] = $attributes['status'] ?? 'active';

            /*
             * The administrator creating this account knows its password, so
             * the credential is shared until the holder replaces it. They are
             * held on the change-password screen at first login.
             */
            $attributes['must_change_password'] = $attributes['must_change_password'] ?? true;
            $attributes['password_changed_at'] = null;

            $user = User::query()->create($attributes);

            if (! empty($roles)) {
                $user->syncRoles($roles);

                $this->writeAuditLogAction->execute(
                    AuditEventType::PermissionChanged,
                    $actor,
                    $user,
                    null,
                    oldValues: ['roles' => []],
                    newValues: ['roles' => $roles],
                );
            }

            foreach ($organizationIds ?: array_filter([$organizationId]) as $scopedOrganizationId) {
                $scope = $user->organizationScopes()->create([
                    'organization_id' => $scopedOrganizationId,
                    'scope_type' => $scopeType,
                    'is_active' => true,
                    'effective_from' => null,
                    'assigned_by' => $actor->getKey(),
                ]);

                $this->writeAuditLogAction->execute(
                    AuditEventType::UserOrganizationScopeAssigned,
                    $actor,
                    $scope,
                    $scopedOrganizationId,
                    newValues: $scope->only(['organization_id', 'scope_type', 'is_active', 'effective_from']),
                );
            }

            $this->writeAuditLogAction->execute(
                AuditEventType::UserCreated,
                $actor,
                $user,
                null,
                newValues: [
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone_number' => $user->phone_number,
                    'gender' => $user->gender,
                    'roles' => $roles,
                ],
            );

            if ($usesDefaultPassword) {
                $this->writeAuditLogAction->execute(
                    AuditEventType::UserCreatedWithDefaultPassword,
                    $actor,
                    $user,
                    reason: 'default_initial_password_applied',
                );
            }

            return $user;
        });
    }
}
