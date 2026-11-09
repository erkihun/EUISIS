<?php

declare(strict_types=1);

use Database\Seeders\GrievanceDefaultsSeeder;
use Illuminate\Database\Migrations\Migration;

/*
 * Code rules, reason codes, the carried-over SLA profile and default letter
 * templates, so installed systems and fresh installs start the same way.
 * Idempotent; down() leaves configuration in place (it may have been edited).
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
