<?php

declare(strict_types=1);

namespace App\Console\Commands\Integration;

use App\Jobs\Integration\DeliverSubscriptionWebhooks;
use App\Services\Integration\Webhooks\DeliveryDispatcher;
use Illuminate\Console\Command;

/**
 * Sends due webhook deliveries.
 *
 *   --queue   (scheduled) one DeliverSubscriptionWebhooks job per subscription
 *             with due deliveries, on the integrations queue
 *   default   deliver inline (operations, recovery when the queue is down)
 */
class DeliverWebhooks extends Command
{
    protected $signature = 'integrations:deliver {--queue : Dispatch one queued job per subscription} {--subscription= : Only this subscription}';

    protected $description = 'Deliver due webhook events to partner subscriptions';

    public function handle(DeliveryDispatcher $dispatcher): int
    {
        if (! config('integration.webhooks.enabled')) {
            $this->line('webhooks disabled');

            return self::SUCCESS;
        }

        if ($this->option('queue')) {
            $ids = $dispatcher->subscriptionsWithDueDeliveries();
            foreach ($ids as $id) {
                DeliverSubscriptionWebhooks::dispatch($id);
            }
            $this->line('queued='.count($ids));

            return self::SUCCESS;
        }

        $counts = $dispatcher->run($this->option('subscription') ?: null);
        $this->line(collect($counts)->map(fn (int $n, string $status): string => $status.'='.$n)->implode(' ') ?: 'nothing due');

        return self::SUCCESS;
    }
}
