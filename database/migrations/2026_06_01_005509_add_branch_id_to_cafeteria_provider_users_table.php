<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cafeteria_provider_users', function (Blueprint $table): void {
            $table->foreignUuid('cafeteria_provider_branch_id')
                ->nullable()
                ->after('organization_id')
                ->constrained('cafeteria_provider_branches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cafeteria_provider_users', function (Blueprint $table): void {
            $table->dropForeign(['cafeteria_provider_branch_id']);
            $table->dropColumn('cafeteria_provider_branch_id');
        });
    }
};
