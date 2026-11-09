<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('cafeteria_transactions', 'usage_mode')) {
            return;
        }

        Schema::table('cafeteria_transactions', function (Blueprint $table): void {
            $table->string('usage_mode')->default('single_day');
            $table->decimal('available_amount_before', 12, 2)->default(0);
            $table->date('week_start_date')->nullable();
            $table->date('week_end_date')->nullable();
            $table->unsignedSmallInteger('available_days_count')->default(0);
            $table->unsignedSmallInteger('consumed_days_count')->default(0);
            $table->string('blocked_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cafeteria_transactions', function (Blueprint $table): void {
            $table->dropColumn([
                'usage_mode', 'available_amount_before', 'week_start_date', 'week_end_date',
                'available_days_count', 'consumed_days_count', 'blocked_reason',
            ]);
        });
    }
};
