<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The leave settings page controls the module end to end (owner,
 * 2026-11-27): how a request is decided, how the leave schedule works, and
 * every switch of a leave type. The module's own switches live in
 * leave_settings; "may start before the day it is asked for" becomes a
 * switch of the leave type instead of a rule written in code.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_settings')) {
            Schema::create('leave_settings', function (Blueprint $table): void {
                $table->string('key', 80)->primary();
                $table->json('value')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('leave_types', 'allows_past_start')) {
            Schema::table('leave_types', function (Blueprint $table): void {
                $table->boolean('allows_past_start')->default(false)->after('allows_half_day');
            });
            // What cannot be asked for beforehand is reported after it began.
            DB::table('leave_types')->whereIn('category', ['sick', 'maternity', 'paternity'])->update(['allows_past_start' => true]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_settings');
        if (Schema::hasColumn('leave_types', 'allows_past_start')) {
            Schema::table('leave_types', fn (Blueprint $table) => $table->dropColumn('allows_past_start'));
        }
    }
};
