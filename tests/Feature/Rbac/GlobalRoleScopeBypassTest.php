<?php

declare(strict_types=1);

use App\Enums\RoleScopeType;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Spatie\Permission\Models\Permission;

/**
 * Regression cover for the "global role is a blanket scope bypass" defect.
 *
 * scope_type=global must only lift organization scoping for SYSTEM modules
 * (roles, permissions, audit logs, code rules, security settings). A global
 * role holding an operational permission stays confined to its assigned
 * organizations, exactly like a scoped role.
 */
beforeEach(function (): void {
    $this->service = app(OrganizationScopeService::class);

    $type = OrganizationType::query()->create([
        'code' => 'GRB-TYPE',
        'name_en' => 'Global Role Bypass Type',
    ]);

    $this->orgA = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'GRB-A',
        'name_en' => 'Org A',
        'status' => 'active',
    ]);

    $this->orgB = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'GRB-B',
        'name_en' => 'Org B',
        'status' => 'active',
    ]);

    Permission::findOrCreate('reports.view', 'web');
    Permission::findOrCreate('audit_logs.view', 'web');
});

/** Build a user with $scopeType role holding $permission, scoped to orgA only. */
function grbUser(string $roleName, RoleScopeType $scopeType, string $permission, Organization $scopedTo): User
{
    $role = Role::query()->updateOrCreate(
        ['name' => $roleName, 'guard_name' => 'web'],
        ['scope_type' => $scopeType],
    );
    $role->syncPermissions([$permission]);

    $user = User::factory()->create();
    $user->assignRole($role);
    $user->organizationScopes()->create([
        'organization_id' => $scopedTo->id,
        'scope_type' => 'self',
        'is_active' => true,
    ]);

    return $user->fresh();
}

it('does not let a global role with an operational permission escape its organization scope', function (): void {
    $user = grbUser('GRB Global Operational', RoleScopeType::Global, 'reports.view', $this->orgA);

    expect($this->service->isUnrestricted($user))->toBeFalse()
        ->and($this->service->canAccessOrganization($user, $this->orgA->id))->toBeTrue()
        ->and($this->service->canAccessOrganization($user, $this->orgB->id))->toBeFalse();
});

it('confines a global operational role to its own organizations in the id resolver', function (): void {
    $user = grbUser('GRB Global Resolver', RoleScopeType::Global, 'reports.view', $this->orgA);

    $allowed = $this->service->allowedOrganizationIds($user);

    expect($allowed)->toContain($this->orgA->id)
        ->and($allowed)->not->toContain($this->orgB->id);
});

it('treats a global and a scoped operational role identically for organization access', function (): void {
    $global = grbUser('GRB Cmp Global', RoleScopeType::Global, 'reports.view', $this->orgA);
    $scoped = grbUser('GRB Cmp Scoped', RoleScopeType::Scoped, 'reports.view', $this->orgA);

    expect($this->service->canAccessOrganization($global, $this->orgB->id))
        ->toBe($this->service->canAccessOrganization($scoped, $this->orgB->id))
        ->toBeFalse();
});

it('still allows a global role to exercise a system permission without organization scope', function (): void {
    $user = grbUser('GRB Global System', RoleScopeType::Global, 'audit_logs.view', $this->orgA);

    expect($this->service->canExercisePermission($user, 'audit_logs.view'))->toBeTrue();
});

it('blocks an operational permission outside scope even when granted by a global role', function (): void {
    $user = grbUser('GRB Global Op Check', RoleScopeType::Global, 'reports.view', $this->orgA);

    expect($this->service->canExercisePermission($user, 'reports.view', $this->orgA->id))->toBeTrue()
        ->and($this->service->canExercisePermission($user, 'reports.view', $this->orgB->id))->toBeFalse();
});

it('keeps genuine administrative roles unrestricted', function (): void {
    $role = Role::query()->updateOrCreate(
        ['name' => 'Super Admin', 'guard_name' => 'web'],
        ['scope_type' => RoleScopeType::Global],
    );
    $role->syncPermissions(Permission::all());

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole($role);

    expect($this->service->isUnrestricted($superAdmin->fresh()))->toBeTrue()
        ->and($this->service->canAccessOrganization($superAdmin->fresh(), $this->orgB->id))->toBeTrue();
});
