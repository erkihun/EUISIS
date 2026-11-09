<?php

declare(strict_types=1);

namespace App\Jobs\Integration;

use App\Services\Integration\Webhooks\DeliveryDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Delivers one subscription's due webhooks on the integrations queue.
 *
 * One job per subscription (unique while queued), so a slow or failing
 * partner occupies at most one worker and never delays another partner's
 * deliveries or the application's own queues.
 */
class DeliverSubscriptionWebhooks implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $subscriptionId)
    {
        $this->onQueue((string) config('integration.webhooks.queue', 'integrations'));
    }

    public function uniqueId(): string
    {
        return $this->subscriptionId;
    }

    public function handle(DeliveryDispatcher $dispatcher): void
    {
        $dispatcher->run($this->subscriptionId);
    }
}
