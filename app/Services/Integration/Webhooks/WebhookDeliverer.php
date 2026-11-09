<?php

declare(strict_types=1);

namespace App\Services\Integration\Webhooks;

use App\Models\Integration\WebhookDelivery;
use App\Models\Integration\WebhookDeliveryAttempt;
use App\Models\Integration\WebhookSubscription;
use App\Services\Integration\Events\ChangeVisibility;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one claimed delivery and records the outcome.
 *
 * At-least-once: a partner may receive the same event_id more than once
 * (a retry after a lost acknowledgement, a replay) and must process event_id
 * idempotently. Exactly-once is not claimed.
 *
 * Safety: the callback URL is re-vetted (CallbackUrlPolicy) and the connection
 * pinned to the vetted IP; redirects are not followed; TLS is verified;
 * connect/total timeouts are strict; the response body is never read or
 * stored — only status, latency and a safe error category.
 */
final class WebhookDeliverer
{
    public function __construct(
        private readonly CallbackUrlPolicy $urls,
        private readonly RetryPolicy $retry,
        private readonly ChangeVisibility $visibility,
        private readonly SubscriptionVisibility $subscriptions,
    ) {}

    public function deliver(WebhookDelivery $delivery): string
    {
        $delivery->loadMissing(['subscription.application', 'event']);
        $subscription = $delivery->subscription;
        $event = $delivery->event;

        // Scope, grant, type or status changed since publication: not owed any more.
        if ($subscription === null || $event === null || ! $this->subscriptions->stillVisible($subscription, $event)) {
            $delivery->forceFill(['status' => WebhookDelivery::STATUS_SKIPPED, 'locked_until' => null, 'last_error_category' => 'no_longer_visible'])->save();

            return WebhookDelivery::STATUS_SKIPPED;
        }

        $body = (string) json_encode($this->visibility->present($event, $subscription->application), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $attemptNo = $delivery->attempt_count + 1;
        $startedAt = Carbon::now();
        [$status, $transportError] = $this->send($subscription, $delivery, $event->event_id, $event->event_type, $body);
        $completedAt = Carbon::now();
        $latency = (int) round($startedAt->diffInMilliseconds($completedAt, true));

        [$outcome, $category] = $this->retry->classify($status, $transportError);

        WebhookDeliveryAttempt::query()->create([
            'webhook_delivery_id' => $delivery->getKey(), 'attempt' => $attemptNo, 'outcome' => $outcome,
            'http_status' => $status, 'error_category' => $category, 'latency_ms' => $latency,
            'started_at' => $startedAt, 'completed_at' => $completedAt,
        ]);

        $finalStatus = match (true) {
            $outcome === RetryPolicy::SUCCEEDED => WebhookDelivery::STATUS_SUCCEEDED,
            $outcome === RetryPolicy::PERMANENT, $attemptNo >= $this->retry->maxAttempts() => WebhookDelivery::STATUS_DEAD,
            default => WebhookDelivery::STATUS_RETRYING,
        };

        $delivery->forceFill([
            'status' => $finalStatus,
            'attempt_count' => $attemptNo,
            'last_http_status' => $status,
            'last_error_category' => $category,
            'last_latency_ms' => $latency,
            'first_attempt_at' => $delivery->first_attempt_at ?? $startedAt,
            'last_attempt_at' => $completedAt,
            'delivered_at' => $finalStatus === WebhookDelivery::STATUS_SUCCEEDED ? $completedAt : null,
            'next_attempt_at' => $finalStatus === WebhookDelivery::STATUS_RETRYING ? $completedAt->copy()->addSeconds($this->retry->delaySeconds($attemptNo + 1)) : null,
            'locked_until' => null,
        ])->save();

        if ($finalStatus === WebhookDelivery::STATUS_SUCCEEDED) {
            $subscription->forceFill(['consecutive_failures' => 0, 'last_success_at' => $completedAt])->saveQuietly();
        } else {
            $subscription->forceFill([
                'consecutive_failures' => $subscription->consecutive_failures + 1,
                'last_failure_at' => $completedAt,
                'last_failure_category' => $category,
            ])->saveQuietly();
        }

        return $finalStatus;
    }

    /**
     * A clearly marked synthetic event — never a real business event — to
     * prove the endpoint, TLS and signature verification.
     *
     * @return array{ok: bool, http_status: int|null, error_category: string|null, latency_ms: int}
     */
    public function sendTest(WebhookSubscription $subscription): array
    {
        $eventId = (string) Str::uuid7();
        $body = (string) json_encode([
            'event_id' => $eventId,
            'event_type' => 'webhook.test',
            'event_version' => 1,
            'aggregate_type' => 'webhook_subscription',
            'aggregate_uuid' => $subscription->getKey(),
            'organization_uuid' => null,
            'sequence' => 0,
            'change_kind' => 'test',
            'occurred_at' => Carbon::now()->toIso8601ZuluString('microsecond'),
            'effective_at' => null,
            'correlation_id' => null,
            'data' => ['test' => true, 'message' => 'Synthetic test event. Not a business change.'],
        ], JSON_UNESCAPED_SLASHES);

        $started = Carbon::now();
        [$status, $error] = $this->send($subscription, null, $eventId, 'webhook.test', $body);
        [$outcome, $category] = $this->retry->classify($status, $error);

        return ['ok' => $outcome === RetryPolicy::SUCCEEDED, 'http_status' => $status, 'error_category' => $category, 'latency_ms' => (int) round($started->diffInMilliseconds(Carbon::now(), true))];
    }

    /** @return array{0: int|null, 1: string|null} [http status, transport error category] */
    private function send(WebhookSubscription $subscription, ?WebhookDelivery $delivery, string $eventId, string $eventType, string $body): array
    {
        $target = $this->urls->resolve($subscription->callback_url);
        if ($target['error'] !== null) {
            return [null, 'url_'.$target['error']];
        }

        $timestamp = time();
        $headers = [
            WebhookSigner::HEADER_SIGNATURE => WebhookSigner::signatureHeader($subscription->signingSecrets(), $timestamp, $eventId, $body),
            WebhookSigner::HEADER_EVENT_ID => $eventId,
            WebhookSigner::HEADER_EVENT_TYPE => $eventType,
            WebhookSigner::HEADER_TIMESTAMP => (string) $timestamp,
            'User-Agent' => 'AddisOne-Webhooks/1',
        ];
        if ($delivery !== null) {
            $headers[WebhookSigner::HEADER_DELIVERY_ID] = (string) $delivery->getKey();
        }

        $options = [
            'allow_redirects' => false,
            'verify' => true,
            'stream' => true,
            'http_errors' => false,
        ];
        if ($target['ip'] !== null && defined('CURLOPT_RESOLVE')) {
            $ip = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];
            $options['curl'] = [CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.$ip]];
        }

        try {
            $response = Http::withOptions($options)
                ->connectTimeout((int) config('integration.webhooks.connect_timeout_seconds', 3))
                ->timeout((int) config('integration.webhooks.timeout_seconds', 10))
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($subscription->callback_url);

            return [$response->status(), null];
        } catch (ConnectionException $exception) {
            return [null, str_contains(strtolower($exception->getMessage()), 'timed out') ? 'timeout' : 'network_error'];
        } catch (Throwable) {
            return [null, 'network_error'];
        }
    }
}
