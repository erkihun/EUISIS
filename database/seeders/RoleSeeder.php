<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Seeder;

/**
 * Creates and describes the default system roles of
 * App\Support\Rbac\DefaultRoleMatrix, then applies their permissions
 * (RolePermissionSeeder), so seeding roles alone always yields a working
 * RBAC. Run PermissionSeeder first.
 *
 * Idempotent: roles are matched by name; users and custom roles are never touched.
 */
class RoleSeeder extends Seeder
{
    public function run(RbacSynchronizer $rbac): void
    {
        $result = $rbac->ensureRoles(createMissing: true);

        $this->command?->info(sprintf(
            'Roles seeded: %d created%s, %d updated.',
            count($result['created']),
            $result['created'] !== [] ? ' ('.implode(', ', $result['created']).')' : '',
            count($result['updated']),
        ));

        $this->call(RolePermissionSeeder::class);
    }
}
