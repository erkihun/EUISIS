<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'user_type')) {
                $table->string('user_type')->default('staff')->after('status')->index();
            }

            if (! Schema::hasColumn('users', 'cafeteria_provider_id')) {
                $table->foreignUuid('cafeteria_provider_id')
                    ->nullable()
                    ->after('default_organization_id')
                    ->constrained('cafeteria_providers')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('users', 'provider_portal_enabled')) {
                $table->boolean('provider_portal_enabled')->default(false)->after('cafeteria_provider_id')->index();
            }

            if (! Schema::hasColumn('users', 'provider_portal_activated_at')) {
                $table->timestamp('provider_portal_activated_at')->nullable()->after('provider_portal_enabled');
            }
        });

        if (Schema::hasTable('cafeteria_provider_users')) {
            Schema::table('cafeteria_provider_users', function (Blueprint $table): void {
                if (! Schema::hasColumn('cafeteria_provider_users', 'provider_role')) {
                    $table->string('provider_role')->nullable()->after('role');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'cafeteria_provider_id')) {
                $table->dropConstrainedForeignId('cafeteria_provider_id');
            }

            foreach (['provider_portal_activated_at', 'provider_portal_enabled', 'user_type'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasTable('cafeteria_provider_users') && Schema::hasColumn('cafeteria_provider_users', 'provider_role')) {
            Schema::table('cafeteria_provider_users', function (Blueprint $table): void {
                $table->dropColumn('provider_role');
            });
        }
    }
};
