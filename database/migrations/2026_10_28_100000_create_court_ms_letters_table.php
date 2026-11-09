<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * court-ms Flow 5 (ADR 0013): letters the administrative tribunal approves for an
 * institution registered here. `court-ms:poll` reads them with their PDFs; the
 * institution's tribunal officers read them under Tribunal cases > Court letters.
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('court_ms_letters')) {
            return;
        }

        Schema::create('court_ms_letters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('court_letter_id')->unique();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('case_number', 80)->nullable()->index();
            $table->string('case_title', 255)->nullable();
            $table->string('subject', 500)->nullable();
            $table->string('reference_number', 120)->nullable();
            $table->string('recipient', 500)->nullable();
            $table->string('approved_by', 255)->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->string('pdf_disk', 40);
            $table->string('pdf_path', 255);
            $table->char('pdf_sha256', 64);
            $table->dateTime('received_at');
            $table->dateTime('opened_at')->nullable();
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('court_ms_letters');
    }
};
