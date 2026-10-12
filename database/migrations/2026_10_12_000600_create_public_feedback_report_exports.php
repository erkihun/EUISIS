<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Queued exports of the public submissions report (brief §87). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_feedback_report_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->string('format', 10);
            $table->json('filters')->nullable();
            $table->string('status', 20)->default('queued');
            $table->string('disk', 40)->default('local');
            $table->string('path')->nullable();
            $table->string('filename')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['requested_by', 'created_at'], 'pf_report_exports_requester_idx');
            $table->index(['status'], 'pf_report_exports_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_feedback_report_exports');
    }
};
