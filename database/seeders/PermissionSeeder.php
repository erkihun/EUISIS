<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Seeder;

/**
 * Registers App\Support\Rbac\PermissionCatalog (database/seeders/data/permissions.php).
 *
 * Idempotent: creates missing permissions and refreshes labels, descriptions
 * and groups. Never deletes a permission; stale ones are listed by
 * `php artisan rbac:report` instead.
 */
class PermissionSeeder extends Seeder
{
    public function run(RbacSynchronizer $rbac): void
    {
        $result = $rbac->syncCatalog();

        $this->command?->info("Permissions seeded: {$result['created']} created, {$result['updated']} updated.");
    }
}
