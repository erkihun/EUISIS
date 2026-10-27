<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Access and audit hardening, compatible with the rows already stored:
 *  - core_audit_logs.subject_id held a UUID column type, but accounts have
 *    integer ids and a settings group is named by its key, so on PostgreSQL
 *    every user and settings change failed. It becomes a short string; every
 *    existing UUID value keeps its text.
 *  - audit rows say where a change came from (the Core frontend, an API client
 *    or the console) and which API client made it;
 *  - accounts record when the password was last changed, whether the one-time
 *    password must still be replaced, and the last sign-in; earlier password
 *    hashes are kept so a password is not reused.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE core_audit_logs ALTER COLUMN subject_id TYPE varchar(64) USING subject_id::text');
        } else {
            Schema::table('core_audit_logs', function (Blueprint $table): void {
                $table->string('subject_id', 64)->nullable()->change();
            });
        }

        Schema::table('core_audit_logs', function (Blueprint $table): void {
            $table->string('source', 32)->nullable();
            $table->uuid('api_client_id')->nullable();
            $table->index(['organization_id', 'created_at'], 'core_audit_logs_organization_created_idx');
            $table->index('action', 'core_audit_logs_action_idx');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('password_changed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
        });

        Schema::create('password_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('password', 255);
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'created_at'], 'password_histories_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_histories');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['must_change_password', 'password_changed_at', 'last_login_at']);
        });
        Schema::table('core_audit_logs', function (Blueprint $table): void {
            $table->dropIndex('core_audit_logs_organization_created_idx');
            $table->dropIndex('core_audit_logs_action_idx');
            $table->dropColumn(['source', 'api_client_id']);
        });
        // subject_id stays a string: rows written since then may not be UUIDs.
    }
};
