<?php

declare(strict_types=1);

use App\Support\Database\SchemaResume;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization Design & Workforce Planning, phase 9
 * (docs/organization-design-workforce-planning-plan.md §3.8): one row per
 * queued report export — who asked for which report with which filters, and
 * where the private file landed once the job finished.
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaResume::create('organization_design_report_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->string('report', 40);
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

            $table->index(['requested_by', 'created_at'], 'od_report_exports_requester_idx');
            $table->index(['status'], 'od_report_exports_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_design_report_exports');
    }
};
