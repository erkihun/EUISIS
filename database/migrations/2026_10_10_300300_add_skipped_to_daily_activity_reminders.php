<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reminder row now also records "evaluated, no reminder due" (leave,
 * non-required day), so the every-15-minutes job evaluates each employee at
 * most once per date and type instead of re-walking the workforce on every
 * run (production risk register SCL-7). Additive with a constant default:
 * metadata-only on PostgreSQL 11+, no table rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('daily_activity_reminders', 'skipped')) {
            Schema::table('daily_activity_reminders', function (Blueprint $table): void {
                $table->boolean('skipped')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('daily_activity_reminders', 'skipped')) {
            Schema::table('daily_activity_reminders', function (Blueprint $table): void {
                $table->dropColumn('skipped');
            });
        }
    }
};
