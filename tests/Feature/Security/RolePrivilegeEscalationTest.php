<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Phase-2: privilege escalation through role assignment.
 *
 * Three controls stack here, and the tests attack each from below:
 *
 *   UserPolicy::assignRoles  — no self-assignment, and actor and target must
 *                              share an organization scope,
 *   Role::canBeAssignedBy    — a global or protected role may only be granted
 *                              by Super Admin or System Admin,
 *   AssignRolesRequest       — rejects the request if ANY named role fails
 *                              that check, so a privileged role cannot ride
 *                              along with a permitted one.
 */
beforeEach(function (): void {
    foreach (['users.viewAny', 'users.view', 'users.update', 'users.assignRoles'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $superAdmin = Role::findOrCreate('Super Admin', 'web');
    $superAdmin->forceFill(['scope_type' => 'global'])->save();
    $superAdmin->syncPermissions(Permission::all());

    $scoped = Role::findOrCreate('Organizational Admin', 'web');
    $scoped->forceFill(['scope_type' => 'scoped'])->save();
    $scoped->syncPermissions(['users.viewAny', 'users.view', 'users.update', 'users.assignRoles']);

    $harmless = Role::findOrCreate('HR Officer', 'web');
    $harmless->forceFill(['scope_type' => 'scoped'])->save();

    $type = OrganizationType::query()->create(['code' => 'ESC-TYPE', 'name_en' => 'Escalation Type']);
    $this->org = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'ESC-ORG',
        'name_en' => 'Escalation Bureau',
        'status' => 'active',
    ]);

    // Attacker and victim share one organization, so the scope check passes
    // and the ROLE guard is the only thing left standing.
    $this->attacker = escalationUser($this->org, 'Organizational Admin');
    $this->victim = escalationUser($this->org, 'HR Officer');
});

function escalationUser(Organization $organization, ?string $role = null): User
{
    $user = User::factory()->create([
        'status' => 'active',
        'default_organization_id' => $organization->id,
    ]);

    $user->organizationScopes()->create([
        'organization_id' => $organization->id,
        'scope_type' => 'self',
        'is_active' => true,
    ]);

    if ($role !== null) {
        $user->assignRole($role);
    }

    return $user;
}

function assignRoles(User $actor, User $target, array $roles): TestResponse
{
    return test()->actingAs($actor)->post(route('users.assign-roles', $target), ['roles' => $roles]);
}

test('a scoped admin in the same organization cannot grant a protected global role', function (): void {
    assignRoles($this->attacker, $this->victim, ['Super Admin'])
        ->assertSessionHasErrors();

    expect($this->victim->fresh()->hasRole('Super Admin'))->toBeFalse();
});

/* Smuggling the privileged role alongside a permitted one must not work. */
test('a protected role cannot ride along with an allowed role', function (): void {
    assignRoles($this->attacker, $this->victim, ['HR Officer', 'Super Admin'])
        ->assertSessionHasErrors();

    expect($this->victim->fresh()->hasRole('Super Admin'))->toBeFalse();
});

/* Self-assignment is refused outright by the policy, before roles are read. */
test('nobody may assign roles to themselves', function (): void {
    assignRoles($this->attacker, $this->attacker, ['Super Admin'])->assertForbidden();

    expect($this->attacker->fresh()->hasRole('Super Admin'))->toBeFalse();
});

test('a scoped admin cannot assign roles outside its organization', function (): void {
    $type = OrganizationType::query()->create(['code' => 'ESC-OTHER', 'name_en' => 'Other Type']);
    $otherOrg = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'ESC-OTHER-ORG',
        'name_en' => 'Other Bureau',
        'status' => 'active',
    ]);

    $outsider = escalationUser($otherOrg);

    assignRoles($this->attacker, $outsider, ['HR Officer'])->assertForbidden();

    expect($outsider->fresh()->hasRole('HR Officer'))->toBeFalse();
});

test('a user without users.assignRoles cannot assign anything', function (): void {
    $nobody = escalationUser($this->org);

    assignRoles($nobody, $this->victim, ['HR Officer'])->assertForbidden();
});

/* Control: the role that is supposed to be able to do this, can. */
test('a super admin can grant a protected global role', function (): void {
    $superAdmin = User::factory()->create(['status' => 'active']);
    $superAdmin->assignRole('Super Admin');

    assignRoles($superAdmin, $this->victim, ['Super Admin']);

    expect($this->victim->fresh()->hasRole('Super Admin'))->toBeTrue();
});
