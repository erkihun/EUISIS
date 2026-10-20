<?php

declare(strict_types=1);

namespace App\Services\Integration\Webhooks;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\ExternalApplication;
use App\Models\Integration\IntegrationEvent;
use App\Models\Integration\IntegrationReplay;
use App\Models\Integration\WebhookDelivery;
use App\Models\Integration\WebhookSubscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Re-delivers already published events to one subscription.
 *
 * A replay never creates a business event: it adds a new delivery
 * generation for the SAME outbox row, so the partner receives the original
 * event_id, sequence and payload and can de-duplicate. Only events the
 * subscription may still see are replayed. Every replay is recorded
 * (integration_replays) and audited: who, which subscription, what criteria,
 * why, how many.
 *
 * Criteria (any combination): event_ids, from/to occurred_at, event_types,
 * only_failed (deliveries that ended dead or skipped).
 */
final class ReplayService
{
    public function __construct(
        private readonly SubscriptionVisibility $visibility,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /**
     * @param  array{event_ids?: list<string>, from?: string|null, to?: string|null, event_types?: list<string>, only_failed?: bool}  $criteria
     */
    public function replay(WebhookSubscription $subscription, array $criteria, string $reason, User|ExternalApplication $initiator): IntegrationReplay
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('integrations.errors.reason_required')]);
        }
        if (! $subscription->isActive()) {
            throw ValidationException::withMessages(['subscription' => __('integrations.errors.subscription_inactive')]);
        }

        $max = max(1, (int) config('integration.webhooks.replay_max_events', 5000));
        $query = IntegrationEvent::query()->published()->orderBy('feed_position');

        if (! empty($criteria['event_ids'])) {
            $query->whereIn('event_id', array_slice($criteria['event_ids'], 0, $max));
        }
        if (! empty($criteria['from'])) {
            $query->where('occurred_at', '>=', Carbon::parse($criteria['from']));
        }
        if (! empty($criteria['to'])) {
            $query->where('occurred_at', '<=', Carbon::parse($criteria['to']));
        }
        $types = array_values(array_intersect($criteria['event_types'] ?? $subscription->event_types ?? [], $subscription->event_types ?? []));
        $query->whereIn('event_type', $types === [] ? ['__none__'] : $types);

        if (! empty($criteria['only_failed'])) {
            $query->whereIn('event_id', WebhookDelivery::query()
                ->where('webhook_subscription_id', $subscription->getKey())
                ->whereIn('status', [WebhookDelivery::STATUS_DEAD, WebhookDelivery::STATUS_SKIPPED])
                ->select('event_id'));
        }

        if (empty($criteria['event_ids']) && empty($criteria['from']) && empty($criteria['only_failed'])) {
            throw ValidationException::withMessages(['criteria' => __('integrations.errors.replay_criteria_required')]);
        }

        $subscription->loadMissing('application');
        $events = $query->limit($max + 1)->get();
        if ($events->count() > $max) {
            throw ValidationException::withMessages(['criteria' => __('integrations.errors.replay_too_large', ['max' => $max])]);
        }

        return DB::transaction(function () use ($subscription, $criteria, $reason, $initiator, $events): IntegrationReplay {
            $replay = IntegrationReplay::query()->create([
                'webhook_subscription_id' => $subscription->getKey(),
                'initiated_by_user_id' => $initiator instanceof User ? $initiator->getKey() : null,
                'initiated_by_application_id' => $initiator instanceof ExternalApplication ? $initiator->getKey() : null,
                'criteria' => $criteria,
                'reason' => mb_substr($reason, 0, 500),
                'status' => 'queued',
            ]);

            $count = 0;
            foreach ($events as $event) {
                if (! $this->visibility->stillVisible($subscription, $event)) {
                    continue;
                }
                $generation = (int) WebhookDelivery::query()
                    ->where('webhook_subscription_id', $subscription->getKey())
                    ->where('event_id', $event->event_id)
                    ->max('generation');
                $hasAny = WebhookDelivery::query()->where('webhook_subscription_id', $subscription->getKey())->where('event_id', $event->event_id)->exists();

                WebhookDelivery::query()->create([
                    'webhook_subscription_id' => $subscription->getKey(),
                    'integration_event_id' => $event->getKey(),
                    'event_id' => $event->event_id,
                    'feed_position' => $event->feed_position,
                    'aggregate_key' => $event->aggregateKey(),
                    'generation' => $hasAny ? $generation + 1 : 0,
                    'replay_id' => $replay->getKey(),
                    'status' => WebhookDelivery::STATUS_PENDING,
                    'next_attempt_at' => Carbon::now(),
                ]);
                $count++;
            }

            $replay->forceFill(['event_count' => $count, 'status' => 'queued'])->save();

            $this->audit->execute(
                AuditEventType::IntegrationReplayRequested,
                $initiator instanceof User ? $initiator : null,
                reason: 'Webhook replay '.$replay->getKey().' for '.$subscription->application?->code.' / '.$subscription->name
                    .' by '.($initiator instanceof User ? 'user:'.$initiator->getKey() : 'application:'.$initiator->code)
                    .': '.$count.' event(s). Reason: '.mb_substr($reason, 0, 200),
            );

            return $replay;
        });
    }
}
