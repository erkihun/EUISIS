<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which usage mode an operator chose when serving.
 *
 * `single_day` consumes today only; `use_remaining_week` consumes the rest of
 * the subsidy week. Stored per transaction so a settlement can be reconstructed
 * exactly as it was authorised, even if the provider default changes later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cafeteria_service_transactions', function (Blueprint $table): void {
            $table->string('usage_mode', 32)->default('single_day')->after('service_type');
        });
    }

    public function down(): void
    {
        Schema::table('cafeteria_service_transactions', function (Blueprint $table): void {
            $table->dropColumn('usage_mode');
        });
    }
};
