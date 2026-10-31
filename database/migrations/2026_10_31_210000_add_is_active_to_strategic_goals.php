<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * WLMS's "active strategic goal" flag (ADR 0019). It is a label on the goal
 * lists; it does not change weights, readiness or scoring.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strategic_goals', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('is_shared');
        });
    }

    public function down(): void
    {
        Schema::table('strategic_goals', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
