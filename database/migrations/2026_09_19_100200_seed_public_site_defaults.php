<?php

declare(strict_types=1);

use Database\Seeders\PublicSiteDefaultsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Carries the public site's existing copy into the new CMS tables in every
 * environment, so the site does not go blank when it starts reading from them.
 * The seeder is idempotent and never overwrites administrator edits.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new PublicSiteDefaultsSeeder)->run();
    }

    public function down(): void
    {
        // Content rows are dropped with their tables in the create migration.
    }
};
