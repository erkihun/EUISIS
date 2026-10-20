<?php

declare(strict_types=1);

namespace App\Services\Integration\Webhooks;

use App\Models\Integration\WebhookDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Finds due deliveries, claims them, and hands them to the deliverer.
 *
 * Claiming is an optimistic conditional update (status + lease), so any
 * number of workers can run this: a delivery is sent by the worker whose
 * update matched. A worker that dies mid-delivery leaves an expired lease;
 * the delivery becomes due again (at-least-once).
 *
 * Fairness: at most per_subscription_batch deliveries per subscription per
 * run, so one slow or broken partner cannot monopolise a worker, and
 * deliverSubscription() lets each partner run on its own queued job.
 *
 * Ordering: within a subscription, a delivery waits while an EARLIER delivery
 * for the same aggregate (same employee, card, …) is still open. A dead
 * earlier delivery no longer blocks — the gap is visible through the
 * aggregate `sequence` and can be filled by replay or the change feed.
 */
final class DeliveryDispatcher
{
    public function __construct(private readonly WebhookDeliverer $deliverer) {}

    /** @return array<string, int> counts by resulting status */
    public function run(?string $subscriptionId = null, ?int $limit = null): array
    {
        if (! config('integration.webhooks.enabled')) {
            return [];
        }

        $limit ??= max(1, (int) config('integration.webhooks.batch', 200));
        $perSubscription = max(1, (int) config('integration.webhooks.per_subscription_batch', 50));
        $counts = [];

        foreach ($this->due($subscriptionId, $limit * 2)->groupBy('webhook_subscription_id') as $group) {
            foreach ($group->take($perSubscription) as $delivery) {
                if ($limit <= 0) {
                    break 2;
                }
                if ($this->blockedByEarlier($delivery) || ! $this->claim($delivery)) {
                    continue;
                }
                $status = $this->deliverer->deliver($delivery->refresh());
                $counts[$status] = ($counts[$status] ?? 0) + 1;
                $limit--;
            }
        }

        return $counts;
    }

    /** Subscriptions with something due now — one queued job each. @return list<string> */
    public function subscriptionsWithDueDeliveries(): array
    {
        return $this->dueQuery()->distinct()->pluck('webhook_subscription_id')->map(fn ($id): string => (string) $id)->all();
    }

    /** @return Collection<int, WebhookDelivery> */
    private function due(?string $subscriptionId, int $limit): Collection
    {
        return $this->dueQuery()
            ->when($subscriptionId !== null, fn ($query) => $query->where('webhook_subscription_id', $subscriptionId))
            ->orderBy('feed_position')
            ->orderBy('generation')
            ->limit($limit)
            ->get();
    }

    private function dueQuery()
    {
        $now = Carbon::now();

        return WebhookDelivery::query()->where(fn ($query) => $query
            ->where(fn ($q) => $q->whereIn('status', [WebhookDelivery::STATUS_PENDING, WebhookDelivery::STATUS_RETRYING])->where('next_attempt_at', '<=', $now))
            ->orWhere(fn ($q) => $q->where('status', WebhookDelivery::STATUS_IN_FLIGHT)->where('locked_until', '<', $now)));
    }

    private function blockedByEarlier(WebhookDelivery $delivery): bool
    {
        return WebhookDelivery::query()
            ->where('webhook_subscription_id', $delivery->webhook_subscription_id)
            ->where('aggregate_key', $delivery->aggregate_key)
            ->where('feed_position', '<', $delivery->feed_position)
            ->whereIn('status', WebhookDelivery::OPEN)
            ->exists();
    }

    private function claim(WebhookDelivery $delivery): bool
    {
        $now = Carbon::now();
        $lease = $now->copy()->addSeconds(max(10, (int) config('integration.webhooks.lease_seconds', 120)));

        return WebhookDelivery::query()
            ->whereKey($delivery->getKey())
            ->where('status', $delivery->status)
            ->where(fn ($query) => $query->whereNull('locked_until')->orWhere('locked_until', '<', $now))
            ->update(['status' => WebhookDelivery::STATUS_IN_FLIGHT, 'locked_until' => $lease, 'updated_at' => $now]) === 1;
    }
}
