<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the auditable city-review start point without changing submitted
 * snapshots. Existing submissions stay valid; only new review metadata is
 * added, so this migration is safe for in-flight cycles.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assessment_institution_submissions')) {
            return;
        }

        Schema::table('assessment_institution_submissions', function (Blueprint $table): void {
            if (! Schema::hasColumn('assessment_institution_submissions', 'city_reviewer_id')) {
                // constrained($table, $column, $indexName): the constraint name is the
                // THIRD argument. Passing it second made the key reference a
                // non-existent users column, which PostgreSQL refuses outright and
                // SQLite accepts but then fails every users update ("foreign key
                // mismatch"). 2026_10_09_115000 repairs databases that ran the old form.
                $table->foreignId('city_reviewer_id')->nullable()->after('return_reason')
                    ->constrained('users', 'id', 'ais_city_reviewer_fk')->restrictOnDelete();
            }
            if (! Schema::hasColumn('assessment_institution_submissions', 'review_started_at')) {
                $table->dateTime('review_started_at')->nullable()->after('city_reviewer_id');
            }
            if (! Schema::hasColumn('assessment_institution_submissions', 'last_verification_reminded_at')) {
                $table->dateTime('last_verification_reminded_at')->nullable()->after('outdated_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('assessment_institution_submissions')) {
            return;
        }

        Schema::table('assessment_institution_submissions', function (Blueprint $table): void {
            if (Schema::hasColumn('assessment_institution_submissions', 'city_reviewer_id')) {
                $table->dropForeign('ais_city_reviewer_fk');
                $table->dropColumn('city_reviewer_id');
            }
            if (Schema::hasColumn('assessment_institution_submissions', 'review_started_at')) {
                $table->dropColumn('review_started_at');
            }
            if (Schema::hasColumn('assessment_institution_submissions', 'last_verification_reminded_at')) {
                $table->dropColumn('last_verification_reminded_at');
            }
        });
    }
};
