<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\ManageIntegrations;
use App\Models\ApiClient;
use App\Models\ApiRequestLog;
use App\Models\OutboxEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * What the Integration Center shows, for the API and the screens alike: the
 * clients with their last day's calls, the webhook endpoints with their
 * deliveries by status, the deliveries, the event log and the API call log.
 * No secret is ever part of an answer.
 */
final class IntegrationCenter
{
    /** @return list<array<string, mixed>> */
    public function clients(): array
    {
        // Usage per client (ADR 0010): the last day and the last week, errors, denials and the mean time per call.
        $usage = fn (\DateTimeInterface $since) => ApiRequestLog::query()->where('created_at', '>=', $since)->groupBy('api_client_id')
            ->selectRaw('api_client_id, count(*) as calls, sum(case when status >= 400 then 1 else 0 end) as errors, sum(case when denial_code is not null then 1 else 0 end) as denials, avg(duration_ms) as mean_ms')
            ->get()->keyBy('api_client_id');
        [$day, $week] = [$usage(now()->subDay()), $usage(now()->subDays(7))];

        return ApiClient::query()->orderBy('name')->get()->map(fn (ApiClient $client): array => $client->only(ManageIntegrations::CLIENT_FIELDS) + [
            'calls_24h' => (int) ($day[$client->id]->calls ?? 0),
            'errors_24h' => (int) ($day[$client->id]->errors ?? 0),
            'calls_7d' => (int) ($week[$client->id]->calls ?? 0),
            'errors_7d' => (int) ($week[$client->id]->errors ?? 0),
            'denials_7d' => (int) ($week[$client->id]->denials ?? 0),
            'mean_ms_7d' => isset($week[$client->id]) ? (int) round((float) $week[$client->id]->mean_ms) : null,
        ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function webhooks(): array
    {
        $counts = WebhookDelivery::query()->groupBy('webhook_endpoint_id', 'status')->selectRaw('webhook_endpoint_id, status, count(*) as total')->get()
            ->groupBy('webhook_endpoint_id');

        return WebhookEndpoint::query()->orderBy('name')->get()->map(fn (WebhookEndpoint $endpoint): array => $endpoint->only([
            'id', 'name', 'url', 'event_types', 'scope_organization_id', 'is_active', 'secret_rotated_at', 'last_success_at', 'last_failure_at', 'created_at',
            'consecutive_failures', 'suspended_at', 'suspended_reason', 'previous_secret_expires_at',
        ]) + ['resume_url' => $endpoint->suspended_at !== null ? route('core.integration.webhooks.resume', $endpoint) : null] + ['deliveries' => ($counts[$endpoint->id] ?? collect())->pluck('total', 'status')->map(fn ($n): int => (int) $n)])->values()->all();
    }

    /** @param array{status?: string, webhook?: string} $filters */
    public function deliveries(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        return WebhookDelivery::query()->join('outbox_events', 'outbox_events.id', '=', 'webhook_deliveries.outbox_event_id')
            ->join('webhook_endpoints', 'webhook_endpoints.id', '=', 'webhook_deliveries.webhook_endpoint_id')
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('webhook_deliveries.status', $status))
            ->when($filters['webhook'] ?? null, fn ($q, string $id) => $q->where('webhook_deliveries.webhook_endpoint_id', $id))
            ->orderByDesc('webhook_deliveries.created_at')
            ->paginate($perPage, ['webhook_deliveries.id', 'webhook_endpoints.name as endpoint_name', 'outbox_events.event_type', 'outbox_events.entity_id',
                'webhook_deliveries.status', 'webhook_deliveries.attempts', 'webhook_deliveries.response_code', 'webhook_deliveries.error',
                'webhook_deliveries.next_attempt_at', 'webhook_deliveries.last_attempt_at', 'webhook_deliveries.delivered_at', 'webhook_deliveries.created_at'])
            ->withQueryString();
    }

    public function events(?string $type, int $perPage = 50): LengthAwarePaginator
    {
        return OutboxEvent::query()->when($type, fn ($q, string $t) => $q->where('event_type', $t))
            ->orderByDesc('occurred_at')->paginate($perPage, ['id', 'event_type', 'entity_type', 'entity_id', 'organization_id', 'payload_version', 'data', 'occurred_at'])
            ->withQueryString();
    }

    /** @param array{client?: string} $filters */
    public function logs(array $filters, bool $errorsOnly, int $perPage = 50): LengthAwarePaginator
    {
        return ApiRequestLog::query()->leftJoin('api_clients', 'api_clients.id', '=', 'api_request_logs.api_client_id')
            ->when($filters['client'] ?? null, fn ($q, string $uuid) => $q->where('api_clients.uuid', $uuid))
            ->when($errorsOnly, fn ($q) => $q->where('api_request_logs.status', '>=', 400))
            ->orderByDesc('api_request_logs.created_at')->orderByDesc('api_request_logs.id')
            ->paginate($perPage, ['api_request_logs.id', 'api_clients.uuid as client_uuid', 'api_clients.name as client_name', 'api_request_logs.method',
                'api_request_logs.route', 'api_request_logs.status', 'api_request_logs.duration_ms', 'api_request_logs.ip', 'api_request_logs.denial_code',
                'api_request_logs.request_id', 'api_request_logs.created_at'])->withQueryString();
    }

    /** @return array<string, int> the figures at the top of the Integration Center */
    public function summary(): array
    {
        $since = now()->subDay();

        return [
            'active_clients' => ApiClient::query()->where('is_active', true)->whereNull('revoked_at')->count(),
            'calls_24h' => ApiRequestLog::query()->where('created_at', '>=', $since)->count(),
            'errors_24h' => ApiRequestLog::query()->where('created_at', '>=', $since)->where('status', '>=', 400)->count(),
            'active_webhooks' => WebhookEndpoint::query()->where('is_active', true)->count(),
            'events_24h' => OutboxEvent::query()->where('occurred_at', '>=', $since)->count(),
            'pending_deliveries' => WebhookDelivery::query()->where('status', WebhookDelivery::PENDING)->count(),
            'failed_deliveries' => WebhookDelivery::query()->where('status', WebhookDelivery::FAILED)->count(),
        ];
    }
}
