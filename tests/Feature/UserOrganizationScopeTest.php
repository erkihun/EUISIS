<?php

declare(strict_types=1);

use App\Enums\OrganizationScopeType;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach ([
        'users.viewAny',
        'users.update',
        'users.assignOrganizationScopes',
        'user-organization-scopes.viewAny',
        'user-organization-scopes.create',
        'user-organization-scopes.update',
        'user-organization-scopes.delete',
        'user-organization-scopes.restore',
    ] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('Scope Manager', 'web')->syncPermissions([
        'users.viewAny',
        'users.update',
        'users.assignOrganizationScopes',
        'user-organization-scopes.viewAny',
        'user-organization-scopes.create',
        'user-organization-scopes.update',
        'user-organization-scopes.delete',
        'user-organization-scopes.restore',
    ]);
});

function scopeManager(): User
{
    $user = User::factory()->create();
    $user->assignRole('Scope Manager');

    return $user;
}

function makeScopeTestOrg(): Organization
{
    $type = OrganizationType::query()->firstOrCreate(
        ['code' => 'SCOPE_TEST_TYPE'],
        ['name_en' => 'Scope Test Type', 'is_demo' => true],
    );

    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'SCOPE-TEST-'.uniqid(),
        'name_en' => 'Scope Test Organization',
        'status' => 'active',
        'effective_from' => now()->toDateString(),
        'is_demo' => true,
    ]);
}

it('can list organization scopes for a user', function (): void {
    $actor = scopeManager();
    $target = User::factory()->create();
    $org = makeScopeTestOrg();

    UserOrganizationScope::query()->create([
        'user_id' => $target->id,
        'organization_id' => $org->id,
        'scope_type' => OrganizationScopeType::Self,
        'is_active' => true,
        'effective_from' => now()->toDateString(),
    ]);

    $this->actingAs($actor)
        ->getJson(route('users.organization-scopes.index', $target))
        ->assertOk()
        ->assertJsonCount(1);
});

it('can store a new organization scope', function (): void {
    $actor = scopeManager();
    $target = User::factory()->create();
    $org = makeScopeTestOrg();

    $this->actingAs($actor)
        ->post(route('users.organization-scopes.store', $target), [
            'organization_id' => $org->id,
            'scope_type' => 'self',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('user_organization_scopes', [
        'user_id' => $target->id,
        'organization_id' => $org->id,
        'scope_type' => 'self',
    ]);
});

it('can store a citywide scope without organization_id', function (): void {
    $actor = scopeManager();
    $target = User::factory()->create();

    $this->actingAs($actor)
        ->post(route('users.organization-scopes.store', $target), [
            'scope_type' => 'citywide',
            'is_active' => true,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('user_organization_scopes', [
        'user_id' => $target->id,
        'scope_type' => 'citywide',
    ]);
});

it('rejects storing a non-citywide scope without organization_id', function (): void {
    $actor = scopeManager();
    $target = User::factory()->create();

    $this->actingAs($actor)
        ->post(route('users.organization-scopes.store', $target), [
            'scope_type' => 'self',
            'is_active' => true,
        ])
        ->assertSessionHasErrors(['organization_id']);
});

it('can update an existing scope', function (): void {
    $actor = scopeManager();
    $target = User::factory()->create();
    $org = makeScopeTestOrg();

    $scope = UserOrganizationScope::query()->create([
        'user_id' => $target->id,
        'organization_id' => $org->id,
        'scope_type' => OrganizationScopeType::Self,
        'is_active' => true,
        'effective_from' => now()->toDateString(),
    ]);

    $this->actingAs($actor)
        ->put(route('users.organization-scopes.update', [$target, $scope]), [
            'organization_id' => $org->id,
            'scope_type' => 'subtree',
            'is_active' => false,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('user_organization_scopes', [
        'id' => $scope->id,
        'scope_type' => 'subtree',
        'is_active' => false,
    ]);
});

it('can delete an organization scope', function (): void {
    $actor = scopeManager();
    $target = User::factory()->create();
    $org = makeScopeTestOrg();

    $scope = UserOrganizationScope::query()->create([
        'user_id' => $target->id,
        'organization_id' => $org->id,
        'scope_type' => OrganizationScopeType::Self,
        'is_active' => true,
        'effective_from' => now()->toDateString(),
    ]);

    $this->actingAs($actor)
        ->delete(route('users.organization-scopes.destroy', [$target, $scope]))
        ->assertRedirect();

    $this->assertDatabaseMissing('user_organization_scopes', ['id' => $scope->id]);
});

it('forbids unauthenticated access to scopes', function (): void {
    $target = User::factory()->create();

    $this->getJson(route('users.organization-scopes.index', $target))
        ->assertUnauthorized();
});

it('forbids users without permission from creating scopes', function (): void {
    $actor = User::factory()->create();
    $target = User::factory()->create();
    $org = makeScopeTestOrg();

    $this->actingAs($actor)
        ->post(route('users.organization-scopes.store', $target), [
            'organization_id' => $org->id,
            'scope_type' => 'self',
            'is_active' => true,
        ])
        ->assertForbidden();
});

it('active scope query filters by is_active and effective dates', function (): void {
    $target = User::factory()->create();
    $org = makeScopeTestOrg();

    UserOrganizationScope::query()->create([
        'user_id' => $target->id,
        'organization_id' => $org->id,
        'scope_type' => OrganizationScopeType::Self,
        'is_active' => true,
        'effective_from' => now()->subDay()->toDateString(),
        'effective_to' => now()->addDay()->toDateString(),
    ]);

    UserOrganizationScope::query()->create([
        'user_id' => $target->id,
        'organization_id' => $org->id,
        'scope_type' => OrganizationScopeType::Subtree,
        'is_active' => false,
        'effective_from' => now()->subDay()->toDateString(),
    ]);

    UserOrganizationScope::query()->create([
        'user_id' => $target->id,
        'organization_id' => $org->id,
        'scope_type' => OrganizationScopeType::Citywide,
        'is_active' => true,
        'effective_from' => now()->addDays(5)->toDateString(),
    ]);

    $active = $target->organizationScopes()->active()->get();

    expect($active)->toHaveCount(1)
        ->and($active->first()->scope_type)->toBe(OrganizationScopeType::Self);
});

it('edit page includes organizations and organization_scopes', function (): void {
    Permission::findOrCreate('users.update', 'web');
    $actor = scopeManager();
    $target = User::factory()->create();

    $this->actingAs($actor)
        ->get(route('users.edit', $target))
        ->assertInertia(fn ($page) => $page
            ->has('organizations')
            ->has('user.organization_scopes')
            ->has('can.assignOrganizationScopes'),
        );
});
