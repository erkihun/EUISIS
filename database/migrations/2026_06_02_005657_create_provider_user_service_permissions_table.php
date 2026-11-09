<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('provider_user_service_permissions')) {
            Schema::table('provider_user_service_permissions', function (Blueprint $table): void {
                $table->unique(
                    ['provider_user_id', 'service_type_id', 'permission_key'],
                    'provider_user_perm_unique',
                );
                $table->index(['service_type_id', 'permission_key'], 'provider_user_perm_service_idx');
            });

            return;
        }

        Schema::create('provider_user_service_permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_user_id')->constrained('provider_users')->cascadeOnDelete();
            $table->foreignUuid('service_type_id')->constrained('service_types')->cascadeOnDelete();
            $table->string('permission_key');
            $table->boolean('is_allowed')->default(true);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_user_id', 'service_type_id', 'permission_key'], 'provider_user_perm_unique');
            $table->index(['service_type_id', 'permission_key'], 'provider_user_perm_service_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_user_service_permissions');
    }
};
