<?php

declare(strict_types=1);

use App\Support\Database\SchemaResume;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Addis One Assistant — minimal support domain (docs/assistant/support-helpdesk.md,
 * architecture decision A-7). A ticket is one request for help from a signed-in
 * user: where they were, what went wrong in their words, a user-chosen
 * category and priority, and a SAFE diagnostic context (allow-listed keys,
 * redacted values — never cookies, tokens, headers, HTML or form payloads).
 * Every status, assignment and note change is an immutable event row.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropFoundationPlaceholder();

        SchemaResume::create('support_tickets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference', 20)->unique('support_tickets_reference_unique');
            $table->foreignId('reporter_user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('organization_id')->nullable();
            $table->string('module', 60)->nullable();
            $table->string('route_name', 190)->nullable();
            $table->string('path', 255)->nullable();
            $table->string('category', 30);
            $table->string('priority', 10)->default('normal');
            $table->string('title', 190);
            $table->text('description');
            $table->json('context')->nullable();
            $table->string('error_reference', 40)->nullable();
            $table->uuid('incident_id')->nullable();
            $table->uuid('conversation_id')->nullable();
            $table->string('status', 20)->default('open');
            $table->string('assigned_team', 60)->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->index(['reporter_user_id', 'created_at'], 'support_tickets_reporter_created_idx');
            $table->index(['status', 'created_at'], 'support_tickets_status_created_idx');
        });

        SchemaResume::create('support_ticket_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->string('event', 30);
            $table->string('from_value', 190)->nullable();
            $table->string('to_value', 190)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');
            $table->index(['ticket_id', 'occurred_at'], 'support_ticket_events_ticket_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_events');
        Schema::dropIfExists('support_tickets');
    }

    /**
     * 2026_05_09_160000_create_domain_foundation_tables created an empty
     * placeholder `support_tickets` (opened_by, subject, status) that no model
     * or route ever wrote to. It is replaced here; a placeholder that somehow
     * holds rows is left alone and the migration stops so nobody loses data.
     */
    private function dropFoundationPlaceholder(): void
    {
        if (! Schema::hasTable('support_tickets') || ! Schema::hasColumn('support_tickets', 'opened_by')) {
            return;
        }
        if (DB::table('support_tickets')->exists()) {
            throw new RuntimeException('support_tickets still holds rows from the foundation placeholder; move them before running this migration.');
        }

        Schema::dropIfExists('support_tickets');
    }
};
