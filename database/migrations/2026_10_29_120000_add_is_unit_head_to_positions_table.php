<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the position that leads its organization unit, for approvals, letter
 * signatures and the organogram. One head per unit is enforced by the position
 * actions (EnsureSingleUnitHead), not by an index: MySQL has no partial index.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('positions', 'is_unit_head')) {
            Schema::table('positions', function (Blueprint $table): void {
                $table->boolean('is_unit_head')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('positions', 'is_unit_head')) {
            Schema::table('positions', function (Blueprint $table): void {
                $table->dropColumn('is_unit_head');
            });
        }
    }
};
