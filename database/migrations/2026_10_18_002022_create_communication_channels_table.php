<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communication: organization-aware channels. Each channel wraps one conversation
 * stream (conversations.type = CHANNEL); membership lives in conversation_members
 * with source MANUAL or AUTO, so there is one read-state store, not two.
 *
 * Automatic membership follows the configurable organization hierarchy: a
 * channel bound to an organization (optionally with its subtree) or to a unit
 * (optionally with its sub-units). No level names (Bureau, Sub-city, Woreda)
 * are encoded anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->unique()->constrained('conversations')->cascadeOnDelete();
            $table->string('slug', 80);
            $table->string('name');
            $table->string('name_am')->nullable();
            $table->text('description')->nullable();
            // PUBLIC_ORGANIZATION | PRIVATE | SYSTEM_MANAGED | PROJECT
            $table->string('type', 30);
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->boolean('include_descendants')->default(false);
            $table->boolean('auto_membership')->default(false);
            // On transfer: drop AUTO memberships that no longer match (MANUAL ones are never touched unless configured).
            $table->boolean('remove_on_transfer')->default(true);
            // ALL_MEMBERS | ADMINS_ONLY
            $table->string('posting_policy', 20)->default('ALL_MEMBERS');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'slug'], 'channels_org_slug_unique');
            $table->index(['type', 'organization_id'], 'channels_type_org_idx');
            $table->index(['organization_unit_id'], 'channels_unit_idx');
            $table->index(['auto_membership', 'archived_at'], 'channels_auto_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
