<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ExternalApplication;
use App\Models\Integration\WebhookDelivery;
use App\Models\Integration\WebhookSubscription;
use App\Services\Integration\Webhooks\ReplayService;
use App\Support\Api\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A partner's view of its OWN webhook subscriptions (webhooks.manage) and a
 * bounded self-service replay (webhooks.replay).
 *
 * Subscriptions are created, re-pointed and given secrets by administrators
 * only; nothing here reveals a secret. Another application's subscription is
 * a 404 — never a cross-partner IDOR.
 */
class WebhookSubscriptionApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (($refusal = $this->refuse($request)) !== null) {
            return $refusal;
        }

        $subscriptions = WebhookSubscription::query()
            ->where('external_application_id', $request->user()->getKey())
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $subscriptions->map(fn (WebhookSubscription $subscription): array => $this->present($subscription))->all(),
            'request_id' => ApiError::requestId($request),
        ]);
    }

    public function deliveries(Request $request, string $subscription): JsonResponse
    {
        if (($refusal = $this->refuse($request)) !== null) {
            return $refusal;
        }
        $owned = $this->owned($request, $subscription);
        if ($owned === null) {
            return ApiError::response($request, 404, 'not_found', 'Not Found.');
        }

        $validated = $request->validate([
            'status' => ['nullable', 'in:pending,in_flight,retrying,succeeded,dead,skipped'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $rows = WebhookDelivery::query()
            ->where('webhook_subscription_id', $owned->getKey())
            ->when(! empty($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->with('event:id,event_type')
            ->orderByDesc('feed_position')
            ->limit((int) ($validated['limit'] ?? 50))
            ->get();

        return response()->json([
            'data' => $rows->map(fn (WebhookDelivery $delivery): array => [
                'delivery_id' => $delivery->getKey(),
                'event_id' => $delivery->event_id,
                'event_type' => $delivery->event?->event_type,
                'generation' => $delivery->generation,
                'status' => $delivery->status,
                'attempts' => $delivery->attempt_count,
                'last_http_status' => $delivery->last_http_status,
                'last_error_category' => $delivery->last_error_category,
                'last_attempt_at' => $delivery->last_attempt_at?->toIso8601ZuluString(),
                'next_attempt_at' => $delivery->next_attempt_at?->toIso8601ZuluString(),
                'delivered_at' => $delivery->delivered_at?->toIso8601ZuluString(),
            ])->all(),
            'request_id' => ApiError::requestId($request),
        ]);
    }

    public function replay(Request $request, string $subscription, ReplayService $replays): JsonResponse
    {
        if (($refusal = $this->refuse($request)) !== null) {
            return $refusal;
        }
        $owned = $this->owned($request, $subscription);
        if ($owned === null) {
            return ApiError::response($request, 404, 'not_found', 'Not Found.');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'event_ids' => ['nullable', 'array', 'max:1000'],
            'event_ids.*' => ['uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'event_types' => ['nullable', 'array'],
            'event_types.*' => ['string', 'max:64'],
            'only_failed' => ['nullable', 'boolean'],
        ]);

        $replay = $replays->replay($owned, array_filter([
            'event_ids' => $validated['event_ids'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'event_types' => $validated['event_types'] ?? null,
            'only_failed' => (bool) ($validated['only_failed'] ?? false),
        ], fn ($value) => $value !== null && $value !== false), $validated['reason'], $request->user());

        return response()->json([
            'replay_id' => $replay->getKey(),
            'event_count' => $replay->event_count,
            'status' => $replay->status,
            'request_id' => ApiError::requestId($request),
        ], 202);
    }

    private function owned(Request $request, string $id): ?WebhookSubscription
    {
        return WebhookSubscription::query()
            ->whereKey($id)
            ->where('external_application_id', $request->user()->getKey())
            ->first();
    }

    /** @return array<string, mixed> */
    private function present(WebhookSubscription $subscription): array
    {
        return [
            'subscription_id' => $subscription->getKey(),
            'name' => $subscription->name,
            'callback_url' => $subscription->callback_url,
            'event_types' => $subscription->event_types,
            'status' => $subscription->status,
            'health' => $subscription->health(),
            'secret_rotated_at' => $subscription->secret_rotated_at?->toIso8601ZuluString(),
            'previous_secret_valid_until' => $subscription->previous_secret_expires_at?->isFuture() ? $subscription->previous_secret_expires_at->toIso8601ZuluString() : null,
            'last_success_at' => $subscription->last_success_at?->toIso8601ZuluString(),
            'last_failure_at' => $subscription->last_failure_at?->toIso8601ZuluString(),
            'last_failure_category' => $subscription->last_failure_category,
        ];
    }

    private function refuse(Request $request): ?JsonResponse
    {
        if (! $request->user() instanceof ExternalApplication) {
            return ApiError::response($request, 403, 'application_required', 'Available to registered applications only.');
        }

        return null;
    }
}
