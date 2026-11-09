<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cafeteria_provider_users')) {
            Schema::create('cafeteria_provider_users', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('cafeteria_provider_id');
                $table->foreignId('user_id');
                $table->string('role')->nullable();
                $table->boolean('is_active')->default(true);
                $table->foreignId('assigned_by')->nullable();
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->timestamps();

                $table->foreign('cafeteria_provider_id', 'cpu_provider_fk')
                    ->references('id')->on('cafeteria_providers')->cascadeOnDelete();
                $table->foreign('user_id', 'cpu_user_fk')
                    ->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('assigned_by', 'cpu_assigned_by_fk')
                    ->references('id')->on('users')->nullOnDelete();
                $table->unique(['cafeteria_provider_id', 'user_id', 'is_active'], 'cpu_provider_user_active_unique');
                $table->index(['user_id', 'is_active']);
                $table->index(['effective_from', 'effective_to']);
            });
        }

        Schema::table('cafeteria_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('cafeteria_transactions', 'scan_nonce')) {
                $table->uuid('scan_nonce')->nullable()->unique();
            }

            if (! Schema::hasColumn('cafeteria_transactions', 'scan_request_hash')) {
                $table->string('scan_request_hash', 64)->nullable()->unique();
            }

            if (! Schema::hasColumn('cafeteria_transactions', 'fulfilled_at')) {
                $table->timestamp('fulfilled_at')->nullable();
            }
        });

        if (! Schema::hasTable('cafeteria_transaction_consumed_days')) {
            Schema::create('cafeteria_transaction_consumed_days', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('cafeteria_transaction_id');
                $table->foreignUuid('employee_id');
                $table->date('consumed_date');
                $table->decimal('subsidy_amount', 12, 2)->default(0);
                $table->boolean('is_working_day')->default(true);
                $table->string('source')->default('scan');
                $table->timestamp('reversed_at')->nullable();
                $table->foreignId('reversed_by')->nullable();
                $table->uuid('reversal_transaction_id')->nullable();
                $table->timestamps();

                $table->foreign('cafeteria_transaction_id', 'ctcd_transaction_fk')
                    ->references('id')->on('cafeteria_transactions')->cascadeOnDelete();
                $table->foreign('employee_id', 'ctcd_employee_fk')
                    ->references('id')->on('employees')->cascadeOnDelete();
                $table->foreign('reversed_by', 'ctcd_reversed_by_fk')
                    ->references('id')->on('users')->nullOnDelete();
                $table->index(['employee_id', 'consumed_date', 'reversed_at'], 'ctcd_employee_date_reversed_idx');
                $table->index(['cafeteria_transaction_id', 'consumed_date'], 'ctcd_transaction_date_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cafeteria_transaction_consumed_days');

        Schema::table('cafeteria_transactions', function (Blueprint $table): void {
            $table->dropUnique(['scan_nonce']);
            $table->dropUnique(['scan_request_hash']);
            $table->dropColumn(['scan_nonce', 'scan_request_hash', 'fulfilled_at']);
        });

        Schema::dropIfExists('cafeteria_provider_users');
    }
};
