<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Support\Rbac\DefaultRoleMatrix;
use App\Support\Rbac\PermissionCatalog;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/*
| The City-wide Viewer: sees every module and every institution, changes
| nothing, and never uncovers a person.
*/

it('holds only view permissions', function (): void {
    $permissions = DefaultRoleMatrix::permissionsFor(DefaultRoleMatrix::CITYWIDE_VIEWER);

    $notViews = array_values(array_filter($permissions, static function (string $permission): bool {
        $action = substr($permission, (int) strrpos($permission, '.') + 1);

        return ! in_array($permission, DefaultRoleMatrix::VIEW_GATES, true) && preg_match('/^(view|preview|oversight_view$)/', $action) !== 1;
    }));

    expect($notViews)->toBe([])
        ->and(count($permissions))->toBeGreaterThan(200);

    foreach ($permissions as $permission) {
        expect($permission)->not->toMatch('/\.(create|update|delete|manage|approve|reject|export\w*|import|assign\w*|publish|archive|restore|print|download|submit\w*)$/');
    }
});

it('reaches the list page of every module the city administrator can open', function (): void {
    $permissions = DefaultRoleMatrix::permissionsFor(DefaultRoleMatrix::CITYWIDE_VIEWER);

    expect($permissions)->toContain(
        'dashboard.view', 'organizations.viewAny', 'organization-units.viewAny', 'positions.viewAny',
        'employees.viewAny', 'employees.view', 'users.viewAny', 'reports.view',
    );
});

it('does not uncover a person', function (): void {
    $permissions = DefaultRoleMatrix::permissionsFor(DefaultRoleMatrix::CITYWIDE_VIEWER);

    foreach ($permissions as $permission) {
        expect(substr($permission, (int) strrpos($permission, '.') + 1))->not->toBeIn(DefaultRoleMatrix::VIEW_WITHHELD);
    }

    expect(array_intersect($permissions, DefaultRoleMatrix::ADMIN_ONLY))->toBe([]);
});

it('is a global role with an Amharic name, and sees every institution without a scope record', function (): void {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);

    $role = Role::query()->where('name', DefaultRoleMatrix::CITYWIDE_VIEWER)->where('guard_name', PermissionCatalog::GUARD)->firstOrFail();
    expect(DefaultRoleMatrix::roles()[DefaultRoleMatrix::CITYWIDE_VIEWER]['scope'])->toBe(DefaultRoleMatrix::SCOPE_GLOBAL)
        ->and(DefaultRoleMatrix::nameAm(DefaultRoleMatrix::CITYWIDE_VIEWER))->toBe('የከተማ አቀፍ ተመልካች')
        ->and($role->permissions()->count())->toBe(count(DefaultRoleMatrix::permissionsFor(DefaultRoleMatrix::CITYWIDE_VIEWER)));

    $type = OrganizationType::query()->firstOrCreate(['code' => 'VIEW-T'], ['name_en' => 'Bureau', 'name_am' => 'ቢሮ']);
    $organization = Organization::query()->create([
        'organization_type_id' => $type->id, 'code' => 'VIEW-1', 'name_en' => 'Any Bureau', 'name_am' => 'ማንኛውም ቢሮ',
        'status' => OrganizationStatus::Active, 'effective_from' => now()->toDateString(),
    ]);

    $viewer = User::factory()->create();
    $viewer->assignRole(DefaultRoleMatrix::CITYWIDE_VIEWER);
    $scope = app(OrganizationScopeService::class);

    expect($scope->isUnrestricted($viewer))->toBeTrue()
        ->and($scope->canAccessOrganization($viewer, $organization->id))->toBeTrue()
        // The lists and the dashboard count through this; it was empty while the role was only "global".
        ->and($scope->accessibleOrganizationIds($viewer)->map(fn ($id): string => (string) $id)->all())->toContain((string) $organization->id)
        ->and($viewer->can('employees.viewAny'))->toBeTrue()
        ->and($viewer->can('employees.manage'))->toBeFalse()
        ->and($viewer->can('organizations.create'))->toBeFalse()
        ->and($viewer->can('users.assignRoles'))->toBeFalse();
});
