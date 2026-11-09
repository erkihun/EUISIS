<?php

declare(strict_types=1);

namespace App\Services\Integration\Webhooks;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\ExternalApplication;
use App\Models\Integration\WebhookSubscription;
use App\Models\User;
use App\Services\Integration\Events\EventCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates and governs webhook subscriptions. Administrative only — partners do
 * not create subscriptions or see secrets (docs/integration/partner-onboarding.md).
 *
 * A subscription may only name event types its application's scopes allow;
 * removing a scope later stops delivery at once (SubscriptionVisibility).
 * Secrets are random 32-byte values, returned once by create()/rotateSecret()
 * and stored encrypted.
 */
final class SubscriptionService
{
    public function __construct(
        private readonly CallbackUrlPolicy $urls,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /**
     * @param  list<string>  $eventTypes
     * @return array{0: WebhookSubscription, 1: string} [subscription, plaintext secret shown once]
     */
    public function create(ExternalApplication $application, string $name, string $callbackUrl, array $eventTypes, User $actor): array
    {
        $this->validate($application, $callbackUrl, $eventTypes);
        $secret = $this->newSecret();

        $subscription = DB::transaction(fn (): WebhookSubscription => WebhookSubscription::query()->create([
            'external_application_id' => $application->getKey(),
            'name' => $name,
            'callback_url' => $callbackUrl,
            'event_types' => array_values(array_unique($eventTypes)),
            'status' => WebhookSubscription::STATUS_ACTIVE,
            'secret' => $secret,
            'secret_rotated_at' => Carbon::now(),
            // Starts with events published after now; history comes from the
            // change feed or an explicit replay, never an accidental flood.
            'start_after_position' => (int) DB::table('integration_feed_state')->where('id', 1)->value('last_position'),
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ]));

        $this->log($actor, 'Webhook subscription created: '.$application->code.' / '.$subscription->name);

        return [$subscription, $secret];
    }

    /** @param list<string> $eventTypes */
    public function update(WebhookSubscription $subscription, string $name, string $callbackUrl, array $eventTypes, User $actor): WebhookSubscription
    {
        $this->validate($subscription->application, $callbackUrl, $eventTypes);

        $subscription->forceFill([
            'name' => $name,
            'callback_url' => $callbackUrl,
            'event_types' => array_values(array_unique($eventTypes)),
            'updated_by' => $actor->getKey(),
        ])->save();

        $this->log($actor, 'Webhook subscription updated: '.$subscription->application->code.' / '.$subscription->name);

        return $subscription;
    }

    /** Old secret keeps signing for the overlap window. @return string new plaintext secret, shown once */
    public function rotateSecret(WebhookSubscription $subscription, User $actor): string
    {
        $secret = $this->newSecret();
        $overlap = max(0, (int) config('integration.webhooks.rotation_overlap_hours', 24));

        $subscription->forceFill([
            'previous_secret' => $overlap > 0 ? $subscription->secret : null,
            'previous_secret_expires_at' => $overlap > 0 ? Carbon::now()->addHours($overlap) : null,
            'secret' => $secret,
            'secret_rotated_at' => Carbon::now(),
            'updated_by' => $actor->getKey(),
        ])->save();

        $this->log($actor, 'Webhook secret rotated: '.$subscription->application->code.' / '.$subscription->name.' (overlap '.$overlap.'h)', AuditEventType::IntegrationSecretRotated);

        return $secret;
    }

    public function setStatus(WebhookSubscription $subscription, string $status, User $actor): void
    {
        $subscription->forceFill(['status' => $status, 'updated_by' => $actor->getKey()])->save();
        $this->log($actor, 'Webhook subscription '.$status.': '.$subscription->application->code.' / '.$subscription->name);
    }

    /** @param list<string> $eventTypes */
    private function validate(?ExternalApplication $application, string $callbackUrl, array $eventTypes): void
    {
        $errors = [];

        if ($application === null) {
            $errors['external_application_id'] = __('integrations.errors.application_required');
        }

        if (($urlError = $this->urls->check($callbackUrl)) !== null) {
            $errors['callback_url'] = __('integrations.errors.url_'.$urlError);
        }

        if ($eventTypes === []) {
            $errors['event_types'] = __('integrations.errors.event_types_required');
        } elseif ($application !== null) {
            $allowed = EventCatalog::typesForScopes($application->grantableScopes());
            $refused = array_values(array_diff($eventTypes, $allowed));
            if ($refused !== []) {
                $errors['event_types'] = __('integrations.errors.event_types_not_allowed', ['types' => implode(', ', $refused)]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function newSecret(): string
    {
        return 'whsec_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function log(User $actor, string $reason, AuditEventType $type = AuditEventType::IntegrationSubscriptionChanged): void
    {
        $this->audit->execute($type, $actor, reason: $reason);
    }

    public static function maskedSecretHint(): string
    {
        return 'whsec_'.Str::repeat('•', 8);
    }
}
