<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Regulation 201/2018 Art. 56 (transitional): proceedings begun before the
 * regulation took effect on Tir 8, 2018 E.C. (16 January 2026, Art. 59)
 * continue under the repealed Regulation 26/2002. Each grievance and
 * disciplinary case records which regulation governs it; those begun earlier
 * are marked 26/2002 and get no Regulation 201/2018 deadlines.
 *
 * Additive. The backfill only labels existing rows by their start date.
 */
return new class extends Migration
{
    private const EFFECTIVE_FROM = '2026-01-16 00:00:00';

    public function up(): void
    {
        foreach (['grievances', 'disciplinary_cases'] as $table) {
            if (! Schema::hasColumn($table, 'governing_regulation')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->string('governing_regulation', 20)->default('201/2018');
                });
            }
        }

        // A grievance began when it was submitted (or created, for an unsubmitted draft).
        DB::table('grievances')
            ->where(fn ($q) => $q->where('submitted_at', '<', self::EFFECTIVE_FROM)
                ->orWhere(fn ($d) => $d->whereNull('submitted_at')->where('created_at', '<', self::EFFECTIVE_FROM)))
            ->update(['governing_regulation' => '26/2002']);

        DB::table('disciplinary_cases')
            ->where(fn ($q) => $q->where('charged_at', '<', self::EFFECTIVE_FROM)
                ->orWhere(fn ($d) => $d->whereNull('charged_at')->where('created_at', '<', self::EFFECTIVE_FROM)))
            ->update(['governing_regulation' => '26/2002']);
    }

    public function down(): void
    {
        foreach (['grievances', 'disciplinary_cases'] as $table) {
            if (Schema::hasColumn($table, 'governing_regulation')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->dropColumn('governing_regulation');
                });
            }
        }
    }
};
