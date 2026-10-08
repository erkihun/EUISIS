<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Support\Performance\PerformanceRoles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Registers the Assessment Oversight & Compliance permissions and the two
 * narrow roles (city oversight officer, aggregate report viewer). Re-runnable.
 */
return new class extends Migration
{
    private const GROUPS = ['assessment_oversight.', 'assessment_exclusions.', 'assessment_submissions.', 'assessment_reports.'];

    public function up(): void
    {
        $catalog = collect(require database_path('seeders/data/performance-permissions.php'))
            ->filter(fn (array $entry): bool => str_starts_with($entry['name'], 'assessment_') && ! str_starts_with($entry['name'], 'assessment_forms.'));

        foreach ($catalog as $entry) {
            Permission::query()->updateOrCreate(['name' => $entry['name'], 'guard_name' => 'web'], array_diff_key($entry, ['name' => true]));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = $catalog->pluck('name')->all();
        $only = fn (array $permissions): array => array_values(array_intersect($permissions, $all));
        $grants = [
            'Super Admin' => $all,
            'System Admin' => $all,
            'City Admin' => $all,
            'Public Service Bureau Admin' => $all,
            'Organizational Admin' => $only(PerformanceRoles::ASSESSMENT_INSTITUTION_ADMIN_PERMISSIONS),
            'HR Officer' => $only(PerformanceRoles::ASSESSMENT_HR_PERMISSIONS),
            PerformanceRoles::OFFICER_ROLE => $only(PerformanceRoles::ASSESSMENT_OFFICER_PERMISSIONS),
            PerformanceRoles::MANAGER_ROLE => $only(PerformanceRoles::ASSESSMENT_MANAGER_PERMISSIONS),
        ];
        foreach ($grants as $roleName => $permissions) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permissions);
        }

        foreach ([
            PerformanceRoles::ASSESSMENT_OVERSIGHT_ROLE => PerformanceRoles::ASSESSMENT_OVERSIGHT_PERMISSIONS,
            PerformanceRoles::ASSESSMENT_REPORT_VIEWER_ROLE => PerformanceRoles::ASSESSMENT_REPORT_VIEWER_PERMISSIONS,
        ] as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->forceFill(['scope_type' => 'scoped'])->save();
            $role->givePermissionTo($only($permissions));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::GROUPS as $prefix) {
            Permission::query()->where('name', 'like', $prefix.'%')->where('guard_name', 'web')->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
