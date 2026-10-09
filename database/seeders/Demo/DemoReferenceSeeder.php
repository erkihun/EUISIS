<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\OrganizationType;
use App\Models\ServiceType;
use App\Support\Demo\DemoDataset;
use Database\Seeders\CodeRuleSeeder;
use Database\Seeders\GrievanceDefaultsSeeder;
use Database\Seeders\OccupationSeeder;
use Database\Seeders\OrganizationTypeSeeder;
use Database\Seeders\OrganizationUnitTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use RuntimeException;

/**
 * Reference data the demo dataset stands on, through the project's own
 * reference seeders. Organization types are only created when missing; an
 * existing type is reused as it is.
 */
class DemoReferenceSeeder extends DemoSeeder
{
    public function run(): void
    {
        // Permissions from the catalog, then roles and their matrix permissions.
        $this->call([PermissionSeeder::class, RoleSeeder::class]);
        $this->call(CodeRuleSeeder::class);
        // Grievance code rules, reason codes, SLA profile, letter templates (idempotent).
        $this->call(GrievanceDefaultsSeeder::class);
        $this->call(OrganizationUnitTypeSeeder::class);
        $this->call(OccupationSeeder::class);

        ServiceType::query()->firstOrCreate(['code' => 'cafeteria'], ['name_en' => 'Cafeteria', 'is_active' => true]);

        $this->ensureOrganizationTypes();
    }

    private function ensureOrganizationTypes(): void
    {
        $needed = collect(DemoDataset::organizations())->pluck('type')->unique();
        $definitions = collect(OrganizationTypeSeeder::definitions())->keyBy('code');

        foreach ($needed as $code) {
            $existing = OrganizationType::withTrashed()->where('code', $code)->first();
            if ($existing !== null) {
                if ($existing->trashed()) {
                    throw new RuntimeException("Organization type {$code} is archived; restore it before seeding demo data.");
                }

                continue;
            }

            $definition = $definitions->get($code) ?? throw new RuntimeException("No reference definition for organization type {$code}.");
            OrganizationType::query()->create($definition);
        }
    }
}
