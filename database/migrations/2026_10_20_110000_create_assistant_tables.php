<?php

declare(strict_types=1);

use App\Support\Database\SchemaResume;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addis One Assistant conversations (docs/assistant/architecture.md, A-6).
 *
 * assistant_conversations  one per user thread; archived when a new one starts
 * assistant_messages       user and assistant turns with their allow-listed
 *                          blocks; never model reasoning, never secrets
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaResume::create('assistant_conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 120)->nullable();
            $table->string('module', 60)->nullable();
            $table->string('route_name', 190)->nullable();
            $table->string('status', 15)->default('active');
            $table->unsignedInteger('message_count')->default(0);
            $table->dateTime('last_activity_at');
            $table->timestamps();
            $table->index(['user_id', 'status', 'last_activity_at'], 'assistant_conversations_user_idx');
            $table->index('last_activity_at', 'assistant_conversations_activity_idx');
        });

        SchemaResume::create('assistant_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->string('role', 10);
            $table->text('text');
            $table->json('blocks')->nullable();
            $table->string('mode', 25)->nullable();
            $table->json('tools')->nullable();
            $table->string('status', 15)->nullable();
            $table->boolean('ai_used')->default(false);
            $table->uuid('ai_run_id')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('feedback', 15)->nullable();
            $table->dateTime('created_at');
            $table->index(['conversation_id', 'created_at'], 'assistant_messages_conversation_idx');
            $table->index('created_at', 'assistant_messages_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_conversations');
    }
};
