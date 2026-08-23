<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subsidy ledger + local cafeteria settings.
 *
 * Mirrors the EUISIS cafeteria module's ledger and settings concepts, but
 * keyed by employee_number rather than a foreign key — employees live in
 * EUISIS, which this application cannot reach.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cafeteria_subsidy_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->nullable()
                ->constrained('cafeteria_providers')->nullOnDelete();
            $table->foreignUuid('cafeteria_id')->nullable()
                ->constrained('cafeterias')->nullOnDelete();
            $table->foreignUuid('transaction_id')->nullable()
                ->constrained('cafeteria_service_transactions')->nullOnDelete();

            // Employee identified by business number, never by FK.
            $table->string('employee_number')->index();
            $table->string('employee_name')->nullable();
            $table->string('organization_code')->nullable()->index();

            // credit = subsidy granted, debit = subsidy consumed.
            $table->string('entry_type', 16)->index();
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('balance_after', 12, 2)->default(0);
            $table->date('entry_date')->index();
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['employee_number', 'entry_date']);
        });

        Schema::create('cafeteria_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->nullable()
                ->constrained('cafeteria_providers')->cascadeOnDelete();
            $table->string('key')->index();
            $table->text('value')->nullable();
            $table->string('type', 16)->default('string');
            $table->string('group', 32)->default('general')->index();
            $table->string('label')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cafeteria_settings');
        Schema::dropIfExists('cafeteria_subsidy_ledger');
    }
};
