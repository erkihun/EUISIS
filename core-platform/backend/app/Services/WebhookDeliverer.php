<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends due webhook deliveries, outside any business transaction. Each body is
 * the event payload; it is signed with the endpoint's secret:
 *   X-Core-Signature: v1=<hex HMAC-SHA256 of "<X-Core-Timestamp>.<body>">
 * A 2xx answer delivers it; anything else is retried with back-off, and after
 * the last attempt the delivery is failed (an administrator can retry it).
 * A delivery is claimed before sending, so two runners never send it twice
 * at the same time.
 */
final class WebhookDeliverer
{
    /** minutes to wait after attempt n (1-based); after the last, the delivery fails */
    public const BACKOFF_MINUTES = [1, 5, 15, 60, 180, 360, 720];

    public function deliverDue(int $limit = 100): int
    {
        $sent = 0;
        $due = WebhookDelivery::query()->where('status', WebhookDelivery::PENDING)->where('next_attempt_at', '<=', now())
            ->whereHas('endpoint', fn ($q) => $q->where('is_active', true))
            ->orderBy('next_attempt_at')->limit($limit)->pluck('id');
        foreach ($due as $id) {
            // Claim: push the next attempt out first; only the runner that moved it sends.
            $claimed = DB::table('webhook_deliveries')->where('id', $id)->where('status', WebhookDelivery::PENDING)
                ->where('next_attempt_at', '<=', now())->update(['next_attempt_at' => now()->addMinutes(5), 'updated_at' => now()]);
            if ($claimed === 1) {
                $this->send(WebhookDelivery::query()->with(['endpoint', 'event'])->findOrFail($id));
                $sent++;
            }
        }

        return $sent;
    }

    public function send(WebhookDelivery $delivery): void
    {
        $endpoint = $delivery->endpoint;
        $body = (string) json_encode($delivery->event->payload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) now()->getTimestamp();
        $code = null;
        $error = null;
        try {
            $response = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()
                ->withHeaders([
                    'X-Core-Event' => $delivery->event->event_type,
                    'X-Core-Event-Id' => $delivery->event->id,
                    'X-Core-Delivery' => $delivery->id,
                    'X-Core-Timestamp' => $timestamp,
                    'X-Core-Signature' => 'v1='.self::signature((string) $endpoint->secret, $timestamp, $body),
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);
            $code = $response->status();
            if (! $response->successful()) {
                $error = 'HTTP '.$code;
            }
        } catch (Throwable $exception) {
            $error = mb_substr($exception->getMessage(), 0, 1000);
        }

        $attempts = $delivery->attempts + 1;
        if ($error === null) {
            $delivery->forceFill(['status' => WebhookDelivery::DELIVERED, 'attempts' => $attempts, 'last_attempt_at' => now(), 'delivered_at' => now(),
                'response_code' => $code, 'error' => null, 'next_attempt_at' => null])->save();
            $endpoint->forceFill(['last_success_at' => now()])->saveQuietly();

            return;
        }
        $wait = self::BACKOFF_MINUTES[$attempts - 1] ?? null;
        $delivery->forceFill([
            'status' => $wait === null ? WebhookDelivery::FAILED : WebhookDelivery::PENDING,
            'attempts' => $attempts, 'last_attempt_at' => now(), 'response_code' => $code, 'error' => $error,
            'next_attempt_at' => $wait === null ? null : now()->addMinutes($wait),
        ])->save();
        $endpoint->forceFill(['last_failure_at' => now()])->saveQuietly();
    }

    public static function signature(string $secret, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
