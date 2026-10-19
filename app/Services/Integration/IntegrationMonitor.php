<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Models\Integration\IntegrationEvent;
use App\Models\Integration\WebhookDelivery;
use App\Models\Integration\WebhookDeliveryAttempt;
use App\Models\Integration\WebhookSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Event-integration KPIs, every number counted from real rows
 * (integration_events, webhook_deliveries, webhook_delivery_attempts).
 * A figure that cannot be measured is null — shown as UNKNOWN, never as
 * healthy.
 */
final class IntegrationMonitor
{
    /** @return array<string, int|float|string|null> */
    public function snapshot(): array
    {
        if (! Schema::hasTable('integration_events')) {
            return array_fill_keys(['pending_events', 'oldest_pending_seconds', 'rejected_events', 'published_24h', 'feed_position', 'deliveries_today', 'retrying_deliveries', 'dead_deliveries', 'active_subscriptions', 'avg_latency_ms_24h', 'events_enabled', 'change_feed_enabled', 'webhooks_enabled'], null);
        }

        $now = Carbon::now();
        $oldest = IntegrationEvent::query()->where('status', IntegrationEvent::STATUS_PENDING)->min('created_at');

        return [
            'pending_events' => IntegrationEvent::query()->where('status', IntegrationEvent::STATUS_PENDING)->count(),
            'oldest_pending_seconds' => $oldest === null ? 0 : max(0, $now->getTimestamp() - Carbon::parse($oldest)->getTimestamp()),
            'rejected_events' => IntegrationEvent::query()->where('status', IntegrationEvent::STATUS_REJECTED)->count(),
            'published_24h' => IntegrationEvent::query()->where('published_at', '>=', $now->copy()->subDay())->count(),
            'feed_position' => (int) DB::table('integration_feed_state')->where('id', 1)->value('last_position'),
            'deliveries_today' => WebhookDelivery::query()->where('status', WebhookDelivery::STATUS_SUCCEEDED)->where('delivered_at', '>=', $now->copy()->startOfDay())->count(),
            'retrying_deliveries' => WebhookDelivery::query()->where('status', WebhookDelivery::STATUS_RETRYING)->count(),
            'dead_deliveries' => WebhookDelivery::query()->where('status', WebhookDelivery::STATUS_DEAD)->count(),
            'active_subscriptions' => WebhookSubscription::query()->where('status', WebhookSubscription::STATUS_ACTIVE)->count(),
            'avg_latency_ms_24h' => ($avg = WebhookDeliveryAttempt::query()->where('started_at', '>=', $now->copy()->subDay())->avg('latency_ms')) === null ? null : (int) round((float) $avg),
            'events_enabled' => config('integration.events.enabled') ? 'on' : 'off',
            'change_feed_enabled' => config('integration.change_feed.enabled') ? 'on' : 'off',
            'webhooks_enabled' => config('integration.webhooks.enabled') ? 'on' : 'off',
        ];
    }
}
