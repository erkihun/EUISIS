<?php

declare(strict_types=1);

namespace Database\Seeders;

use CafeteriaSystem\Models\Cafeteria;
use CafeteriaSystem\Models\CafeteriaOrganizationAssignment;
use CafeteriaSystem\Models\CafeteriaProvider;
use CafeteriaSystem\Models\CafeteriaUser;
use Illuminate\Database\Seeder;

/**
 * Minimum working setup: a provider, one service point, an operator bound to
 * it, and an organization assignment — without all four the scan terminal
 * cannot serve anyone.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $provider = CafeteriaProvider::query()->updateOrCreate(
            ['code' => env('CAFETERIA_PROVIDER_CODE', 'CAF-001')],
            [
                'name' => 'Main Cafeteria',
                'branch_name' => 'Head Office',
                'status' => 'active',
            ],
        );

        $cafeteria = Cafeteria::query()->updateOrCreate(
            ['code' => 'CAF-001-A'],
            [
                'provider_id' => $provider->id,
                'name' => 'Main Cafeteria — Ground Floor',
                'location' => 'Head Office',
                'status' => 'active',
            ],
        );

        // Provider admin: sees every cafeteria under this provider.
        CafeteriaUser::query()->updateOrCreate(
            ['email' => 'admin@cafeteria.local'],
            [
                'provider_id' => $provider->id,
                'cafeteria_id' => null,
                'name' => 'Cafeteria Administrator',
                'password' => 'password',
                'role' => CafeteriaUser::ROLE_PROVIDER_ADMIN,
                'status' => 'active',
            ],
        );

        // Operator: bound to one cafeteria, so the scan terminal works.
        CafeteriaUser::query()->updateOrCreate(
            ['email' => 'operator@cafeteria.local'],
            [
                'provider_id' => $provider->id,
                'cafeteria_id' => $cafeteria->id,
                'name' => 'Cafeteria Operator',
                'password' => 'password',
                'role' => CafeteriaUser::ROLE_SCANNER,
                'status' => 'active',
            ],
        );

        // Without an assignment every scan is denied, so seed one demo
        // organization. Replace the code with a real EUISIS organization code.
        CafeteriaOrganizationAssignment::query()->updateOrCreate(
            ['cafeteria_id' => $cafeteria->id, 'organization_code' => 'DEMO-ORG'],
            [
                'organization_name_snapshot' => 'Demo Organization',
                'status' => 'active',
                'effective_from' => now()->subYear()->toDateString(),
            ],
        );

        $this->command->info('Cafeteria system seeded.');
        $this->command->line('  admin    : admin@cafeteria.local / password');
        $this->command->line('  operator : operator@cafeteria.local / password');
        $this->command->line('  cafeteria: '.$cafeteria->code.' ('.$cafeteria->name.')');
    }
}
