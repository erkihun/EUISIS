<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * court-ms link on the Administrative Tribunal register (ADR 0013,
 * docs/integration/court-ms.md). The register keeps its own TRB- number; the
 * court-ms case number sits beside it with the latest states read from
 * court-ms. court_ms_sync_state holds the update-list cursor.
 *
 * Additive and re-runnable: every column and the table are checked first.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'referral_state', 'referral_attempts', 'referral_error', 'referred_at', 'referred_by_user_id', 'referral_payload',
        'court_case_id', 'court_case_number', 'court_case_code', 'court_review_status', 'court_review_reason',
        'court_status', 'court_synced_at',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('administrative_tribunal_cases', 'referral_state')) {
            Schema::table('administrative_tribunal_cases', function (Blueprint $table): void {
                // null = never sent; queued, sent or failed afterwards.
                $table->string('referral_state', 20)->nullable();
                $table->unsignedSmallInteger('referral_attempts')->default(0);
                $table->string('referral_error', 500)->nullable();
                $table->dateTime('referred_at')->nullable();
                $table->foreignId('referred_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                // What the officer chose to send (case type, title, summary, documents), kept for retries and the record.
                $table->json('referral_payload')->nullable();
                $table->unsignedBigInteger('court_case_id')->nullable();
                $table->string('court_case_number', 80)->nullable()->unique('admin_tribunal_court_case_number_unique');
                $table->string('court_case_code', 20)->nullable();
                $table->string('court_review_status', 30)->nullable();
                $table->text('court_review_reason')->nullable();
                $table->string('court_status', 30)->nullable();
                $table->dateTime('court_synced_at')->nullable();
                $table->index('referral_state', 'admin_tribunal_referral_state_idx');
            });
        }

        if (! Schema::hasTable('court_ms_sync_state')) {
            Schema::create('court_ms_sync_state', function (Blueprint $table): void {
                $table->string('key', 60)->primary();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('court_ms_sync_state');

        if (Schema::hasColumn('administrative_tribunal_cases', 'referral_state')) {
            Schema::table('administrative_tribunal_cases', function (Blueprint $table): void {
                $table->dropForeign(['referred_by_user_id']);
                $table->dropUnique('admin_tribunal_court_case_number_unique');
                $table->dropIndex('admin_tribunal_referral_state_idx');
                $table->dropColumn(self::COLUMNS);
            });
        }
    }
};
