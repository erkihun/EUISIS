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
        Schema::table('code_rules', function (Blueprint $table): void {
            $table->integer('initial_sequence_number')->default(1)->after('next_number');
        });

        // For existing rules, initialise to 1. The admin-configured starting value
        // may have been lost if next_number was synced by global-scope generation,
        // so 1 is the safest default. Admins can adjust via the edit form if needed.
        DB::table('code_rules')->update(['initial_sequence_number' => 1]);
    }

    public function down(): void
    {
        Schema::table('code_rules', function (Blueprint $table): void {
            $table->dropColumn('initial_sequence_number');
        });
    }
};
