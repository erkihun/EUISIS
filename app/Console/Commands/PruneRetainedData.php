<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Retention\RetentionProcessor;
use App\Support\Retention\RetentionMatrix;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Daily retention run (ADR 0004). Record classes are archived or purged only
 * when their retention is approved (RetentionMatrix AUTHORIZED); until then
 * they are kept and listed as NEEDS_POLICY_DECISION_RETENTION. Operational
 * housekeeping (notifications, daily-activity reminders) keeps its automatic
 * windows. Works in key-ordered batches so a backlog never locks a table.
 */
class PruneRetainedData extends Command
{
    protected $signature = 'data:prune-retention {--dry-run : Count what would be removed without removing}';

    protected $description = 'Apply approved retention to audit, verification and API logs; prune notifications and reminders';

    /** Record classes the application can archive or purge. */
    private const LOG_CLASSES = ['audit_logs', 'verification_logs', 'api_logs'];

    public function handle(RetentionProcessor $processor, RetentionMatrix $matrix): int
    {
        $dryRun = (bool) $this->option('dry-run');

        foreach (self::LOG_CLASSES as $class) {
            $results = $processor->process($class, $dryRun);
            if ($results === null) {
                $this->line(sprintf('%s: kept (%s)', $class, $matrix->for($class)['state']));

                continue;
            }
            foreach ($results as $result) {
                $this->line(sprintf('%s: %s.%s, %d %s%s', $class, $result['table'], $result['column'], $result['rows'],
                    $dryRun ? 'would be removed' : ($matrix->for($class)['action'] === 'archive' ? 'archived' : 'purged'),
                    $result['files'] > 0 ? ' ('.$result['files'].' archive file(s))' : ''));
            }
        }

        $readDays = max(1, (int) config('retention.notifications_read_days', 90));
        $housekeeping = [
            ['notifications', 'id', 'read_at', $readDays],
            ['notifications', 'id', 'created_at', max($readDays, (int) config('retention.notifications_days', 365))],
            ['daily_activity_reminders', 'id', 'activity_date', max(1, (int) config('retention.daily_activity_reminders_days', 90))],
        ];
        foreach ($housekeeping as [$table, $key, $column, $days]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $cutoff = now()->subDays($days);
            $count = $dryRun ? $processor->expired($table, $column, $cutoff)->count() : $processor->deleteExpired($table, $key, $column, $cutoff);
            $this->line(sprintf('%s.%s older than %d days: %d %s', $table, $column, $days, $count, $dryRun ? 'would be deleted' : 'deleted'));
        }

        return self::SUCCESS;
    }
}
