<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Provider settlements (docs/cafeteria-settlement-rules.md).
 *
 * The payee is the provider that delivered the service; every line is one
 * employee organization (the billing owner) at one service cafeteria. Totals
 * are copied from the transactions' applied (snapshot) amounts when the
 * settlement is built, and a finalized settlement stamps its transactions so
 * none can be settled twice or re-priced afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cafeteria_settlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('settlement_number', 40)->unique();
            $table->foreignUuid('provider_id')->constrained('providers')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedInteger('transaction_count')->default(0);
            $table->decimal('total_subsidy_amount', 14, 2)->default(0);
            $table->decimal('total_employee_amount', 14, 2)->default(0);
            $table->decimal('total_provider_amount', 14, 2)->default(0);
            $table->string('currency_code', 3)->default('ETB');
            $table->text('notes')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'period_start', 'period_end'], 'caf_settlement_period_idx');
        });

        Schema::create('cafeteria_settlement_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('cafeteria_settlement_id')->constrained('cafeteria_settlements')->cascadeOnDelete();
            // NULL = legacy transactions whose billing organization is unknown.
            $table->uuid('employee_organization_id')->nullable();
            $table->uuid('cafeteria_id');
            $table->unsignedInteger('transaction_count')->default(0);
            $table->decimal('subsidy_amount', 14, 2)->default(0);
            $table->decimal('employee_amount', 14, 2)->default(0);
            $table->decimal('provider_amount', 14, 2)->default(0);
            $table->timestamps();

            $table->foreign('employee_organization_id', 'caf_settlement_line_org_fk')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('cafeteria_id', 'caf_settlement_line_cafeteria_fk')->references('id')->on('cafeteria_providers')->restrictOnDelete();
        });

        Schema::table('cafeteria_transactions', function (Blueprint $table): void {
            $table->uuid('cafeteria_settlement_id')->nullable();
            $table->foreign('cafeteria_settlement_id', 'ct_settlement_fk')->references('id')->on('cafeteria_settlements')->nullOnDelete();
            $table->index('cafeteria_settlement_id', 'ct_settlement_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cafeteria_transactions', function (Blueprint $table): void {
            $table->dropForeign('ct_settlement_fk');
            $table->dropIndex('ct_settlement_idx');
            $table->dropColumn('cafeteria_settlement_id');
        });

        Schema::dropIfExists('cafeteria_settlement_lines');
        Schema::dropIfExists('cafeteria_settlements');
    }
};
