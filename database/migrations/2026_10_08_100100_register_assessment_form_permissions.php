<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Support\Performance\PerformanceRoles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Registers the Assessment Form Builder permissions on existing
 * installations. City-level roles hold every catalog permission; the
 * Performance Officer and Organizational Admin receive theirs from
 * PerformanceRoles. Publishing and archiving stay with administrators.
 */
return new class extends Migration
{
    public function up(): void
    {
        $catalog = collect(require database_path('seeders/data/performance-permissions.php'))
            ->filter(fn (array $entry): bool => str_starts_with($entry['name'], 'assessment_forms.'));

        foreach ($catalog as $entry) {
            Permission::query()->updateOrCreate(
                ['name' => $entry['name'], 'guard_name' => 'web'],
                array_diff_key($entry, ['name' => true]),
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = $catalog->pluck('name')->all();
        $grants = [
            'Super Admin' => $all,
            'System Admin' => $all,
            'City Admin' => $all,
            'Public Service Bureau Admin' => $all,
            'Organizational Admin' => array_values(array_intersect(PerformanceRoles::ORGANIZATIONAL_ADMIN_PERMISSIONS, $all)),
            PerformanceRoles::OFFICER_ROLE => array_values(array_intersect(PerformanceRoles::OFFICER_PERMISSIONS, $all)),
        ];

        foreach ($grants as $roleName => $permissions) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'like', 'assessment_forms.%')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
