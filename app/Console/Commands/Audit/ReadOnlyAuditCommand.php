<?php

declare(strict_types=1);

namespace App\Console\Commands\Audit;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Base for the data audit commands.
 *
 * Every check is a read-only query that returns the offending rows' internal
 * identifiers (UUIDs and codes, never names, phone numbers or national IDs), so
 * the output can be pasted into a ticket. Nothing is repaired: fixing data is a
 * separate, approved change. Each check runs inside a transaction that is always
 * rolled back, so a check can never write even by mistake.
 *
 * Exit code 1 when any HIGH finding exists (or any finding with --strict), so a
 * deployment gate can run it.
 */
abstract class ReadOnlyAuditCommand extends Command
{
    /** Rows listed per finding; the count is always complete. */
    protected const SAMPLE = 20;

    /**
     * @return list<array{key: string, severity: 'HIGH'|'MEDIUM'|'LOW', label: string, why: string, rows: Closure(): Collection<int, mixed>}>
     */
    abstract protected function checks(): array;

    public function handle(): int
    {
        $findings = [];

        foreach ($this->checks() as $check) {
            // One rolled-back transaction per check: on PostgreSQL a failed
            // query aborts its transaction, which must not end the whole audit.
            DB::beginTransaction();
            try {
                $rows = collect(($check['rows'])());
            } catch (Throwable $e) {
                // A missing table (module not installed) is reported, not fatal.
                $this->components->twoColumnDetail($check['label'], '<fg=yellow>SKIPPED</>');
                $this->components->bulletList([class_basename($e).': '.str($e->getMessage())->limit(160)]);

                continue;
            } finally {
                DB::rollBack();
            }

            $count = $rows->count();
            $this->components->twoColumnDetail(
                $check['label'],
                $count === 0 ? '<fg=green>OK</>' : sprintf('<fg=%s>%s × %d</>', $check['severity'] === 'HIGH' ? 'red' : 'yellow', $check['severity'], $count),
            );
            if ($count > 0) {
                $sample = $rows->take(self::SAMPLE)->map(fn ($row) => is_scalar($row) ? (string) $row : json_encode($row))->all();
                $this->components->bulletList([$check['why'], ...$sample]);
                $findings[] = ['key' => $check['key'], 'severity' => $check['severity'], 'count' => $count];
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode(['command' => $this->getName(), 'findings' => $findings], JSON_PRETTY_PRINT));
        }

        $high = collect($findings)->where('severity', 'HIGH')->count();
        if ($findings === []) {
            $this->components->info('No findings.');

            return self::SUCCESS;
        }

        $this->components->warn(sprintf('%d finding(s), %d HIGH. Read-only: nothing was changed.', count($findings), $high));

        return ($high > 0 || $this->option('strict')) ? self::FAILURE : self::SUCCESS;
    }
}
