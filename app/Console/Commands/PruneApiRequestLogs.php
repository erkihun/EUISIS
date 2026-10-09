<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiRequestLog;
use Illuminate\Console\Command;

/**
 * Trims api_request_logs to a retention window.
 *
 * The table grows with every integration request, so without pruning it
 * becomes the largest table in the system. Schedule this daily.
 */
class PruneApiRequestLogs extends Command
{
    protected $signature = 'api:prune-logs {--days=90 : Retention window in days}';

    protected $description = 'Delete API request logs older than the retention window';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        // Delete in chunks so a large backlog does not lock the table or
        // exhaust memory in one statement.
        $deleted = 0;

        do {
            $batch = ApiRequestLog::query()
                ->where('requested_at', '<', $cutoff)
                ->limit(1000)
                ->delete();

            $deleted += $batch;
        } while ($batch > 0);

        $this->info("Pruned {$deleted} API request log(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
