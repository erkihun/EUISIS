<?php

declare(strict_types=1);

namespace App\Console\Commands\Integration;

use App\Models\Integration\IntegrationEvent;
use App\Models\Integration\WebhookDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Retention for the integration tables — only when a policy is configured.
 *
 * With integration.retention.* null (the default) nothing is deleted:
 * NEEDS_POLICY_DECISION_EVENT_RETENTION. When configured, it removes in
 * chunks only:
 *   - finished deliveries (succeeded / skipped / dead) older than the window;
 *   - PUBLISHED events older than the window that no delivery still
 *     references. Pending or rejected events are never purged.
 */
class PurgeIntegrationEvents extends Command
{
    protected $signature = 'integrations:purge {--dry-run : Count only}';

    protected $description = 'Apply the configured retention to integration events and deliveries';

    public function handle(): int
    {
        $eventDays = config('integration.retention.events_days');
        $deliveryDays = config('integration.retention.deliveries_days');

        if ($eventDays === null && $deliveryDays === null) {
            $this->warn('No retention configured (NEEDS_POLICY_DECISION_EVENT_RETENTION). Nothing purged.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $deliveries = $events = 0;

        if ($deliveryDays !== null) {
            $cutoff = Carbon::now()->subDays(max(1, (int) $deliveryDays));
            $query = WebhookDelivery::query()
                ->whereIn('status', [WebhookDelivery::STATUS_SUCCEEDED, WebhookDelivery::STATUS_SKIPPED, WebhookDelivery::STATUS_DEAD])
                ->where('updated_at', '<', $cutoff);
            $deliveries = $dry ? $query->count() : $this->chunkDelete($query);
        }

        if ($eventDays !== null) {
            $cutoff = Carbon::now()->subDays(max(1, (int) $eventDays));
            $query = IntegrationEvent::query()
                ->where('status', IntegrationEvent::STATUS_PUBLISHED)
                ->where('published_at', '<', $cutoff)
                ->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))->from('webhook_deliveries')->whereColumn('webhook_deliveries.integration_event_id', 'integration_events.id'));
            $events = $dry ? $query->count() : $this->chunkDelete($query);
        }

        $this->line(($dry ? 'would purge' : 'purged').": deliveries={$deliveries} events={$events}");

        return self::SUCCESS;
    }

    private function chunkDelete($query): int
    {
        $total = 0;
        do {
            $ids = (clone $query)->limit(1000)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $total += $query->getModel()->newQuery()->whereKey($ids->all())->delete();
        } while (true);

        return $total;
    }
}
