<?php

declare(strict_types=1);

use App\Enums\OrganizationalChangeRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organizational change request workflow.
 *
 * Requests never touch master data while pending: the proposed state lives in
 * the items table as JSON alongside a snapshot of the current state, and the
 * real organization_units / positions rows are only written during the
 * controlled implementation step.
 *
 * Searchable workflow fields (status, type, organization, dates, actors) are
 * real columns so the review and implementation queues can filter and index
 * on them. Only the proposed/before payloads are JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizational_change_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('request_no', 32)->unique();

            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('request_type', 64);
            $table->string('category', 32);
            $table->string('status', 32)->default(OrganizationalChangeRequestStatus::Draft->value);
            $table->string('priority', 16)->default('normal');

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->date('requested_effective_date')->nullable();

            $table->timestamp('submitted_at')->nullable();

            // Review
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('review_started_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('decision_comment')->nullable();

            /*
             * The approved payload is frozen at approval time and fingerprinted.
             * The implementation service refuses to run if the stored items no
             * longer hash to this value, so an edit between approval and
             * implementation cannot be applied silently.
             */
            $table->json('approved_payload')->nullable();
            $table->string('approved_payload_hash', 64)->nullable();

            // Implementation
            $table->string('implementing_unit_key', 64)->nullable();
            $table->foreignUuid('implementing_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            /*
             * These two carry explicit constraint names: the auto-generated
             * ones ("<table>_<column>_foreign") exceed MySQL's 64-character
             * identifier limit, which SQLite does not enforce.
             */
            $table->foreignId('implementation_assigned_to')->nullable()
                ->constrained('users', indexName: 'ocr_impl_assigned_to_foreign')->nullOnDelete();
            $table->foreignId('implementation_assigned_by')->nullable()
                ->constrained('users', indexName: 'ocr_impl_assigned_by_foreign')->nullOnDelete();
            $table->timestamp('implementation_assigned_at')->nullable();
            $table->timestamp('implementation_started_at')->nullable();
            $table->foreignId('implementation_claimed_by')->nullable()
                ->constrained('users', indexName: 'ocr_impl_claimed_by_foreign')->nullOnDelete();
            $table->timestamp('implemented_at')->nullable();
            $table->foreignId('implemented_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('implementation_note')->nullable();
            $table->json('implementation_result')->nullable();
            $table->json('blocked_reasons')->nullable();
            $table->timestamp('blocked_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'organization_id'], 'ocr_status_org_idx');
            $table->index(['requested_by', 'status'], 'ocr_requester_status_idx');
            $table->index(['request_type', 'status'], 'ocr_type_status_idx');
            $table->index(['implementing_unit_key', 'status'], 'ocr_impl_key_status_idx');
            $table->index(['implementation_assigned_to', 'status'], 'ocr_impl_assignee_status_idx');
            $table->index('requested_effective_date', 'ocr_effective_date_idx');
            $table->index('approved_at', 'ocr_approved_at_idx');
        });

        Schema::create('organizational_change_request_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')
                ->constrained('organizational_change_requests')
                ->cascadeOnDelete();

            $table->string('entity_type', 32);
            // Deliberately NOT a foreign key: an "add" item has no target yet,
            // and a target may be soft-deleted after approval. Existence is
            // revalidated by the conflict detector at implementation time.
            $table->uuid('entity_id')->nullable();
            $table->string('action', 32);

            $table->json('before_data')->nullable();
            $table->json('proposed_data');
            $table->json('validation_snapshot')->nullable();

            // Set once implementation creates or updates the real record.
            $table->uuid('resulting_entity_id')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['request_id', 'sort_order'], 'ocr_item_request_sort_idx');
            $table->index(['entity_type', 'entity_id'], 'ocr_item_entity_idx');
        });

        Schema::create('organizational_change_request_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')
                ->constrained('organizational_change_requests')
                ->cascadeOnDelete();

            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->string('stage', 32)->default('organization_review');
            $table->string('action', 32);
            $table->text('comment')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->index(['request_id', 'reviewed_at'], 'ocr_review_request_idx');
        });

        Schema::create('organizational_change_request_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')
                ->constrained('organizational_change_requests')
                ->cascadeOnDelete();

            $table->string('document_type', 48);
            $table->string('reference_no', 64)->nullable();
            $table->date('document_date')->nullable();
            $table->string('original_name');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('file_type', 128)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['request_id', 'document_type'], 'ocr_attachment_request_idx');
        });

        Schema::create('organizational_change_request_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')
                ->constrained('organizational_change_requests')
                ->cascadeOnDelete();

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->text('comment')->nullable();
            $table->json('changed_fields')->nullable();
            $table->json('context')->nullable();
            $table->unsignedInteger('revision')->default(1);
            // Append-only: history rows are never updated or deleted.
            $table->timestamp('created_at')->nullable();

            $table->index(['request_id', 'created_at'], 'ocr_history_request_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizational_change_request_history');
        Schema::dropIfExists('organizational_change_request_attachments');
        Schema::dropIfExists('organizational_change_request_reviews');
        Schema::dropIfExists('organizational_change_request_items');
        Schema::dropIfExists('organizational_change_requests');
    }
};
