<?php

declare(strict_types=1);

namespace App\Services\OrganizationScope;

use App\Models\User;

/**
 * Accounts with no organization-scope record that need an administrator's
 * decision (ADR 0003): those holding an organization-level role (confined to
 * their own organization, or to nothing without a linked employee) and those
 * given permissions directly without a role (still unscoped). Ids, names and
 * roles only, never e-mail, phone or national ID.
 */
final readonly class ScopeGapReport
{
    public function __construct(private OrganizationScopeService $scope) {}

    /** @return list<array{id: int|string, name: string, roles: list<string>, finding: string}> */
    public function rows(): array
    {
        $rows = [];

        User::query()
            ->whereDoesntHave('organizationScopes')
            ->with(['roles:id,name,scope_type', 'permissions:id,name'])
            ->lazyById(500)
            ->each(function (User $user) use (&$rows): void {
                if ($this->scope->hasUnrestrictedRole($user)) {
                    return;
                }
                $finding = match (true) {
                    $this->scope->holdsOrganizationLevelRole($user) => $user->employee_id === null ? 'CONFINED_NO_ACCESS' : 'CONFINED_OWN_ORGANIZATION',
                    $user->roles->isEmpty() && $user->permissions->isNotEmpty() => 'DIRECT_PERMISSIONS_UNSCOPED',
                    default => null,
                };
                if ($finding !== null) {
                    $rows[] = ['id' => $user->id, 'name' => (string) $user->name, 'roles' => $user->roles->pluck('name')->sort()->values()->all(), 'finding' => $finding];
                }
            });

        return $rows;
    }
}
