<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core platform access and audit: what a Core user may do (role) and where
 * (an organization and everything under it in the published hierarchy, or the
 * whole city when empty), and the append-only audit log of every master-data
 * change made through the Core.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 32)->default('core_viewer')->after('password');
            $table->uuid('scope_organization_id')->nullable()->after('role');
            $table->boolean('is_active')->default(true)->after('scope_organization_id');
            $table->foreign('scope_organization_id')->references('id')->on('organizations')->nullOnDelete();
        });

        Schema::create('core_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('subject_type', 64);
            $table->uuid('subject_id')->nullable();
            $table->uuid('organization_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('reason', 1000)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('request_ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->dateTime('created_at');

            $table->index(['subject_type', 'subject_id'], 'core_audit_logs_subject_idx');
            $table->index('created_at', 'core_audit_logs_created_idx');
            $table->index('request_id', 'core_audit_logs_request_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_audit_logs');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['scope_organization_id']);
            $table->dropColumn(['role', 'scope_organization_id', 'is_active']);
        });
    }
};
