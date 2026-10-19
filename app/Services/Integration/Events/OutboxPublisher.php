<?php

declare(strict_types=1);

namespace App\Services\Integration\Events;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Integration\IntegrationEvent;
use App\Models\Integration\WebhookDelivery;
use App\Models\Integration\WebhookSubscription;
use App\Services\Integration\Webhooks\SubscriptionVisibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moves committed outbox rows into the change feed and fans them out to
 * webhook subscriptions.
 *
 * Ordering. Rows are numbered (`feed_position`) by whichever publisher holds
 * the lock on integration_feed_state, in id order, and only rows already
 * committed are visible to it. A row whose transaction commits late simply
 * gets a later position — so the feed is gap-free and never re-orders what a
 * consumer has already read, which an auto-increment id under concurrent
 * commits would not guarantee.
 *
 * Concurrency. Many workers may run this; the feed-state row lock makes them
 * take turns, so each row is positioned exactly once. A crash before commit
 * leaves the rows pending for the next run (nothing half-published).
 *
 * Validation. A payload that breaks its EventCatalog contract is marked
 * `rejected` with the reason and is never delivered — visible to admins,
 * not silently sent.
 */
final class OutboxPublisher
{
    public function __construct(private readonly SubscriptionVisibility $visibility) {}

    /** @return array{published: int, rejected: int, deliveries: int} */
    public function publish(?int $limit = null): array
    {
        $limit ??= max(1, (int) config('integration.events.publish_batch', 500));
        $maxBytes = (int) config('integration.events.max_payload_bytes', 16384);

        return DB::transaction(function () use ($limit, $maxBytes): array {
            $state = DB::table('integration_feed_state')->where('id', 1)->lockForUpdate()->first();
            $position = (int) ($state->last_position ?? 0);

            $rows = IntegrationEvent::query()
                ->where('status', IntegrationEvent::STATUS_PENDING)
                ->where('available_at', '<=', Carbon::now())
                ->orderBy('id')
                ->limit($limit)
                ->get();

            $published = $rejected = $deliveries = 0;
            $subscriptions = $rows->isEmpty() || ! config('integration.webhooks.enabled') ? collect() : WebhookSubscription::query()
                ->where('status', WebhookSubscription::STATUS_ACTIVE)->with('application')->get();

            foreach ($rows as $event) {
                $this->resolveOrganization($event);

                $problems = EventCatalog::validate($event->event_type, $event->payload ?? []);
                if (strlen((string) json_encode($event->payload)) > $maxBytes) {
                    $problems[] = 'payload_too_large';
                }

                if ($problems !== []) {
                    $event->forceFill([
                        'status' => IntegrationEvent::STATUS_REJECTED,
                        'attempt_count' => $event->attempt_count + 1,
                        'error' => substr(implode(',', $problems), 0, 255),
                    ])->save();
                    $rejected++;

                    continue;
                }

                $event->forceFill([
                    'status' => IntegrationEvent::STATUS_PUBLISHED,
                    'feed_position' => ++$position,
                    'published_at' => Carbon::now(),
                    'attempt_count' => $event->attempt_count + 1,
                ]);
                // One narrow UPDATE per row; no model events on the outbox.
                DB::table('integration_events')->where('id', $event->getKey())->update([
                    'status' => $event->status,
                    'feed_position' => $event->feed_position,
                    'published_at' => $event->published_at,
                    'attempt_count' => $event->attempt_count,
                    'organization_id' => $event->organization_id,
                ]);
                $published++;

                foreach ($subscriptions as $subscription) {
                    if ($this->visibility->shouldDeliver($subscription, $event)) {
                        $deliveries += $this->createDelivery($subscription, $event) ? 1 : 0;
                    }
                }
            }

            if ($published > 0) {
                DB::table('integration_feed_state')->where('id', 1)->update(['last_position' => $position, 'updated_at' => Carbon::now()]);
            }

            return compact('published', 'rejected', 'deliveries');
        });
    }

    private function createDelivery(WebhookSubscription $subscription, IntegrationEvent $event): bool
    {
        // The (subscription, event, generation) identity is unique, so a
        // retried publish can never create a second delivery for the same event.
        $delivery = WebhookDelivery::query()->firstOrCreate(
            ['webhook_subscription_id' => $subscription->getKey(), 'event_id' => $event->event_id, 'generation' => 0],
            [
                'integration_event_id' => $event->getKey(),
                'feed_position' => $event->feed_position,
                'aggregate_key' => $event->aggregateKey(),
                'status' => WebhookDelivery::STATUS_PENDING,
                'next_attempt_at' => Carbon::now(),
            ],
        );

        return $delivery->wasRecentlyCreated;
    }

    /**
     * Employee-side events recorded before the employee had a placement (a
     * brand-new employee) take the organization the employee has when the
     * event is published, so organization-scoped partners can see them.
     */
    private function resolveOrganization(IntegrationEvent $event): void
    {
        if ($event->organization_id !== null || $event->is_global) {
            return;
        }

        $employeeId = match ($event->aggregate_type) {
            'employee', 'employee_placement' => $event->aggregate_uuid,
            'assignment', 'id_card' => $event->payload['employee_uuid'] ?? null,
            default => null,
        };

        if (! is_string($employeeId)) {
            return;
        }

        $assignmentId = Employee::query()->whereKey($employeeId)->value('current_assignment_id');
        $organizationId = $assignmentId === null ? null : EmployeeAssignment::query()->whereKey($assignmentId)->value('organization_id');

        if ($organizationId !== null) {
            $event->organization_id = (string) $organizationId;
        }
    }
}
