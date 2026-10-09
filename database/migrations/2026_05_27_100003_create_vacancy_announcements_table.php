<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancy_announcements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('announcement_number')->unique();
            $table->string('title_en');
            $table->string('title_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->foreignUuid('position_id')->constrained('positions');
            $table->foreignUuid('position_establishment_id')->constrained('position_establishments');
            $table->unsignedInteger('vacancy_slots');
            $table->timestamp('application_opens_at')->nullable();
            $table->timestamp('application_closes_at')->nullable();
            $table->string('status')->default('draft')->index();
            $table->json('eligibility_rules')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['position_id', 'status']);
            $table->index(['application_opens_at', 'application_closes_at'], 'va_app_window_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancy_announcements');
    }
};
