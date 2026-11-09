<?php

declare(strict_types=1);

namespace App\Console\Commands\Integration;

use App\Services\Integration\Events\OutboxPublisher;
use Illuminate\Console\Command;

/**
 * Moves committed outbox rows into the change feed (and webhook deliveries
 * when enabled). Scheduled every ten seconds; runs directly, without the
 * queue, so a Redis outage delays nothing here.
 */
class PublishIntegrationEvents extends Command
{
    protected $signature = 'integrations:publish {--max-seconds=50 : Stop starting new batches after this many seconds}';

    protected $description = 'Publish pending integration events to the change feed and webhook deliveries';

    public function handle(OutboxPublisher $publisher): int
    {
        $deadline = microtime(true) + max(1, (int) $this->option('max-seconds'));
        $totals = ['published' => 0, 'rejected' => 0, 'deliveries' => 0];

        do {
            $batch = $publisher->publish();
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $batch[$key];
            }
        } while (($batch['published'] + $batch['rejected']) > 0 && microtime(true) < $deadline);

        $this->line(sprintf('published=%d rejected=%d deliveries=%d', $totals['published'], $totals['rejected'], $totals['deliveries']));

        return self::SUCCESS;
    }
}
