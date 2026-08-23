<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix external_applications.created_by.
 *
 * It was declared as UUID, but it records the EUISIS user who registered the
 * application and `users.id` is a BIGINT. Inserting `Auth::id()` therefore
 * failed with "invalid input syntax for type uuid".
 *
 * VARCHAR keeps the column agnostic: it accepts the current bigint ids and
 * would still accept UUIDs if the user key type ever changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('external_applications', 'created_by')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE external_applications ALTER COLUMN created_by TYPE VARCHAR(64) USING created_by::VARCHAR');

            return;
        }

        // SQLite is dynamically typed; MySQL needs an explicit MODIFY.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE external_applications MODIFY created_by VARCHAR(64) NULL');
        }
    }

    public function down(): void
    {
        // Deliberately irreversible: bigint ids cannot be cast back to uuid.
    }
};
