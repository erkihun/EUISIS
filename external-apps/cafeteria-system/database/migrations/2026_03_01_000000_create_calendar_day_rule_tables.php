<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Day rules that colour the scan calendar and decide whether a day is
 * subsidised.
 *
 * These are cafeteria-provider policy, not EUISIS employee data, so they live
 * here. Employee leave stays in EUISIS and reaches the calendar through the
 * API — never by reading the EUISIS database.
 *
 * Index names are given explicitly: MySQL caps identifiers at 64 characters and
 * the generated names for these column pairs would exceed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cafeteria_public_holidays', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Null provider = national holiday shared by every provider.
            $table->foreignUuid('provider_id')->nullable()
                ->constrained('cafeteria_providers')->cascadeOnDelete();
            $table->date('holiday_date');
            $table->string('name_en');
            $table->string('name_am')->nullable();
            // A recurring holiday repeats on the same day each year.
            $table->boolean('is_recurring')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['provider_id', 'holiday_date'], 'cph_provider_date_idx');
            $table->unique(['provider_id', 'holiday_date'], 'cph_provider_date_unique');
        });

        Schema::create('cafeteria_special_days', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->nullable()
                ->constrained('cafeteria_providers')->cascadeOnDelete();
            $table->date('special_date');
            $table->string('name_en');
            $table->string('name_am')->nullable();
            // open_day  = open on a day that would normally be closed
            // no_subsidy = open, but the subsidy does not apply
            $table->string('day_type', 24)->default('open_day');
            $table->boolean('is_open')->default(true);
            $table->boolean('is_subsidy_day')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['provider_id', 'special_date'], 'csd_provider_date_idx');
            $table->unique(['provider_id', 'special_date'], 'csd_provider_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cafeteria_special_days');
        Schema::dropIfExists('cafeteria_public_holidays');
    }
};
