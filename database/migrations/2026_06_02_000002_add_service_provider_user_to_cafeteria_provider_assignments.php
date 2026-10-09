<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cafeteria_provider_assignments', function (Blueprint $table): void {
            if (Schema::hasColumn('cafeteria_provider_assignments', 'user_id')) {
                $table->foreignId('user_id')->nullable()->change();
            }

            if (! Schema::hasColumn('cafeteria_provider_assignments', 'service_provider_user_id')) {
                $table->foreignUuid('service_provider_user_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('service_provider_users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('cafeteria_provider_assignments', function (Blueprint $table): void {
            if (Schema::hasColumn('cafeteria_provider_assignments', 'service_provider_user_id')) {
                $table->dropForeign(['service_provider_user_id']);
                $table->dropColumn('service_provider_user_id');
            }
        });
    }
};
