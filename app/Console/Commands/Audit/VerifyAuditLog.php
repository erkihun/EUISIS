<?php

declare(strict_types=1);

namespace App\Console\Commands\Audit;

use App\Services\Audit\AuditLogSealer;
use Illuminate\Console\Command;

/**
 * Recomputes the audit-log seals and reports any sealed day whose rows were
 * changed, added or removed, or whose seal chain was broken. Read-only;
 * prints dates and counts, never audit content.
 */
class VerifyAuditLog extends Command
{
    protected $signature = 'audit:verify {--from= : First day (Y-m-d)} {--to= : Last day (Y-m-d)}';

    protected $description = 'Read-only: verify the audit-log seals (exit 1 when a sealed day was altered)';

    public function handle(AuditLogSealer $sealer): int
    {
        if (! $sealer->available()) {
            $this->error('The audit_log_seals table is missing: run php artisan migrate.');

            return self::FAILURE;
        }

        $results = collect($sealer->verify($this->option('from'), $this->option('to')));
        $bad = $results->reject(fn (array $result): bool => $result['status'] === 'ok');

        if ($results->isEmpty()) {
            $this->warn('No sealed days in this range: run php artisan audit:seal.');

            return self::SUCCESS;
        }

        if ($bad->isNotEmpty()) {
            $this->table(['Day', 'Rows when sealed', 'Status'], $bad->map(fn (array $result): array => [$result['day'], $result['rows'], strtoupper($result['status'])])->all());
            $this->error($bad->count().' of '.$results->count().' sealed day(s) do not verify. Treat as a security incident (docs/incident-response-plan.md).');

            return self::FAILURE;
        }

        $this->info('All '.$results->count().' sealed day(s) verify ('.$results->first()['day'].' to '.$results->last()['day'].', '.$results->sum('rows').' rows).');

        return self::SUCCESS;
    }
}
