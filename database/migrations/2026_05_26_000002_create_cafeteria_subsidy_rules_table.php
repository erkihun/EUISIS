<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cafeteria_subsidy_rules')) {
            return;
        }

        Schema::create('cafeteria_subsidy_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->decimal('subsidy_amount', 12, 2);
            $table->string('currency', 10)->default('ETB');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('applies_to')->default('all_employees')->index();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('employee_type')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('exclude_weekends')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('deletion_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['applies_to', 'organization_id', 'is_active'], 'csr_applies_org_active_idx');
            $table->index(['effective_from', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cafeteria_subsidy_rules');
    }
};
