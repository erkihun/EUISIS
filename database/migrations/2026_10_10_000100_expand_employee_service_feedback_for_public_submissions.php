<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_service_feedback', function (Blueprint $table): void {
            $table->string('type', 16)->default('feedback')->index();
            $table->foreignUuid('employee_assignment_id')->nullable()->constrained('employee_assignments')->nullOnDelete();
            $table->string('reference_number', 80)->nullable()->unique();
            $table->date('incident_date')->nullable();
            $table->string('incident_location', 255)->nullable();
            $table->index(['type', 'created_at']);
        });

        Schema::table('employee_service_feedback', function (Blueprint $table): void {
            $table->unsignedTinyInteger('rating')->nullable()->change();
        });

        Schema::create('employee_service_feedback_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_service_feedback_id')->constrained('employee_service_feedback')->cascadeOnDelete();
            $table->string('disk', 32);
            $table->string('path');
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->string('scan_status', 24)->default('not_scanned');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('employee_service_feedback')->where('type', '!=', 'feedback')->exists()
            || DB::table('employee_service_feedback')->whereNull('rating')->exists()
            || DB::table('employee_service_feedback_attachments')->exists()) {
            throw new RuntimeException('Export or migrate public suggestion/complaint data before rolling back this schema.');
        }

        Schema::dropIfExists('employee_service_feedback_attachments');

        Schema::table('employee_service_feedback', function (Blueprint $table): void {
            $table->unsignedTinyInteger('rating')->nullable(false)->change();
        });

        Schema::table('employee_service_feedback', function (Blueprint $table): void {
            $table->dropIndex(['type', 'created_at']);
            $table->dropForeign(['employee_assignment_id']);
            $table->dropUnique(['reference_number']);
            $table->dropColumn(['type', 'employee_assignment_id', 'reference_number', 'incident_date', 'incident_location']);
        });
    }
};
