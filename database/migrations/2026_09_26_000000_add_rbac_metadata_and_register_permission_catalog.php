<?php

declare(strict_types=1);

use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * RBAC audit (docs/rbac-audit-report.md).
 *
 * 1. Roles get bilingual descriptions and an is_system flag (additive
 *    nullable/defaulted columns; the Spatie schema is untouched).
 * 2. The permission catalog is registered: new permissions are created and
 *    metadata refreshed. Nothing is deleted.
 * 3. Default roles that ALREADY exist are marked system-managed and receive
 *    any matrix permission they lack (additive). No permission is revoked and
 *    no role is created here: removing permissions from default roles and
 *    creating the new default roles are explicit deploy steps
 *    (`php artisan db:seed --class=RoleSeeder`), see the audit report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            if (! Schema::hasColumn('roles', 'description_en')) {
                $table->text('description_en')->nullable()->after('guard_name');
            }
            if (! Schema::hasColumn('roles', 'description_am')) {
                $table->text('description_am')->nullable()->after('description_en');
            }
            if (! Schema::hasColumn('roles', 'is_system')) {
                $table->boolean('is_system')->default(false)->after('description_am')->index();
            }
        });

        $rbac = app(RbacSynchronizer::class);
        $rbac->syncCatalog();
        $rbac->ensureRoles(createMissing: false);
        $rbac->applyMatrix(RbacSynchronizer::MODE_ADDITIVE, onlyExisting: true);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            foreach (['is_system', 'description_am', 'description_en'] as $column) {
                if (Schema::hasColumn('roles', $column)) {
                    if ($column === 'is_system') {
                        $table->dropIndex(['is_system']);
                    }
                    $table->dropColumn($column);
                }
            }
        });
    }
};
