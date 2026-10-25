<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\WebhookDeliverer;
use Illuminate\Console\Command;

/** Sends the webhook deliveries that are due (scheduled every minute). */
class DeliverWebhooks extends Command
{
    protected $signature = 'core:deliver-webhooks {--limit=100 : Most deliveries to send in one run}';

    protected $description = 'Send due webhook deliveries of Core events';

    public function handle(WebhookDeliverer $deliverer): int
    {
        $this->info($deliverer->deliverDue(max(1, (int) $this->option('limit'))).' delivery attempt(s) made.');

        return self::SUCCESS;
    }
}
