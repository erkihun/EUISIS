<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The duplicate review queue (ADR 0009). A detected pair is `pending` until a
 * reviewer decides: `not_duplicate`, `confirmed_duplicate` (the flagged record
 * is blocked until a controlled merge) or `needs_more_information`.
 *
 * Existing rows were the registrar's own "a different person" statements,
 * which the earlier rule accepted; they become `not_duplicate`, keep their
 * registrar as the decider and carry the statement as the registration
 * reason, so no review is invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_duplicate_reviews', function (Blueprint $table): void {
            $table->string('source', 16)->default('registration');
            $table->unsignedBigInteger('detected_by')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->text('registration_reason')->nullable();
            $table->text('notes')->nullable();
            $table->index(['decision', 'created_at'], 'employee_duplicate_reviews_queue_idx');
            $table->index(['matched_employee_id'], 'employee_duplicate_reviews_matched_idx');
        });

        self::backfill();
    }

    public static function backfill(): void
    {
        DB::table('employee_duplicate_reviews')->where('decision', 'distinct')->update([
            'decision' => 'not_duplicate',
            'registration_reason' => DB::raw('reason'),
            'detected_by' => DB::raw('decided_by'),
            'detected_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        DB::table('employee_duplicate_reviews')->where('decision', 'not_duplicate')->whereNotNull('registration_reason')->update(['decision' => 'distinct']);
        Schema::table('employee_duplicate_reviews', function (Blueprint $table): void {
            $table->dropIndex('employee_duplicate_reviews_queue_idx');
            $table->dropIndex('employee_duplicate_reviews_matched_idx');
            $table->dropColumn(['source', 'detected_by', 'detected_at', 'registration_reason', 'notes']);
        });
    }
};
