<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_provider_users', function (Blueprint $table): void {
            if (Schema::hasColumn('service_provider_users', 'service_provider_id')) {
                $table->foreignUuid('service_provider_id')->nullable()->change();
            }

            if (! Schema::hasColumn('service_provider_users', 'service_type_id')) {
                $table->foreignUuid('service_type_id')
                    ->nullable()
                    ->after('service_provider_id')
                    ->constrained('service_types')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_provider_users', function (Blueprint $table): void {
            if (Schema::hasColumn('service_provider_users', 'service_type_id')) {
                $table->dropForeign(['service_type_id']);
                $table->dropColumn('service_type_id');
            }
        });
    }
};
