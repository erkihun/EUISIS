<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeds nothing (ADR 0011): the Core has no demo accounts and no default
 * password. The first account is made with `php artisan core:create-user`
 * (a one-time random password, changed at first sign-in); a throwaway UAT
 * database is filled with `php artisan core:seed-uat`, which refuses
 * production.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Nothing to seed. Use core:create-user for accounts, or core:seed-uat on a throwaway database.');
    }
}
