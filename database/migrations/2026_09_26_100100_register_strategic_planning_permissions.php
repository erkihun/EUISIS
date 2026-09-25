<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Support\Performance\PerformanceRoles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $names = [
            'performance_cycles.approve', 'performance_cycles.publish',
            'strategic_goals.view', 'strategic_goals.create', 'strategic_goals.update', 'strategic_goals.delete_draft',
            'strategic_goals.approve', 'strategic_goals.publish', 'strategic_goal_allocations.view',
            'strategic_goal_allocations.manage', 'performance_objectives.view', 'kpi_targets.view',
        ];
        $catalog = collect(require database_path('seeders/data/performance-permissions.php'))->keyBy('name');
        foreach ($names as $name) {
            $entry = $catalog->get($name);
            Permission::query()->updateOrCreate(['name' => $name, 'guard_name' => 'web'], array_diff_key($entry, ['name' => true]));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Super Admin', 'System Admin', 'City Admin', 'Public Service Bureau Admin'] as $roleName) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($names);
        }
        Role::query()->where('name', 'Organizational Admin')->where('guard_name', 'web')->first()?->givePermissionTo(array_intersect($names, PerformanceRoles::ORGANIZATIONAL_ADMIN_PERMISSIONS));
        Role::query()->where('name', 'HR Officer')->where('guard_name', 'web')->first()?->givePermissionTo(array_intersect($names, PerformanceRoles::HR_PERMISSIONS));
        Role::query()->where('name', PerformanceRoles::MANAGER_ROLE)->where('guard_name', 'web')->first()?->givePermissionTo(array_intersect($names, PerformanceRoles::MANAGER_PERMISSIONS));
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', [
            'performance_cycles.approve', 'performance_cycles.publish',
            'strategic_goals.view', 'strategic_goals.create', 'strategic_goals.update', 'strategic_goals.delete_draft',
            'strategic_goals.approve', 'strategic_goals.publish', 'strategic_goal_allocations.view',
            'strategic_goal_allocations.manage', 'performance_objectives.view', 'kpi_targets.view',
        ])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
