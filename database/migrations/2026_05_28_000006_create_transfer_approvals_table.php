<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('transfer_application_id')->constrained('transfer_applications')->cascadeOnDelete();

            // approval_type: release | receiving | final
            $table->string('approval_type', 20);

            // status: pending | approved | rejected
            $table->string('status', 20)->default('pending');

            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            // One approval record per type per application
            $table->unique(['transfer_application_id', 'approval_type']);
            $table->index(['transfer_application_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_approvals');
    }
};
