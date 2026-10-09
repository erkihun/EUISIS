<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separates cafeteria provider authentication from the admin users table.
 *
 * 1. Renames the existing pivot table (users ↔ providers) to
 *    cafeteria_provider_assignments, keeping all data intact.
 *
 * 2. Creates cafeteria_provider_users as a standalone credential table
 *    whose rows are Authenticatable via the cafeteria_provider guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1 — rename old pivot to cafeteria_provider_assignments
        if (Schema::hasTable('cafeteria_provider_users') && ! Schema::hasTable('cafeteria_provider_assignments')) {
            Schema::rename('cafeteria_provider_users', 'cafeteria_provider_assignments');
        }

        // Step 2 — create the new credential table
        if (! Schema::hasTable('cafeteria_provider_users')) {
            Schema::create('cafeteria_provider_users', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('cafeteria_provider_id');
                $table->foreign('cafeteria_provider_id', 'cpu_cred_provider_fk')
                    ->references('id')->on('cafeteria_providers')->cascadeOnDelete();

                $table->string('name');
                $table->string('email')->nullable()->unique();
                $table->string('username')->nullable()->unique();
                $table->text('phone_number')->nullable(); // encrypted at app layer
                $table->string('password');
                $table->string('status')->default('active')->index();
                $table->boolean('portal_enabled')->default(true)->index();
                $table->boolean('must_change_password')->default(false);

                $table->timestamp('last_login_at')->nullable();
                $table->string('last_login_ip')->nullable();
                $table->timestamp('email_verified_at')->nullable();
                $table->rememberToken();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->unsignedBigInteger('suspended_by')->nullable();
                $table->foreign('created_by', 'cpu_cred_created_by_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('updated_by', 'cpu_cred_updated_by_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('suspended_by', 'cpu_cred_suspended_by_fk')->references('id')->on('users')->nullOnDelete();
                $table->timestamp('suspended_at')->nullable();
                $table->text('suspension_reason')->nullable();

                $table->json('metadata')->nullable();
                $table->softDeletes();
                $table->timestamps();

                $table->index('cafeteria_provider_id');
                $table->index(['status', 'portal_enabled']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cafeteria_provider_users');

        if (Schema::hasTable('cafeteria_provider_assignments') && ! Schema::hasTable('cafeteria_provider_users')) {
            Schema::rename('cafeteria_provider_assignments', 'cafeteria_provider_users');
        }
    }
};
