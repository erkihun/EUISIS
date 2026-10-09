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
            $table->foreignUuid('organization_id')
                ->nullable()
                ->after('cafeteria_provider_id')
                ->constrained('organizations')
                ->nullOnDelete();

            // One user manages one organization within a provider
            $table->unique(
                ['cafeteria_provider_id', 'user_id', 'organization_id'],
                'cpu_provider_user_org_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('cafeteria_provider_users', function (Blueprint $table): void {
            $table->dropUnique('cpu_provider_user_org_unique');
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });
    }
};
