<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Support\Rbac\DefaultRoleMatrix;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Registers the Court Cases entry permission (court_cases.view) and grants it
 * to each existing default role that DefaultRoleMatrix lists it for, so
 * installed roles match fresh installs. No court case tables are created: the
 * module is a placeholder until it is designed (docs/court-cases.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        $catalog = collect(require database_path('seeders/data/court-case-permissions.php'))->keyBy('name');

        foreach ($catalog as $name => $entry) {
            Permission::query()->updateOrCreate(['name' => $name, 'guard_name' => 'web'], array_diff_key($entry, ['name' => true]));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $names = $catalog->keys()->all();
        foreach (DefaultRoleMatrix::names() as $roleName) {
            $granted = array_values(array_intersect($names, DefaultRoleMatrix::permissionsFor($roleName)));
            if ($granted !== []) {
                Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($granted);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $names = array_column(require database_path('seeders/data/court-case-permissions.php'), 'name');
        Permission::query()->whereIn('name', $names)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
