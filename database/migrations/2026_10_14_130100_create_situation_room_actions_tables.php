<?php

declare(strict_types=1);

use App\Support\Database\SchemaResume;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Situation Room Action Register: a management follow-up recorded
 * against an institution, with an owner, an optional due date and an
 * append-only history. It never changes who handles a complaint — the
 * submission workflow keeps its own assignments.
 *
 * MySQL-safe and resumable (SchemaResume): mandatory moments are dateTime,
 * identifiers stay under 64 characters, no partial indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaResume::create('situation_room_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('source_type', 20)->nullable();
            $table->string('source_reference', 100)->nullable();
            $table->string('title', 200);
            $table->text('action_text')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('owner_organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('priority', 20)->default('normal');
            $table->string('status', 20)->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'sr_actions_org_status_idx');
            $table->index(['owner_user_id', 'status'], 'sr_actions_owner_status_idx');
            $table->index(['status', 'due_date'], 'sr_actions_status_due_idx');
        });

        SchemaResume::create('situation_room_action_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('action_id')->constrained('situation_room_actions')->cascadeOnDelete();
            $table->string('event', 30);
            $table->string('from_value', 100)->nullable();
            $table->string('to_value', 100)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('occurred_at');

            $table->index(['action_id', 'occurred_at'], 'sr_action_events_action_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('situation_room_action_events');
        Schema::dropIfExists('situation_room_actions');
    }
};
