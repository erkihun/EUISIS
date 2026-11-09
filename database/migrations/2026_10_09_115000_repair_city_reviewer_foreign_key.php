<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs assessment_institution_submissions.city_reviewer_id on databases
 * that ran the original 2026_10_08_100500 migration, which pointed the
 * foreign key at a non-existent users column. PostgreSQL always refused that
 * migration, so only SQLite databases can carry the broken key, and on them
 * every update to a users row fails with "foreign key mismatch".
 *
 * Idempotent: a key that already references users.id is left alone, so this
 * is a no-op on every database that ran the corrected migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assessment_institution_submissions')
            || ! Schema::hasColumn('assessment_institution_submissions', 'city_reviewer_id')) {
            return;
        }

        $key = collect(Schema::getForeignKeys('assessment_institution_submissions'))
            ->first(fn (array $foreign): bool => $foreign['columns'] === ['city_reviewer_id']);

        if ($key !== null && $key['foreign_table'] === 'users' && $key['foreign_columns'] === ['id']) {
            return;
        }

        Schema::table('assessment_institution_submissions', function (Blueprint $table) use ($key): void {
            if ($key !== null) {
                $table->dropForeign($key['name'] ?? ['city_reviewer_id']);
            }
            $table->foreign('city_reviewer_id', 'ais_city_reviewer_fk')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // The corrected key is the intended schema; nothing to restore.
    }
};
