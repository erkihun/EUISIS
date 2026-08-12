<?php

declare(strict_types=1);

use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Users/Edit → Organization Scopes.
 *
 * The edit page must actually LIST the organizations that can be scoped, expose
 * their code/status/type, keep already-scoped (now inactive) organizations
 * visible, and refuse — server-side — to assign an inactive organization or one
 * outside the acting admin's own scope.
 */
beforeEach(function (): void {
    foreach ([
        'users.viewAny',
        'users.update',
        'users.assignOrganizationScopes',
        'user-organization-scopes.viewAny',
        'user-organization-scopes.create',
        'user-organization-scopes.update',
        'user-organization-scopes.delete',
    ] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('UOS Manager', 'web')->syncPermissions([
        'users.viewAny',
        'users.update',
        'users.assignOrganizationScopes',
        'user-organization-scopes.viewAny',
        'user-organization-scopes.create',
        'user-organization-scopes.update',
        'user-organization-scopes.delete',
    ]);

    // Can edit users, but must NOT be able to touch organization scopes.
    Role::findOrCreate('UOS Weak', 'web')->syncPermissions(['users.viewAny', 'users.update']);

    $this->uosType = OrganizationType::query()->create([
        'code' => 'UOS-TYPE',
        'name_en' => 'Scope Test Type',
        'name_am' => 'የወሰን ሙከራ ዓይነት',
    ]);
});

function uosManager(): User
{
    return tap(User::factory()->create())->assignRole('UOS Manager');
}

function uosWeak(): User
{
    return tap(User::factory()->create())->assignRole('UOS Weak');
}

function uosOrg(string $code, string $status = 'active'): Organization
{
    return Organization::query()->create([
        'organization_type_id' => test()->uosType->id,
        'code' => $code,
        'name_en' => 'Org '.$code,
        'name_am' => 'ተቋም '.$code,
        'status' => $status,
        'effective_from' => now()->toDateString(),
    ]);
}

/**
 * NOTE: effective_from is left null on purpose. Eloquent's `date` cast writes a
 * full datetime string, which on SQLite (tests) sorts *after* today's date and
 * makes UserOrganizationScope::active() reject the row. On Postgres the column
 * is a real DATE, so it behaves correctly — this is a test-driver artifact only
 * (verified against the live database). A null effective_from is matched by the
 * `whereNull(...)` branch of active() on both drivers.
 */
function uosScope(User $user, Organization $org, string $type = 'self'): UserOrganizationScope
{
    return UserOrganizationScope::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'scope_type' => $type,
        'effective_from' => null,
        'is_active' => true,
    ]);
}

// ── The bug: the edit page must LIST organizations ────────────────────────

it('sends active organizations with code, status and type to the edit page', function (): void {
    $org = uosOrg('UOS-1');
    $target = User::factory()->create();

    $this->actingAs(uosManager())
        ->get(route('users.edit', $target))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Users/Edit')
            ->has('organizations', 1)
            ->where('organizations.0.id', $org->id)
            ->where('organizations.0.code', 'UOS-1')
            ->where('organizations.0.status', 'active')
            ->where('organizations.0.name_am', 'ተቋም UOS-1')
            ->where('organizations.0.type.name_en', 'Scope Test Type')
        );
});

it('shows an empty organizations list when none exist rather than failing', function (): void {
    $target = User::factory()->create();

    $this->actingAs(uosManager())
        ->get(route('users.edit', $target))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('organizations', 0));
});

it('pre-loads the users existing organization scopes on the edit page', function (): void {
    $org = uosOrg('UOS-2');
    $target = User::factory()->create();
    uosScope($target, $org);

    $this->actingAs(uosManager())
        ->get(route('users.edit', $target))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('user.organization_scopes', 1)
            ->where('user.organization_scopes.0.organization.id', $org->id)
            ->where('user.organization_scopes.0.scope_type', 'self')
            ->where('can.assignOrganizationScopes', true)
        );
});

// ── Inactive organizations ────────────────────────────────────────────────

it('excludes inactive organizations from the edit page list', function (): void {
    $active = uosOrg('UOS-ACTIVE');
    uosOrg('UOS-INACTIVE', OrganizationStatus::Inactive->value);
    $target = User::factory()->create();

    $this->actingAs(uosManager())
        ->get(route('users.edit', $target))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('organizations', 1)
            ->where('organizations.0.id', $active->id)
        );
});

it('still shows an already-scoped organization that has since become inactive, marked inactive', function (): void {
    $org = uosOrg('UOS-WAS-ACTIVE');
    $target = User::factory()->create();
    uosScope($target, $org);

    // The organization is deactivated after the scope was granted.
    $org->update(['status' => OrganizationStatus::Inactive->value]);

    $this->actingAs(uosManager())
        ->get(route('users.edit', $target))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // Visible so the existing scope still renders …
            ->has('organizations', 1)
            ->where('organizations.0.id', $org->id)
            // … but flagged inactive so the UI can disable/badge it.
            ->where('organizations.0.status', 'inactive')
        );
});

it('rejects newly assigning an inactive organization as a scope', function (): void {
    $inactive = uosOrg('UOS-3', OrganizationStatus::Inactive->value);
    $target = User::factory()->create();

    $this->actingAs(uosManager())
        ->post(route('users.organization-scopes.store', ['user' => $target->id]), [
            'organization_id' => $inactive->id,
            'scope_type' => 'self',
            'is_active' => true,
        ])
        ->assertSessionHasErrors('organization_id');

    expect($target->organizationScopes()->count())->toBe(0);
});

// ── Happy path: assign / update ───────────────────────────────────────────

it('assigns an organization scope successfully', function (): void {
    $org = uosOrg('UOS-4');
    $target = User::factory()->create();

    $this->actingAs(uosManager())
        ->post(route('users.organization-scopes.store', ['user' => $target->id]), [
            'organization_id' => $org->id,
            'scope_type' => 'subtree',
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($target->organizationScopes()->count())->toBe(1)
        ->and($target->organizationScopes()->first()->organization_id)->toBe($org->id);
});

it('updates an existing organization scope successfully', function (): void {
    $orgA = uosOrg('UOS-5A');
    $orgB = uosOrg('UOS-5B');
    $target = User::factory()->create();
    $scope = uosScope($target, $orgA);

    $this->actingAs(uosManager())
        ->put(route('users.organization-scopes.update', ['user' => $target->id, 'scope' => $scope->id]), [
            'organization_id' => $orgB->id,
            'scope_type' => 'subtree',
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($scope->fresh()->organization_id)->toBe($orgB->id)
        ->and($scope->fresh()->scope_type->value)->toBe('subtree');
});

it('allows keeping an existing scope whose organization has since become inactive', function (): void {
    $org = uosOrg('UOS-6');
    $target = User::factory()->create();
    $scope = uosScope($target, $org);

    $org->update(['status' => OrganizationStatus::Inactive->value]);

    // Re-saving the same (now inactive) organization must not be blocked —
    // only switching to a *different* inactive organization is.
    $this->actingAs(uosManager())
        ->put(route('users.organization-scopes.update', ['user' => $target->id, 'scope' => $scope->id]), [
            'organization_id' => $org->id,
            'scope_type' => 'subtree',
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($scope->fresh()->scope_type->value)->toBe('subtree');
});

// ── Authorization ─────────────────────────────────────────────────────────

it('forbids a user without scope permission from assigning organization scopes', function (): void {
    $org = uosOrg('UOS-7');
    $target = User::factory()->create();

    $this->actingAs(uosWeak())
        ->post(route('users.organization-scopes.store', ['user' => $target->id]), [
            'organization_id' => $org->id,
            'scope_type' => 'self',
            'is_active' => true,
        ])
        ->assertForbidden();

    expect($target->organizationScopes()->count())->toBe(0);
});

it('prevents a scoped admin from assigning an organization outside their own scope', function (): void {
    $inside = uosOrg('UOS-INSIDE');
    $outside = uosOrg('UOS-OUTSIDE');

    // The acting admin is themselves scoped to `inside` only.
    $actor = uosManager();
    uosScope($actor, $inside, OrganizationScopeType::Self->value);

    $target = User::factory()->create();

    // Outside their scope → rejected.
    $this->actingAs($actor)
        ->post(route('users.organization-scopes.store', ['user' => $target->id]), [
            'organization_id' => $outside->id,
            'scope_type' => 'self',
            'is_active' => true,
        ])
        ->assertSessionHasErrors('organization_id');

    // Inside their scope → allowed.
    $this->actingAs($actor)
        ->post(route('users.organization-scopes.store', ['user' => $target->id]), [
            'organization_id' => $inside->id,
            'scope_type' => 'self',
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($target->organizationScopes()->count())->toBe(1)
        ->and($target->organizationScopes()->first()->organization_id)->toBe($inside->id);
});
