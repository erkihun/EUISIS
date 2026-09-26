<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Support\Rbac\DefaultRoleMatrix;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Registers the cafeteria network/access/policy/settlement permissions and
 * grants each existing default role exactly what DefaultRoleMatrix lists for
 * it, so installed roles match fresh installs.
 */
return new class extends Migration
{
    public function up(): void
    {
        $catalog = collect(require database_path('seeders/data/cafeteria-policy-permissions.php'))->keyBy('name');

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
        $names = array_column(require database_path('seeders/data/cafeteria-policy-permissions.php'), 'name');
        Permission::query()->whereIn('name', $names)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
