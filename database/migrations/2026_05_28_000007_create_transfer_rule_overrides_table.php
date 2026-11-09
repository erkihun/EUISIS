<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_rule_overrides', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('transfer_application_id')->constrained('transfer_applications')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            // Which setting rule is being overridden (e.g. require_same_position)
            $table->string('rule_key', 60);
            $table->text('reason');

            // status: pending | approved | rejected
            $table->string('status', 20)->default('pending');
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            $table->index(['transfer_application_id', 'status']);
            $table->index(['transfer_application_id', 'rule_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_rule_overrides');
    }
};
