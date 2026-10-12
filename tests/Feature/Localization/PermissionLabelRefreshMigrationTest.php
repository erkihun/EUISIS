<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\DefaultRoleMatrix;
use App\Support\Rbac\PermissionCatalog;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/*
 * The language audit corrected the Amharic labels of the permission catalogue
 * and the default role descriptions. Installed systems receive the corrected
 * wording through a migration; grants and roles are not touched.
 */
function runPermissionLabelRefresh(): void
{
    $migration = require database_path('migrations/2026_10_12_200000_refresh_amharic_permission_and_role_labels.php');
    $migration->up();
}

it('replaces an installed permission label with the corrected catalogue wording', function () {
    $this->seed(PermissionSeeder::class);
    $permission = Permission::query()->where('name', 'service_feedback.review')->firstOrFail();
    $permission->forceFill(['label_am' => 'የአገልግሎት ዘገባ ይገምግሙ', 'description_am' => 'የደንበኛ ዘገባዎችን ቨቴንታ ወይም በቴንታ አልቆ ማመልከት ያስችላል።'])->save();

    runPermissionLabelRefresh();

    $catalogue = PermissionCatalog::all()['service_feedback.review'];
    expect($permission->fresh()->label_am)->toBe($catalogue['label_am'])
        ->and($permission->fresh()->description_am)->toBe($catalogue['description_am']);
});

it('refreshes a default role description without changing its grants', function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $name = array_key_first(DefaultRoleMatrix::roles());
    $role = Role::query()->where('name', $name)->where('guard_name', PermissionCatalog::GUARD)->firstOrFail();
    $grants = $role->permissions()->pluck('name')->sort()->values()->all();
    $role->forceFill(['description_am' => 'የቀድሞ መግለጫ'])->save();

    runPermissionLabelRefresh();

    expect($role->fresh()->description_am)->toBe(DefaultRoleMatrix::roles()[$name]['description_am'])
        ->and($role->fresh()->permissions()->pluck('name')->sort()->values()->all())->toBe($grants);
});
