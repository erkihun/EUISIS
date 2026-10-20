<?php

declare(strict_types=1);

namespace App\Services\Integration\Webhooks;

use App\Models\ExternalApplication;
use App\Models\Integration\IntegrationEvent;
use App\Models\Integration\WebhookSubscription;
use App\Services\Integration\Events\ChangeVisibility;

/**
 * Whether one event is owed to one subscription: subscription and application
 * active, the type subscribed AND still allowed by the application's scopes,
 * inside its organization bound, and after the subscription's start position.
 * Re-checked at delivery time, so a scope or grant withdrawn after the event
 * was published stops the delivery.
 */
final class SubscriptionVisibility
{
    public function __construct(private readonly ChangeVisibility $visibility) {}

    public function shouldDeliver(WebhookSubscription $subscription, IntegrationEvent $event): bool
    {
        $application = $subscription->application;

        return $subscription->isActive()
            && $application instanceof ExternalApplication
            && $application->isActive()
            && $event->feed_position !== null
            && $event->feed_position > $subscription->start_after_position
            && $this->stillVisible($subscription, $event);
    }

    /** Delivery-time re-check (also used by replays, which ignore the start position). */
    public function stillVisible(WebhookSubscription $subscription, IntegrationEvent $event): bool
    {
        $application = $subscription->application;

        return $subscription->isActive()
            && $application instanceof ExternalApplication
            && $application->isActive()
            && in_array($event->event_type, $subscription->event_types ?? [], true)
            && $this->visibility->canSee($application, $event);
    }
}
