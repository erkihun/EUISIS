<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * How long an investigator asked to read for. The approver may grant less;
 * what was granted is starts_at → expires_at. Additive and nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_access_requests', function (Blueprint $table): void {
            $table->unsignedSmallInteger('requested_hours')->nullable()->after('case_reference');
        });
    }

    public function down(): void
    {
        Schema::table('communication_access_requests', function (Blueprint $table): void {
            $table->dropColumn('requested_hours');
        });
    }
};
