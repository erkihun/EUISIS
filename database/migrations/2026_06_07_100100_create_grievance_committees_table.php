<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grievance_committees', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->string('committee_type'); // grievance, disciplinary, tribunal
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->string('status')->default('active'); // active, inactive
            $table->timestamps();

            $table->unique(['organization_id', 'organization_unit_id', 'committee_type'], 'unique_committee_per_org_unit_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grievance_committees');
    }
};
