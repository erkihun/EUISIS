<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time-limited elevation (Core Stage 6 break-glass, technical debt TD-17): a role
 * granted to a named account for a stated reason, approved by a second person,
 * removed automatically when it expires. Additive; guarded so a failed run on
 * MySQL can be resumed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('temporary_role_grants')) {
            return;
        }

        Schema::create('temporary_role_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role_name', 125);
            $table->string('reason', 1000);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('granted_at');
            $table->dateTime('expires_at');
            $table->dateTime('ended_at')->nullable();
            $table->string('end_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['ended_at', 'expires_at'], 'temporary_role_grants_open_idx');
            $table->index(['user_id', 'role_name'], 'temporary_role_grants_user_role_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temporary_role_grants');
    }
};
