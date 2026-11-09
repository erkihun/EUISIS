<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Position matching rules
            $table->boolean('require_same_position')->default(false);
            $table->boolean('require_same_grade')->default(false);
            $table->boolean('require_same_salary')->default(false);

            // Cross-institution
            $table->boolean('allow_cross_institution')->default(true);

            // Override
            $table->boolean('allow_exceptional_override')->default(false);
            $table->json('override_approver_roles')->nullable();

            // Document requirements
            $table->json('required_documents')->nullable();

            // Minimum service before transfer
            $table->unsignedSmallInteger('minimum_service_months')->default(0);

            // Consent flags
            $table->boolean('releasing_consent_required')->default(true);
            $table->boolean('receiving_consent_required')->default(true);
            $table->boolean('final_approval_required')->default(false);

            // Post-transfer policies
            $table->string('card_reprint_policy', 30)->default('request_reprint');
            $table->string('service_recalculation_policy', 40)->default('recalculate_from_effective_date');

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_settings');
    }
};
