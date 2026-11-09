<?php

declare(strict_types=1);

use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Amharic term for "backup" changed from ምትኬ to ባካፕ (2026-10-19).
 * Permission labels live in the catalog seed and are copied into the
 * permissions table, so the stored labels are re-synced here; nothing else
 * changes and no grant is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::pretending() || ! Schema::hasTable('permissions')) {
            return;
        }
        app(RbacSynchronizer::class)->syncCatalog();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Labels only; the previous wording is not restored.
    }
};
