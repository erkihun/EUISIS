<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Services a position delivers to the public.
 *
 * Deliberately unrelated to `service_types`. That table is the entitlements
 * catalog — Cafeteria, Health, Transport, Insurance: things an employee is
 * entitled to RECEIVE. These rows are the opposite direction: work an officer
 * PERFORMS for a client, such as "Employee Record Correction" or "Land Permit".
 * Sharing one table would conflate two unrelated domains, so they stay apart.
 *
 * Each row is owned by an organization and a position. `organization_id` is
 * stored directly rather than reached through the position because it is the
 * boundary for administrative scope and for every report, and those queries
 * should not need a join to answer "whose service is this?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_services', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('position_id')->constrained('positions')->cascadeOnDelete();

            // The reference a client sees on the form and a report quotes.
            $table->string('service_no', 40);

            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description')->nullable();

            // Retire a service without detaching the feedback already given.
            $table->boolean('is_active')->default(true);

            // Excludes advisory or informal services from performance scoring.
            $table->boolean('is_performance_evaluation_enabled')->default(true);

            $table->unsignedInteger('sort_order')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            /*
             * The Service No is unique WITHIN a position, not across the
             * organization: two counters may each run their own "HR-001", and
             * forbidding that would make numbering a city-wide negotiation.
             */
            $table->unique(['position_id', 'service_no'], 'position_services_no_unique');

            $table->index(['organization_id', 'is_active']);
            $table->index(['position_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_services');
    }
};
