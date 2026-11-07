<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A grievance committee member is registered together with their signature
 * image (owner decision, 2026-11-07): the image captured at appointment is
 * kept on the term row (path + SHA-256, who recorded it and when) so minutes
 * and letters can show the signature as it was when the member served, and
 * so a committee cannot be approved while a serving member has none.
 *
 * Additive and nullable; guarded so a retry on MySQL can resume.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grievance_committee_members', function (Blueprint $table): void {
            if (! Schema::hasColumn('grievance_committee_members', 'signature_path')) {
                $table->string('signature_path', 255)->nullable();
            }
            if (! Schema::hasColumn('grievance_committee_members', 'signature_sha256')) {
                $table->string('signature_sha256', 64)->nullable();
            }
            if (! Schema::hasColumn('grievance_committee_members', 'signature_recorded_at')) {
                $table->dateTime('signature_recorded_at')->nullable();
            }
            if (! Schema::hasColumn('grievance_committee_members', 'signature_recorded_by')) {
                $table->unsignedBigInteger('signature_recorded_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('grievance_committee_members', function (Blueprint $table): void {
            foreach (['signature_path', 'signature_sha256', 'signature_recorded_at', 'signature_recorded_by'] as $column) {
                if (Schema::hasColumn('grievance_committee_members', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
