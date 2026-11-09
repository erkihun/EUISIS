<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reviewer can send a strategic goal back to its drafter; the reason is
 * kept on the goal until it is submitted again (docs/epms-strategic-planning.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('strategic_goals', 'return_reason')) {
            return;
        }

        Schema::table('strategic_goals', function (Blueprint $table): void {
            $table->text('return_reason')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('strategic_goals', 'return_reason')) {
            return;
        }

        Schema::table('strategic_goals', function (Blueprint $table): void {
            $table->dropColumn('return_reason');
        });
    }
};
