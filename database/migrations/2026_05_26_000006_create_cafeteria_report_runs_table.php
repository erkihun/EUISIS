<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cafeteria_report_runs')) {
            return;
        }

        Schema::create('cafeteria_report_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('report_number')->unique();
            $table->string('report_type')->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status')->default('pending')->index();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->string('file_path')->nullable();
            $table->json('filters')->nullable();
            $table->json('totals')->nullable();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cafeteria_report_runs');
    }
};
