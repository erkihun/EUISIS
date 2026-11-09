<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0032, step 4: the old single table, kept as
 * `employee_service_feedback_legacy` by the split, is dropped once every one
 * of its rows is in its type's table. If any is missing, nothing is dropped
 * and the migration stops, so no submission can be lost.
 *
 * migration-safety: reviewed. The drop runs only after the check above; the
 * rows live on in public_service_feedback, public_tip_offs and
 * public_complaints (ADR 0032). Take the usual backup before deploying.
 */
return new class extends Migration
{
    private const LEGACY = 'employee_service_feedback_legacy';

    private const TABLES = ['public_service_feedback', 'public_tip_offs', 'public_complaints'];

    public function up(): void
    {
        if (! Schema::hasTable(self::LEGACY)) {
            return;
        }

        $missing = 0;
        DB::table(self::LEGACY)->select('id', 'type')->orderBy('id')->chunk(1000, function ($rows) use (&$missing): void {
            $ids = $rows->pluck('id')->all();
            $found = collect(self::TABLES)->flatMap(fn (string $table) => DB::table($table)->whereIn('id', $ids)->pluck('id'))->all();
            $missing += count(array_diff($ids, $found));
        });
        if ($missing > 0) {
            throw new RuntimeException("Public submissions: {$missing} rows of ".self::LEGACY.' are not in the type tables; the legacy table was kept.');
        }

        Schema::drop(self::LEGACY);
    }

    public function down(): void
    {
        // The legacy copy is not rebuilt: the type tables hold every row.
    }
};
