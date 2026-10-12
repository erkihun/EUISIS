<?php

declare(strict_types=1);

use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/*
 * Amharic language audit (docs/amharic-language-audit.md): the permission
 * catalogue and the default role descriptions received corrected Amharic
 * wording. This copies the catalogue's labels and descriptions onto the
 * installed permissions and the default roles' descriptions onto those roles.
 * No permission is granted or revoked, no role is created, and no user data is
 * touched. The previous wording stays in git history, so down() does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rbac = app(RbacSynchronizer::class);
        $rbac->syncCatalog();
        $rbac->ensureRoles(createMissing: false);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Wording only; nothing to undo.
    }
};
