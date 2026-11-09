<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Retention\RetentionProcessor;
use App\Support\Retention\RetentionMatrix;
use Illuminate\Console\Command;

/**
 * API request logs are a record class (ADR 0004): they are archived or purged
 * only under an approved retention decision (RETENTION_API_LOGS_*), the same
 * way data:prune-retention handles them. Kept on its own schedule slot.
 */
class PruneApiRequestLogs extends Command
{
    protected $signature = 'api:prune-logs';

    protected $description = 'Apply the approved retention to API request logs (kept until approved)';

    public function handle(RetentionProcessor $processor, RetentionMatrix $matrix): int
    {
        $results = $processor->process('api_logs');

        if ($results === null) {
            $this->info('API request logs kept: '.$matrix->for('api_logs')['state'].' (docs/governance/retention-matrix.md).');

            return self::SUCCESS;
        }

        $this->info('API request logs: '.array_sum(array_column($results, 'rows')).' row(s) '.($matrix->for('api_logs')['action'] === 'archive' ? 'archived' : 'purged').' under approval '.$matrix->for('api_logs')['approval'].'.');

        return self::SUCCESS;
    }
}
