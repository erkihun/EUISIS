<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extend id_cards with new columns
        Schema::table('id_cards', function (Blueprint $table): void {
            $table->string('print_batch_item_id')->nullable()->after('card_request_id');
            $table->unsignedSmallInteger('token_version')->default(1)->after('token_hash');
            $table->timestamp('activated_at')->nullable()->after('issued_at');
            $table->timestamp('revoked_at')->nullable()->after('expires_at');
            $table->text('revoke_reason')->nullable()->after('revoked_at');
            $table->text('notes')->nullable()->after('revoke_reason');
        });

        // Extend card_requests with new columns
        Schema::table('card_requests', function (Blueprint $table): void {
            $table->string('request_type')->nullable()->after('status');
            $table->uuid('previous_card_id')->nullable()->after('request_type');
            $table->foreignId('rejected_by')->nullable()->constrained('users')->after('approved_by');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->after('rejected_by');
            $table->text('cancellation_reason')->nullable()->after('rejection_reason');
            $table->text('notes')->nullable()->after('cancellation_reason');
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
            $table->timestamp('cancelled_at')->nullable()->after('rejected_at');
        });

        // Extend card_print_batches with new columns
        Schema::table('card_print_batches', function (Blueprint $table): void {
            $table->foreignId('printed_by')->nullable()->constrained('users')->after('printed_at');
            $table->text('printer_notes')->nullable()->after('printed_by');
            $table->unsignedInteger('total_cards')->default(0)->after('printer_notes');
            $table->unsignedInteger('printed_count')->default(0)->after('total_cards');
            $table->unsignedInteger('spoiled_count')->default(0)->after('printed_count');
        });

        // Extend card_print_batch_items with new columns
        Schema::table('card_print_batch_items', function (Blueprint $table): void {
            $table->uuid('card_request_id')->nullable()->after('id_card_id');
            $table->string('status')->default('pending')->after('card_request_id');
            $table->boolean('spoiled')->default(false)->after('status');
            $table->text('reprint_reason')->nullable()->after('spoiled');
        });

        // Extend card_issuances with new columns
        Schema::table('card_issuances', function (Blueprint $table): void {
            $table->string('issued_to')->nullable()->after('id_card_id');
            $table->string('received_by')->nullable()->after('issued_by');
        });

        // Extend card_replacements with new columns
        Schema::table('card_replacements', function (Blueprint $table): void {
            $table->foreignId('replaced_by')->nullable()->constrained('users')->after('reason');
            $table->timestamp('replaced_at')->nullable()->after('replaced_by');
        });
    }

    public function down(): void
    {
        Schema::table('card_replacements', function (Blueprint $table): void {
            $table->dropForeign(['replaced_by']);
            $table->dropColumn(['replaced_by', 'replaced_at']);
        });

        Schema::table('card_issuances', function (Blueprint $table): void {
            $table->dropColumn(['issued_to', 'received_by']);
        });

        Schema::table('card_print_batch_items', function (Blueprint $table): void {
            $table->dropColumn(['card_request_id', 'status', 'spoiled', 'reprint_reason']);
        });

        Schema::table('card_print_batches', function (Blueprint $table): void {
            $table->dropForeign(['printed_by']);
            $table->dropColumn(['printed_by', 'printer_notes', 'total_cards', 'printed_count', 'spoiled_count']);
        });

        Schema::table('card_requests', function (Blueprint $table): void {
            $table->dropForeign(['rejected_by']);
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn(['request_type', 'previous_card_id', 'rejected_by', 'cancelled_by', 'cancellation_reason', 'notes', 'rejected_at', 'cancelled_at']);
        });

        Schema::table('id_cards', function (Blueprint $table): void {
            $table->dropColumn(['print_batch_item_id', 'token_version', 'activated_at', 'revoked_at', 'revoke_reason', 'notes']);
        });
    }
};
