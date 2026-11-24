<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\Reporting\Infrastructure\Persistence\OperationalHealth;

/**
 * php artisan institutional-reporting:health
 *
 * Scheduler freshness, events not yet relayed, failed files and failed
 * notices. Exit code 1 when something needs a person, so a monitor can alert.
 */
final class ReportingHealthCommand extends Command
{
    protected $signature = 'institutional-reporting:health {--json : print the figures as JSON}';

    protected $description = 'Shows the operational health of the Reporting module (scheduler, outbox, generated files, notices)';

    public function handle(OperationalHealth $health): int
    {
        $report = $health->report();
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT));
        } else {
            $this->line('Maintenance switch: '.($report['paused'] ? 'ON (writes and scheduled work paused)' : 'off'));
            foreach ($report['tasks'] as $task) {
                $this->line(sprintf('%-10s last run %s  status %s%s', $task['task'], $task['last_run_at'] ?? 'never', $task['status'] ?? '-', $task['stale'] ? '  STALE' : ''));
            }
            $this->line(sprintf('Outbox: %d pending, %d failed, oldest pending %s', $report['outbox']['pending'], $report['outbox']['failed'], $report['outbox']['oldest_pending_at'] ?? '-'));
            $this->line(sprintf('Generated files: %d queued, %d processing, %d failed', $report['artifacts']['queued'], $report['artifacts']['processing'], $report['artifacts']['failed']));
            $this->line(sprintf('Notices: %d failed', $report['notifications']['failed']));
        }

        return $report['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}
