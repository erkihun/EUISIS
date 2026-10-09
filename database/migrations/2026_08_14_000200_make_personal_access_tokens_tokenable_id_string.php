<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen personal_access_tokens.tokenable_id to a string.
 *
 * Sanctum ships this column as bigint, which works for the integer-keyed User
 * model but rejects UUID-keyed tokenables. ExternalApplication uses a UUID
 * primary key, so on PostgreSQL issuing a token to one fails outright with
 * "invalid input syntax for type bigint".
 *
 * A string column holds both, so existing user tokens keep working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE personal_access_tokens ALTER COLUMN tokenable_id TYPE VARCHAR(255) USING tokenable_id::VARCHAR');

            return;
        }

        if ($driver === 'sqlite') {
            // SQLite is dynamically typed; the existing column already accepts
            // both integers and UUID strings.
            return;
        }

        DB::statement('ALTER TABLE personal_access_tokens MODIFY tokenable_id VARCHAR(255) NOT NULL');
    }

    public function down(): void
    {
        // Deliberately irreversible: UUID values cannot be cast back to bigint
        // without losing every external-application token.
    }
};
