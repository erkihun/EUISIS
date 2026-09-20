<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Retires `id-cards.printAnytime`.
 *
 * Printing a card straight from the browser has been removed: a card now
 * leaves the system as a PNG export, which `id-cards.exportPng` governs. The
 * permission no longer grants anything, so it is deleted rather than left in
 * the role editor where it would imply a capability that does not exist.
 *
 * Audit history keeps its `card.printed_anytime` entries — the enum case
 * stays so past events still read — and the official print-batch workflow
 * (`id-cards.print`) is untouched.
 */
return new class extends Migration
{
    private const PERMISSION = 'id-cards.printAnytime';

    public function up(): void
    {
        $id = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        if ($id === null) {
            return;
        }

        // Detach first so no role keeps a dangling grant.
        DB::table('role_has_permissions')->where('permission_id', $id)->delete();
        DB::table('model_has_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Restoring the row is safe; the feature it guarded is gone, so no
        // role is re-granted automatically. Re-run the permission seeder to
        // put it back in the catalog if the feature ever returns.
        DB::table('permissions')->updateOrInsert(
            ['name' => self::PERMISSION, 'guard_name' => 'web'],
            ['created_at' => now(), 'updated_at' => now()],
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
