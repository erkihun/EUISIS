<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grievance_sla_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('origin_level'); // woreda, pool, organization, organization_unit
            $table->string('escalation_from_type'); // committee, grievance_team
            $table->string('escalation_to_type');   // committee, administrative_tribunal
            $table->unsignedTinyInteger('working_days_limit')->default(3);
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grievance_sla_rules');
    }
};
