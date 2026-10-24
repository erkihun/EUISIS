<?php

declare(strict_types=1);

namespace App\Console\Commands\Retention;

use App\Support\Retention\RetentionMatrix;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only: the retention matrix with each class's decision state, the
 * approved action, period and approval reference, and the live row count,
 * for the HR, Legal, Records Management, Audit and Data Protection review.
 */
class RetentionReport extends Command
{
    protected $signature = 'data:retention-report';

    protected $description = 'Read-only: show the retention decision and row counts for every record class';

    public function handle(RetentionMatrix $matrix): int
    {
        $rows = [];
        foreach ($matrix->classes() as $key => $class) {
            $count = collect($class['tables'])->filter(fn (string $table): bool => Schema::hasTable($table))->sum(fn (string $table): int => DB::table($table)->count());
            $rows[] = [$key, $class['label'], $class['state'], $class['action'], $class['days'] ?? '—', $class['approval'] ?? '—', $count, $class['automated'] ? 'yes' : 'no'];
        }

        $this->table(['Class', 'Records', 'State', 'Action', 'Period (days)', 'Approval', 'Rows', 'Automated'], $rows);
        $pending = collect($rows)->where(2, RetentionMatrix::NEEDS_POLICY_DECISION)->count();
        $this->line($pending > 0
            ? $pending.' class(es) await the retention decision; their records are kept, never deleted.'
            : 'Every record class has an approved retention decision.');

        return self::SUCCESS;
    }
}
