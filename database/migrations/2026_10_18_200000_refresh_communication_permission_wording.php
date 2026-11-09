<?php

declare(strict_types=1);

use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/*
 * Communication permission descriptions now follow the Amharic terminology
 * guide (ማህደር, not ማኅደር). Rewrites the stored wording from the catalog;
 * role grants are not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(RbacSynchronizer::class)->syncCatalog();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Wording only; nothing to undo.
    }
};
