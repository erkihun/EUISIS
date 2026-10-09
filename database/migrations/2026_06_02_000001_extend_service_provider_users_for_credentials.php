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

            if (Schema::hasColumn('service_provider_users', 'user_id')) {
                $table->foreignId('user_id')->nullable()->change();
            }

            if (! Schema::hasColumn('service_provider_users', 'name')) {
                $table->string('name')->nullable()->after('service_provider_id');
            }

            if (! Schema::hasColumn('service_provider_users', 'email')) {
                $table->string('email')->nullable()->unique()->after('name');
            }

            if (! Schema::hasColumn('service_provider_users', 'username')) {
                $table->string('username', 100)->nullable()->unique()->after('email');
            }

            if (! Schema::hasColumn('service_provider_users', 'phone_number')) {
                $table->text('phone_number')->nullable()->after('username');
            }

            if (! Schema::hasColumn('service_provider_users', 'password')) {
                $table->string('password')->nullable()->after('phone_number');
            }

            if (! Schema::hasColumn('service_provider_users', 'status')) {
                $table->string('status')->default('active')->index()->after('password');
            }

            if (! Schema::hasColumn('service_provider_users', 'portal_enabled')) {
                $table->boolean('portal_enabled')->default(true)->index()->after('status');
            }

            if (! Schema::hasColumn('service_provider_users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(false)->after('portal_enabled');
            }

            if (! Schema::hasColumn('service_provider_users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('must_change_password');
            }

            if (! Schema::hasColumn('service_provider_users', 'last_login_ip')) {
                $table->string('last_login_ip')->nullable()->after('last_login_at');
            }

            if (! Schema::hasColumn('service_provider_users', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable()->after('last_login_ip');
            }

            if (! Schema::hasColumn('service_provider_users', 'remember_token')) {
                $table->rememberToken()->after('email_verified_at');
            }

            if (! Schema::hasColumn('service_provider_users', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('remember_token')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('service_provider_users', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('service_provider_users', 'suspended_by')) {
                $table->foreignId('suspended_by')->nullable()->after('updated_by')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('service_provider_users', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable()->after('suspended_by');
            }

            if (! Schema::hasColumn('service_provider_users', 'suspension_reason')) {
                $table->text('suspension_reason')->nullable()->after('suspended_at');
            }

            if (! Schema::hasColumn('service_provider_users', 'metadata')) {
                $table->json('metadata')->nullable()->after('suspension_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_provider_users', function (Blueprint $table): void {
            if (Schema::hasColumn('service_provider_users', 'service_type_id')) {
                $table->dropForeign(['service_type_id']);
            }

            foreach (['suspended_by', 'updated_by', 'created_by'] as $foreignColumn) {
                if (Schema::hasColumn('service_provider_users', $foreignColumn)) {
                    $table->dropForeign([$foreignColumn]);
                }
            }

            foreach ([
                'metadata',
                'suspension_reason',
                'suspended_at',
                'suspended_by',
                'updated_by',
                'created_by',
                'remember_token',
                'email_verified_at',
                'last_login_ip',
                'last_login_at',
                'must_change_password',
                'portal_enabled',
                'status',
                'password',
                'phone_number',
                'username',
                'email',
                'name',
                'service_type_id',
            ] as $column) {
                if (Schema::hasColumn('service_provider_users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
