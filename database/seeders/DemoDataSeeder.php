<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Demo\DemoDataValidator;
use Database\Seeders\Demo\DemoActorSeeder;
use Database\Seeders\Demo\DemoCafeteriaAccessSeeder;
use Database\Seeders\Demo\DemoCafeteriaPolicySeeder;
use Database\Seeders\Demo\DemoCafeteriaTransactionSeeder;
use Database\Seeders\Demo\DemoDailyActivitySeeder;
use Database\Seeders\Demo\DemoEmployeeSeeder;
use Database\Seeders\Demo\DemoGrievanceSeeder;
use Database\Seeders\Demo\DemoIdCardSeeder;
use Database\Seeders\Demo\DemoOrganizationSeeder;
use Database\Seeders\Demo\DemoPerformanceSeeder;
use Database\Seeders\Demo\DemoPositionSeeder;
use Database\Seeders\Demo\DemoProviderSeeder;
use Database\Seeders\Demo\DemoReferenceSeeder;
use Database\Seeders\Demo\DemoSeeder;
use Database\Seeders\Demo\DemoStructureSeeder;
use Database\Seeders\Demo\DemoUserSeeder;

/**
 * DEVELOPMENT / QA / UAT demo dataset (docs/demo-seed-data.md):
 *
 *     php artisan db:seed --class=DemoDataSeeder
 *
 * Never part of DatabaseSeeder, refused in production, idempotent (a re-run
 * finds its own records by natural key), never truncates or deletes.
 */
class DemoDataSeeder extends DemoSeeder
{
    public function run(DemoDataValidator $validator): void
    {
        $this->command?->warn('DEVELOPMENT / QA / UAT DEMO DATA — synthetic records, never production data.');

        $this->call([
            DemoReferenceSeeder::class,
            DemoActorSeeder::class,
            DemoOrganizationSeeder::class,
            DemoStructureSeeder::class,
            DemoPositionSeeder::class,
            DemoEmployeeSeeder::class,
            DemoUserSeeder::class,
            DemoIdCardSeeder::class,
            DemoProviderSeeder::class,
            DemoCafeteriaAccessSeeder::class,
            DemoCafeteriaPolicySeeder::class,
            DemoCafeteriaTransactionSeeder::class,
            // Optional modules — each seeds only through its own services.
            DemoDailyActivitySeeder::class,
            DemoPerformanceSeeder::class,
            DemoGrievanceSeeder::class,
        ]);

        if ($this->command === null) {
            return;
        }

        $this->command->newLine();
        $this->command->info('Demo seed complete.');
        foreach ($validator->counts() as $label => $count) {
            $this->command->line(sprintf('  %-22s %s', $label.':', $count));
        }
        $this->command->line('Run "php artisan demo-data:validate" to check the dataset.');
    }
}
