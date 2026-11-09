<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('code_rules', function (Blueprint $table): void {
            $table->string('sequence_scope_strategy')->default('auto')->after('next_number');
            $table->json('sequence_scope_tokens')->nullable()->after('sequence_scope_strategy');
        });
    }

    public function down(): void
    {
        Schema::table('code_rules', function (Blueprint $table): void {
            $table->dropColumn(['sequence_scope_strategy', 'sequence_scope_tokens']);
        });
    }
};
