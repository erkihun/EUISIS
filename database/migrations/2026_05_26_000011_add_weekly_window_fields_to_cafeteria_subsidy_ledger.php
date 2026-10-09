<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('cafeteria_subsidy_ledger', 'allocated_for_date')) {
            return;
        }

        Schema::table('cafeteria_subsidy_ledger', function (Blueprint $table): void {
            $table->date('allocated_for_date')->nullable()->index();
            $table->date('week_start_date')->nullable();
            $table->date('week_end_date')->nullable();
            $table->string('usage_mode')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cafeteria_subsidy_ledger', function (Blueprint $table): void {
            $table->dropIndex(['allocated_for_date']);
            $table->dropColumn(['allocated_for_date', 'week_start_date', 'week_end_date', 'usage_mode']);
        });
    }
};
