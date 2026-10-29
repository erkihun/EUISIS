<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * court-ms Flow 7 (ADR 0013 #14): cases filed at the administrative tribunal
 * against an institution registered here. Each institution names the officer who
 * follows its court cases (court_case_officers); `court-ms:poll` reads every new
 * case against an institution and gives it to that officer. court-ms stays the
 * system of record: a row here is the institution's register entry, read live
 * from court-ms when opened. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('court_case_officers')) {
            Schema::create('court_case_officers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('institution_court_cases')) {
            Schema::create('institution_court_cases', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('court_case_id')->unique();
                $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->string('case_number', 80)->index();
                $table->json('applicants')->nullable();
                $table->string('case_type', 255)->nullable();
                $table->date('filed_at')->nullable();
                $table->string('status', 30)->nullable();
                $table->string('review_status', 30)->nullable();
                $table->string('source', 40)->nullable();
                $table->foreignId('officer_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('assigned_at')->nullable();
                $table->dateTime('received_at');
                $table->timestamps();

                $table->index(['organization_id', 'received_at']);
                $table->index(['officer_user_id', 'received_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_court_cases');
        Schema::dropIfExists('court_case_officers');
    }
};
