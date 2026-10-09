<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\Users\CreateUserAction;
use App\Enums\AuditEventType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Support\Demo\DemoDataset;

/**
 * Demo staff and employee-portal accounts, their organization scopes (self,
 * subtree, citywide) and the portal link to an employee. Roles come from the
 * default role matrix; nothing here grants a permission directly. No Super
 * Admin is created — DatabaseSeeder / UserSeeder already provide one.
 */
class DemoUserSeeder extends DemoSeeder
{
    public function run(CreateUserAction $createUser, WriteAuditLogAction $audit, OrganizationScopeService $scopes): void
    {
        $maker = DemoDataset::requireUser(DemoDataset::MAKER_EMAIL);

        foreach (DemoDataset::users() as $email => $definition) {
            $user = $email === DemoDataset::MAKER_EMAIL ? $maker : DemoActorSeeder::ensureUser($email, $maker, $createUser);

            foreach ($definition['scopes'] as [$scopeType, $organizationKey]) {
                $this->ensureScope($user, $scopeType, $organizationKey, $maker, $audit);
            }

            if (isset($definition['employee']) && $user->employee_id === null) {
                $employee = DemoDataset::requireEmployee($definition['employee']);
                $user->forceFill(['employee_id' => $employee->id, 'employee_link_locked' => true])->save();
            }

            $scopes->clearCache($user);
        }
    }

    private function ensureScope(User $user, string $scopeType, string $organizationKey, User $maker, WriteAuditLogAction $audit): void
    {
        $organization = DemoDataset::requireOrganization($organizationKey);
        $exists = UserOrganizationScope::query()->where('user_id', $user->id)
            ->where('organization_id', $organization->id)->where('scope_type', $scopeType)->exists();
        if ($exists) {
            return;
        }

        $scope = $user->organizationScopes()->create([
            'organization_id' => $organization->id,
            'scope_type' => $scopeType,
            'is_active' => true,
            'effective_from' => DemoDataset::epoch()->toDateString(),
            'assigned_by' => $maker->id,
            'metadata' => ['demo_dataset' => DemoDataset::TAG],
        ]);

        $audit->execute(AuditEventType::UserOrganizationScopeAssigned, $maker, $scope, $organization->id,
            newValues: $scope->only(['organization_id', 'scope_type', 'is_active', 'effective_from']));
    }
}
