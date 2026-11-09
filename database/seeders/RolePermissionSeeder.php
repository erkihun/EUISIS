<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Seeder;

/**
 * Applies App\Support\Rbac\DefaultRoleMatrix to the system roles.
 *
 * Mode (config rbac.default_role_sync / RBAC_DEFAULT_ROLE_SYNC):
 *   sync      (default) each system role ends with exactly its matrix permissions
 *   additive  only missing matrix permissions are added
 * Custom roles are never touched. Every change is printed.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(RbacSynchronizer $rbac): void
    {
        $mode = (string) config('rbac.default_role_sync', RbacSynchronizer::MODE_SYNC);
        $report = $rbac->applyMatrix($mode, onlyExisting: true);

        if ($report === []) {
            $this->command?->info("Role permissions already match the default matrix ({$mode}).");

            return;
        }

        foreach ($report as $role => $changes) {
            $this->command?->info(sprintf('%s: +%d -%d', $role, count($changes['added']), count($changes['removed'])));
            if ($changes['removed'] !== []) {
                $this->command?->warn('  removed: '.implode(', ', $changes['removed']));
            }
        }
    }
}
