<?php

declare(strict_types=1);

use App\Support\Rbac\RbacSynchronizer;
use Database\Seeders\LegalInstrumentTypeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Legal sources, legacy baselines, design imports and document suggestions
 * (docs/organization-design-legacy-baseline-plan.md §5). Additive only:
 * registers the catalog entries, grants them to the three design roles the
 * matrix already defines, and adds the source types a baseline rests on.
 * No other role is granted anything — a business decision.
 */
return new class extends Migration
{
    private const GRANTS = [
        'Organization Designer' => [
            'legal-sources.viewAny', 'legal-sources.view', 'legal-sources.create', 'legal-sources.update',
            'legacy-baselines.viewAny', 'legacy-baselines.view', 'legacy-baselines.create', 'legacy-baselines.update',
            'design-imports.viewAny', 'design-imports.create', 'design-suggestions.view', 'design-suggestions.apply',
        ],
        'Organization Design Approver' => [
            'legal-sources.viewAny', 'legal-sources.view',
            'legacy-baselines.viewAny', 'legacy-baselines.view', 'legacy-baselines.review', 'legacy-baselines.approve', 'legacy-baselines.publish',
            'design-imports.viewAny', 'design-imports.commit', 'design-suggestions.view',
        ],
        'Design Catalog Manager' => ['legal-sources.viewAny', 'legal-sources.view', 'legal-sources.manageCatalog'],
    ];

    public function up(): void
    {
        if (DB::pretending() || ! Schema::hasTable('permissions')) {
            return;
        }
        app(RbacSynchronizer::class)->syncCatalog();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::GRANTS as $roleName => $permissions) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permissions);
        }
        if (Schema::hasTable('legal_instrument_types')) {
            (new LegalInstrumentTypeSeeder)->run();
        }
    }

    public function down(): void
    {
        // Grants only; nothing to undo safely.
    }
};
