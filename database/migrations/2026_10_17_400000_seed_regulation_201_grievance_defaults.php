<?php

declare(strict_types=1);

use Database\Seeders\GrievanceDefaultsSeeder;
use Illuminate\Database\Migrations\Migration;

/*
 * Regulation 201/2018 grievance defaults for systems that already ran the first
 * defaults migration: the Art. 31 grievance categories and the Art. 34(3)
 * 15-working-day committee SLA profile. The seeder is idempotent and keyed, so
 * this adds only what is missing and never overwrites an edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new GrievanceDefaultsSeeder)->run();
    }

    public function down(): void
    {
        // Intentionally empty: seeded configuration may be in use and edited.
    }
};
