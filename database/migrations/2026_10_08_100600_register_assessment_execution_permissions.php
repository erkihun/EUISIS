<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Support\DailyActivity\DailyActivityRoles;
use App\Support\Performance\PerformanceRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Registers the Assessment Execution permissions on existing installations,
 * granted as DefaultRoleMatrix defines them. Re-runnable.
 */
return new class extends Migration
{
    private const GROUPS = ['assessments.', 'assessment_assignments.', 'assessment_results.'];

    public function up(): void
    {
        // `migrate --pretend` cannot process Eloquent's PostgreSQL RETURNING
        // result for updateOrCreate. This migration registers reference data,
        // so skip its data writes in a dry run while keeping real migrations
        // fully re-runnable.
        if (DB::pretending()) {
            return;
        }

        $catalog = collect(require database_path('seeders/data/performance-permissions.php'))
            ->filter(fn (array $entry): bool => collect(self::GROUPS)->contains(fn (string $prefix): bool => str_starts_with($entry['name'], $prefix)));

        foreach ($catalog as $entry) {
            Permission::query()->updateOrCreate(['name' => $entry['name'], 'guard_name' => 'web'], array_diff_key($entry, ['name' => true]));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = $catalog->pluck('name')->all();
        $only = fn (array ...$lists): array => array_values(array_intersect(array_merge(...$lists), $all));
        foreach ([
            'Super Admin' => $all,
            'System Admin' => $all,
            'City Admin' => $all,
            'Public Service Bureau Admin' => $all,
            'Organizational Admin' => $only(PerformanceRoles::ASSESSMENT_EXECUTION_ADMIN_PERMISSIONS, PerformanceRoles::ASSESSMENT_EVALUATOR_PERMISSIONS),
            'HR Officer' => $only(PerformanceRoles::ASSESSMENT_EXECUTION_HR_PERMISSIONS, PerformanceRoles::ASSESSMENT_EVALUATOR_PERMISSIONS),
            DailyActivityRoles::EMPLOYEE_ROLE => $only(PerformanceRoles::ASSESSMENT_EVALUATOR_PERMISSIONS),
            PerformanceRoles::MANAGER_ROLE => $only(PerformanceRoles::ASSESSMENT_EVALUATOR_PERMISSIONS),
        ] as $roleName => $permissions) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permissions);
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
