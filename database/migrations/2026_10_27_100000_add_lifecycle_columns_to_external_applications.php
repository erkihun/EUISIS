<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API client lifecycle (Golden Path Stage 1, API client foundation): an approval
 * expiry, a terminal revocation record (when, by whom, why) and an optional
 * owner organization beside the free-text owner institution, which stays because
 * many partners are not organizations in the register.
 *
 * Additive, nullable columns; every step is guarded so a failed run on MySQL
 * (DDL is not transactional there) can be resumed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_applications', function (Blueprint $table): void {
            if (! Schema::hasColumn('external_applications', 'expires_at')) {
                $table->dateTime('expires_at')->nullable();
            }
            if (! Schema::hasColumn('external_applications', 'revoked_at')) {
                $table->dateTime('revoked_at')->nullable();
            }
            if (! Schema::hasColumn('external_applications', 'revoked_by')) {
                $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('external_applications', 'revocation_reason')) {
                $table->string('revocation_reason', 1000)->nullable();
            }
            if (! Schema::hasColumn('external_applications', 'owner_organization_id')) {
                $table->foreignUuid('owner_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('external_applications', function (Blueprint $table): void {
            foreach (['revoked_by', 'owner_organization_id'] as $column) {
                if (Schema::hasColumn('external_applications', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }
            foreach (['expires_at', 'revoked_at', 'revocation_reason'] as $column) {
                if (Schema::hasColumn('external_applications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
