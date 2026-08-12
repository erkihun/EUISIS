<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grievance_decision_letters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grievance_id')->unique()->constrained('grievances')->cascadeOnDelete();
            $table->foreignUuid('response_id')->constrained('grievance_responses')->cascadeOnDelete();
            $table->string('letter_reference')->unique();
            $table->string('file_path')->nullable();
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->timestamp('downloaded_at')->nullable();
            $table->foreignId('downloaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grievance_decision_letters');
    }
};
