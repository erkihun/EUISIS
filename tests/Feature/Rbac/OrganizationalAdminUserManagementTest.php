<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Enums\EmployeeStatus;
use App\Enums\OrganizationScopeType;
use App\Enums\RoleScopeType;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    foreach ([
        'users.viewAny', 'users.view', 'users.create', 'users.update',
        'users.deactivate', 'users.archive', 'users.restore', 'users.assignRoles',
        'users.assignOrganizationScopes', 'user-organization-scopes.viewAny',
        'user-organization-scopes.create', 'user-organization-scopes.update',
        'user-organization-scopes.delete', 'system-settings.manageSecurity',
        'employees.view', 'employees.manage',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->forceFill(['scope_type' => RoleScopeType::Global])->save();
    Role::findByName('Super Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('City Admin', 'web')->forceFill(['scope_type' => RoleScopeType::Global])->save();
    Role::findByName('City Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('Organizational Admin', 'web')->syncPermissions([
        'users.viewAny', 'users.view', 'users.create', 'users.update',
        'users.deactivate', 'users.archive', 'users.restore', 'users.assignRoles',
        'users.assignOrganizationScopes', 'user-organization-scopes.viewAny',
        'user-organization-scopes.create', 'user-organization-scopes.update',
        'user-organization-scopes.delete',
    ]);
    Role::findOrCreate('Organization Staff', 'web')->syncPermissions(['users.viewAny']);
    Role::findOrCreate('HR Officer', 'web')->syncPermissions(['employees.view', 'employees.manage']);
    Role::findOrCreate('Global Operator', 'web')->forceFill(['scope_type' => RoleScopeType::Global])->save();
    Role::findByName('Global Operator', 'web')->syncPermissions(['system-settings.manageSecurity']);

    $this->organizationType = OrganizationType::query()->create([
        'code' => 'USER-SCOPE-ORG',
        'name_en' => 'User Scope Organization',
    ]);
});

function oumOrganization(string $code): Organization
{
    return Organization::query()->create([
        'organization_type_id' => test()->organizationType->id,
        'code' => $code,
        'name_en' => 'Organization '.$code,
        'status' => 'active',
    ]);
}

function oumScope(User $user, Organization $organization): UserOrganizationScope
{
    return UserOrganizationScope::query()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
        'scope_type' => OrganizationScopeType::Self,
        'is_active' => true,
        'effective_from' => null,
    ]);
}

function oumOrganizationalAdmin(Organization $organization): User
{
    $user = User::factory()->create();
    $user->assignRole('Organizational Admin');
    oumScope($user, $organization);

    return $user;
}

function oumTarget(Organization $organization, string $email): User
{
    $user = User::factory()->create(['email' => $email]);
    oumScope($user, $organization);

    return $user;
}

function oumEmployee(Organization $organization): Employee
{
    $employee = Employee::query()->create([
        'employee_number' => 'OUM-EMP-'.uniqid(),
        'first_name' => 'Scoped',
        'last_name' => 'Employee',
        'full_name' => 'Scoped Employee',
        'status' => EmployeeStatus::Active,
    ]);

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $organization->id,
        'assignment_status' => AssignmentStatus::Active,
        'effective_from' => now()->toDateString(),
        'is_current' => true,
    ]);

    $employee->update(['current_assignment_id' => $assignment->id]);

    return $employee->fresh();
}

test('super admin sees users from every organization', function (): void {
    $first = oumOrganization('SUPER-A');
    $second = oumOrganization('SUPER-B');
    oumTarget($first, 'first@example.test');
    oumTarget($second, 'second@example.test');
    $superAdmin = User::factory()->create()->assignRole('Super Admin');

    $this->actingAs($superAdmin)
        ->get(route('users.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('scopedUserManagement', false)
            ->where('users', fn ($users) => collect($users)->pluck('email')->contains('first@example.test')
                && collect($users)->pluck('email')->contains('second@example.test'))
        );
});

test('organizational admin without an assigned scope cannot create users or see the global user list', function (): void {
    oumTarget(oumOrganization('UNASSIGNED-TARGET'), 'unassigned-target@example.test');
    $admin = User::factory()->create()->assignRole('Organizational Admin');

    $this->actingAs($admin)
        ->get(route('users.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('users', 0));

    $this->actingAs($admin)->get(route('users.create'))->assertForbidden();
});

test('organizational admin sees and edits only users in its organization scope', function (): void {
    $inside = oumOrganization('INSIDE');
    $outside = oumOrganization('OUTSIDE');
    $admin = oumOrganizationalAdmin($inside);
    $insideUser = oumTarget($inside, 'inside@example.test');
    $outsideUser = oumTarget($outside, 'outside@example.test');

    $this->actingAs($admin)
        ->get(route('users.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('scopedUserManagement', true)
            ->where('users', fn ($users) => collect($users)->pluck('email')->contains('inside@example.test')
                && ! collect($users)->pluck('email')->contains('outside@example.test'))
        );

    $this->actingAs($admin)->get(route('users.edit', $insideUser))->assertOk();
    $this->actingAs($admin)->get(route('users.edit', $outsideUser))->assertForbidden();
    $this->actingAs($admin)->patch(route('users.update', $outsideUser), [
        'name' => 'Outside Changed',
        'email' => $outsideUser->email,
    ])->assertForbidden();
});

test('organizational admin creates a user only with an allowed organization scope and role', function (): void {
    $inside = oumOrganization('CREATE-IN');
    $outside = oumOrganization('CREATE-OUT');
    $admin = oumOrganizationalAdmin($inside);
    $payload = [
        'name' => 'Scoped New User',
        'email' => 'scoped-new@example.test',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'status' => 'active',
        'roles' => ['Organization Staff'],
        'scope_type' => 'self',
    ];

    $this->actingAs($admin)
        ->post(route('users.store'), [...$payload, 'organization_id' => $outside->id])
        ->assertSessionHasErrors('organization_id');

    $this->actingAs($admin)
        ->post(route('users.store'), $payload)
        ->assertSessionHasErrors('organization_id');

    $this->actingAs($admin)
        ->post(route('users.store'), [...$payload, 'organization_id' => $inside->id])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('users.index'));

    $created = User::query()->where('email', 'scoped-new@example.test')->firstOrFail();
    expect($created->hasRole('Organization Staff'))->toBeTrue()
        ->and($created->default_organization_id)->toBe($inside->id)
        ->and($created->organizationScopes()->where('organization_id', $inside->id)->exists())->toBeTrue();

    $this->assertDatabaseHas('audit_logs', [
        'event_type' => AuditEventType::UserCreated->value,
        'auditable_id' => $created->id,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'event_type' => AuditEventType::UserOrganizationScopeAssigned->value,
        'organization_id' => $inside->id,
    ]);
});

test('organizational admin sees HR Officer as scoped and creates it with role and scope ids', function (): void {
    $inside = oumOrganization('HR-IN');
    $outside = oumOrganization('HR-OUT');
    $admin = oumOrganizationalAdmin($inside);
    $hrRole = Role::findByName('HR Officer', 'web');

    $this->actingAs($admin)
        ->get(route('users.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('roles', fn ($roles) => collect($roles)->contains(
                fn ($role) => $role['id'] === $hrRole->id
                    && $role['name'] === 'HR Officer'
                    && $role['scope'] === 'organization',
            ))
            ->where('roles', fn ($roles) => ! collect($roles)->pluck('name')->contains('Super Admin')
                && ! collect($roles)->pluck('name')->contains('System Admin'))
        );

    $this->actingAs($admin)
        ->post(route('users.store'), [
            'name' => 'Scoped HR Officer',
            'email' => 'scoped-hr@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'status' => 'active',
            'role_ids' => [$hrRole->id],
            'organization_scope_ids' => [$inside->id],
            'scope_type' => 'self',
        ])
        ->assertSessionHasNoErrors();

    $hrOfficer = User::query()->where('email', 'scoped-hr@example.test')->firstOrFail();
    $insideEmployee = oumEmployee($inside);
    $outsideEmployee = oumEmployee($outside);

    expect($hrOfficer->hasRole('HR Officer'))->toBeTrue()
        ->and($hrOfficer->organizationScopes()->where('organization_id', $inside->id)->exists())->toBeTrue();

    $this->assertDatabaseHas('audit_logs', [
        'event_type' => AuditEventType::PermissionChanged->value,
        'auditable_id' => $hrOfficer->id,
        'actor_user_id' => $admin->id,
    ]);

    /*
     * An admin-created account must change its password at first login, which
     * would otherwise redirect every request below. This test is about
     * organization scope, so the gate is satisfied first to keep the two
     * concerns from being tested through one another.
     */
    $hrOfficer->markPasswordChanged();

    $this->actingAs($hrOfficer)->get(route('employees.show', $insideEmployee))->assertOk();
    $this->actingAs($hrOfficer)->get(route('employees.show', $outsideEmployee))->assertForbidden();
});

test('organizational admin role and scope ids are required and authoritative', function (): void {
    $inside = oumOrganization('IDS-IN');
    $outside = oumOrganization('IDS-OUT');
    $admin = oumOrganizationalAdmin($inside);
    $hrRole = Role::findByName('HR Officer', 'web');
    $superRole = Role::findByName('Super Admin', 'web');
    $base = [
        'name' => 'Invalid Scoped User',
        'email' => 'invalid-ids@example.test',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'status' => 'active',
        'scope_type' => 'self',
    ];

    $this->actingAs($admin)->post(route('users.store'), $base)
        ->assertSessionHasErrors(['role_ids', 'organization_scope_ids']);
    $this->actingAs($admin)->post(route('users.store'), [
        ...$base,
        'role_ids' => [$hrRole->id],
        'organization_scope_ids' => [$outside->id],
    ])->assertSessionHasErrors('organization_scope_ids.0');
    $this->actingAs($admin)->post(route('users.store'), [
        ...$base,
        'role_ids' => [$superRole->id],
        'organization_scope_ids' => [$inside->id],
    ])->assertSessionHasErrors('role_ids');
});

test('super admin sees global and scoped roles but scoped role assignment requires organization scope', function (): void {
    $superAdmin = User::factory()->create()->assignRole('Super Admin');
    $hrRole = Role::findByName('HR Officer', 'web');

    $this->actingAs($superAdmin)
        ->get(route('users.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('requiresOrganizationScope', false)
            ->where('roles', fn ($roles) => collect($roles)->pluck('name')->contains('Super Admin')
                && collect($roles)->pluck('name')->contains('HR Officer'))
        );

    $this->actingAs($superAdmin)->post(route('users.store'), [
        'name' => 'Global Created User',
        'email' => 'global-created@example.test',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'status' => 'active',
        'role_ids' => [$hrRole->id],
    ])->assertSessionHasErrors('organization_scope_ids');

    $globalRole = Role::findByName('Global Operator', 'web');
    $this->actingAs($superAdmin)->post(route('users.store'), [
        'name' => 'Global Created User',
        'email' => 'global-created@example.test',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'status' => 'active',
        'role_ids' => [$globalRole->id],
    ])->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'global-created@example.test')->firstOrFail()->hasRole('Global Operator'))->toBeTrue();
});

test('organizational admin cannot grant protected or globally privileged roles on create', function (string $role): void {
    $organization = oumOrganization('ROLE-BLOCK');
    $admin = oumOrganizationalAdmin($organization);

    $this->actingAs($admin)
        ->post(route('users.store'), [
            'name' => 'Escalated User',
            'email' => 'escalated-'.str($role)->slug().'@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'status' => 'active',
            'roles' => [$role],
            'organization_id' => $organization->id,
            'scope_type' => 'self',
        ])
        ->assertSessionHasErrors('roles');

    expect(User::query()->where('name', 'Escalated User')->exists())->toBeFalse();
})->with(['Super Admin', 'City Admin', 'Global Operator']);

test('organizational admin create and edit props exclude outside organizations and global roles', function (): void {
    $inside = oumOrganization('PROPS-IN');
    $outside = oumOrganization('PROPS-OUT');
    $admin = oumOrganizationalAdmin($inside);
    $target = oumTarget($inside, 'props-target@example.test');

    $assertScopedProps = fn (Assert $page) => $page
        ->where('organizations', fn ($organizations) => collect($organizations)->pluck('id')->all() === [$inside->id])
        ->where('roles', fn ($roles) => ! collect($roles)->pluck('name')->contains('Super Admin')
            && ! collect($roles)->pluck('name')->contains('City Admin')
            && ! collect($roles)->pluck('name')->contains('Global Operator'));

    $this->actingAs($admin)->get(route('users.create'))->assertInertia($assertScopedProps);
    $this->actingAs($admin)->get(route('users.edit', $target))->assertInertia($assertScopedProps);
    expect($outside->exists)->toBeTrue();
});

test('organizational admin cannot modify protected users or deactivate itself', function (): void {
    $organization = oumOrganization('PROTECTED');
    $admin = oumOrganizationalAdmin($organization);
    $protected = oumTarget($organization, 'protected@example.test');
    $protected->assignRole('City Admin');

    $this->actingAs($admin)->get(route('users.edit', $protected))->assertForbidden();
    $this->actingAs($admin)->post(route('users.deactivate', $protected))->assertForbidden();
    $this->actingAs($admin)->post(route('users.deactivate', $admin))->assertForbidden();
});

test('role and organization scope changes write audit records', function (): void {
    $organization = oumOrganization('AUDIT');
    $admin = oumOrganizationalAdmin($organization);
    $target = oumTarget($organization, 'audit-target@example.test');

    $this->actingAs($admin)
        ->post(route('users.assign-roles', $target), ['roles' => ['Organization Staff']])
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)
        ->post(route('users.organization-scopes.store', $target), [
            'organization_id' => $organization->id,
            'scope_type' => 'subtree',
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('audit_logs', [
        'event_type' => AuditEventType::PermissionChanged->value,
        'auditable_id' => $target->id,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'event_type' => AuditEventType::UserOrganizationScopeAssigned->value,
        'organization_id' => $organization->id,
    ]);
});
