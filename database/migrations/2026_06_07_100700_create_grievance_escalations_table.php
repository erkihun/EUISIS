<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grievance_escalations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->constrained('grievances')->cascadeOnDelete();
            $table->string('from_level'); // committee, grievance_team
            $table->string('to_level');   // committee, administrative_tribunal
            $table->string('reason')->default('sla_breach'); // sla_breach, manual
            $table->text('notes')->nullable();
            $table->foreignId('escalated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('escalated_at');
            $table->timestamps();

            $table->index('grievance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grievance_escalations');
    }
};
