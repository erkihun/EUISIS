<?php

declare(strict_types=1);

use App\Actions\Organizations\PublishHierarchyVersionAction;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\OrganizationScope\OrganizationScopeService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * The organization scope rules every controller and policy relies on, at the
 * edges the page tests do not reach: city-wide grants, records that no longer
 * (or not yet) apply, the scoped Organizational Admin, permission checks with
 * no organization, and delegation.
 */
beforeEach(function (): void {
    foreach (['Super Admin', 'City Admin', 'Organizational Admin', 'HR Officer'] as $role) {
        Role::findOrCreate($role, 'web');
    }
    Permission::findOrCreate('employees.view', 'web');
    $type = OrganizationType::query()->create(['code' => 'OSS-T', 'name_en' => 'Bureau']);
    $make = fn (string $code) => Organization::query()->create([
        'organization_type_id' => $type->id, 'code' => 'OSS-'.$code, 'name_en' => 'Org '.$code, 'status' => OrganizationStatus::Active,
    ]);
    $this->parent = $make('PARENT');
    $this->child = $make('CHILD');
    $this->other = $make('OTHER');
    $this->scope = app(OrganizationScopeService::class);
});

function ossUser(string $role, array $scopes = []): User
{
    $user = User::factory()->create(['status' => 'active'])->assignRole($role);
    foreach ($scopes as $scope) {
        UserOrganizationScope::query()->create($scope + ['user_id' => $user->id, 'is_active' => true]);
    }

    return $user->fresh();
}

function ossPublish(Organization $parent, Organization $child): void
{
    $version = HierarchyVersion::query()->create(['version_name' => 'oss-'.uniqid(), 'status' => HierarchyVersionStatus::Draft, 'effective_from' => now()->subDay()->toDateString()]);
    OrganizationEdge::query()->create([
        'hierarchy_version_id' => $version->id, 'parent_organization_id' => $parent->id, 'child_organization_id' => $child->id,
        'relationship_type' => OrganizationRelationshipType::ReportsTo, 'effective_from' => now()->toDateString(),
    ]);
    app(PublishHierarchyVersionAction::class)->execute($version, ossUser('Super Admin'));
}

it('grants every organization through a city-wide scope', function (): void {
    $user = ossUser('HR Officer', [['scope_type' => OrganizationScopeType::Citywide, 'organization_id' => null]]);

    expect($this->scope->isUnrestricted($user))->toBeFalse() // it has a scope record…
        ->and($this->scope->allowedOrganizationIds($user))->toContain($this->parent->id, $this->child->id, $this->other->id) // …that covers everything
        ->and($this->scope->canAccessOrganization($user, $this->other->id))->toBeTrue()
        ->and($this->scope->applyOrganizationScope(Organization::query(), $user, 'id')->count())->toBe(3);
});

it('fails closed when every scope record is inactive, expired or not yet started', function (): void {
    $user = ossUser('HR Officer', [
        ['scope_type' => OrganizationScopeType::Self, 'organization_id' => $this->parent->id, 'is_active' => false],
        ['scope_type' => OrganizationScopeType::Self, 'organization_id' => $this->child->id, 'effective_to' => now()->subDay()->toDateString()],
        ['scope_type' => OrganizationScopeType::Citywide, 'organization_id' => null, 'effective_from' => now()->addDay()->toDateString()],
    ]);

    expect($this->scope->isUnrestricted($user))->toBeFalse()
        ->and($this->scope->allowedOrganizationIds($user))->toBe([])
        ->and($this->scope->canAccessOrganization($user, $this->parent->id))->toBeFalse()
        ->and($this->scope->applyOrganizationScope(Organization::query(), $user, 'id')->count())->toBe(0);
});

it('treats staff with no scope record as organization-wide (the documented convention)', function (): void {
    $user = ossUser('HR Officer');

    expect($this->scope->isUnrestricted($user))->toBeTrue()
        ->and($this->scope->canAccessOrganization($user, $this->other->id))->toBeTrue();
});

it('gives an Organizational Admin without a scope record nothing, not everything', function (): void {
    $admin = ossUser('Organizational Admin');

    expect($this->scope->isUnrestricted($admin))->toBeFalse()
        ->and($this->scope->allowedOrganizationIds($admin))->toBe([])
        ->and($this->scope->canAccessOrganization($admin, $this->parent->id))->toBeFalse()
        ->and($this->scope->applyOrganizationScope(Organization::query(), $admin, 'id')->count())->toBe(0);
});

it('checks permission, then organization, and refuses organization-less operational work to scoped users', function (): void {
    $scoped = ossUser('HR Officer', [['scope_type' => OrganizationScopeType::Self, 'organization_id' => $this->parent->id]]);
    $scoped->givePermissionTo('employees.view');
    $unscoped = ossUser('HR Officer');
    $unscoped->givePermissionTo('employees.view');

    expect($this->scope->canExercisePermission(ossUser('HR Officer'), 'employees.view', $this->parent->id))->toBeFalse() // no permission
        ->and($this->scope->canExercisePermission(ossUser('Super Admin'), 'employees.view'))->toBeTrue()
        ->and($this->scope->canExercisePermission($scoped, 'employees.view', $this->parent->id))->toBeTrue()
        ->and($this->scope->canExercisePermission($scoped, 'employees.view', $this->other->id))->toBeFalse()
        // No organization to check against: only an unscoped actor may proceed.
        ->and($this->scope->canExercisePermission($scoped, 'employees.view'))->toBeFalse()
        ->and($this->scope->canExercisePermission($unscoped, 'employees.view'))->toBeTrue()
        ->and($this->scope->canAccessOrganization($unscoped, null))->toBeFalse();
});

it('lets a subtree Organizational Admin read the subtree but create only under the assigned organization', function (): void {
    ossPublish($this->parent, $this->child);
    $admin = ossUser('Organizational Admin', [['scope_type' => OrganizationScopeType::Subtree, 'organization_id' => $this->parent->id]]);

    expect($this->scope->canAccess($admin, $this->child))->toBeTrue()
        ->and($this->scope->canCreateOrganizationUnder($admin, $this->parent->id))->toBeTrue()
        ->and($this->scope->canCreateOrganizationUnder($admin, $this->child->id))->toBeFalse()
        ->and($this->scope->canCreateOrganizationUnder($admin, $this->other->id))->toBeFalse()
        ->and($this->scope->canCreateOrganizationUnder($admin, null))->toBeFalse();
});

it('limits delegation and user management to the actor\'s own organizations', function (): void {
    $actor = ossUser('Organizational Admin', [['scope_type' => OrganizationScopeType::Self, 'organization_id' => $this->parent->id]]);
    $colleague = ossUser('HR Officer', [['scope_type' => OrganizationScopeType::Self, 'organization_id' => $this->parent->id]]);
    $defaulted = User::factory()->create(['default_organization_id' => $this->parent->id]);
    $stranger = ossUser('HR Officer', [['scope_type' => OrganizationScopeType::Self, 'organization_id' => $this->other->id]]);

    expect($this->scope->canAssignUserToScope($actor, $this->parent))->toBeTrue()
        ->and($this->scope->canAssignUserToScope($actor, $this->other))->toBeFalse()
        ->and($this->scope->canAssignUserToScope($actor, null))->toBeFalse()
        ->and($this->scope->canManageUser($actor, $colleague))->toBeTrue()
        ->and($this->scope->canManageUser($actor, $defaulted))->toBeTrue()
        ->and($this->scope->canManageUser($actor, $stranger))->toBeFalse()
        ->and($this->scope->canManageUser(ossUser('City Admin'), $stranger))->toBeTrue();
});

it('lists an organization\'s descendants in the published hierarchy, nearest first', function (): void {
    ossPublish($this->parent, $this->child);
    $version = HierarchyVersion::query()->where('status', HierarchyVersionStatus::Published)->sole();

    $rows = $this->scope->descendantsForOrganization($this->parent->id, $version->id);

    expect($rows->pluck('descendant_organization_id'))->toContain($this->child->id)->not->toContain($this->other->id)
        ->and($rows->pluck('depth')->all())->toBe($rows->pluck('depth')->sort()->values()->all());
});
