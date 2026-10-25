<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\ApiRequestLog;
use App\Models\OutboxEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\AuditWriter;
use App\Services\DomainEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The Integration Center for city-wide administrators: API clients (scopes,
 * organizations, addresses, rate limit, expiry; the secret is shown once, on
 * creation and rotation, and revocation ends it at once), webhook endpoints
 * (subscribed events, signing secret shown once), deliveries with manual
 * retry, the event log and the API call log. Every change is audited.
 */
class IntegrationAdminController extends Controller
{
    private const CLIENT_FIELDS = ['uuid', 'name', 'description', 'owner_organization_id', 'scopes', 'allowed_ips', 'rate_limit_per_minute', 'is_active',
        'expires_at', 'revoked_at', 'revocation_reason', 'secret_rotated_at', 'last_used_at', 'last_used_ip', 'created_at'];

    public function __construct(private readonly AuditWriter $audit) {}

    public function clients(): JsonResponse
    {
        $since = now()->subDay();
        $calls = ApiRequestLog::query()->where('created_at', '>=', $since)->groupBy('api_client_id')
            ->selectRaw('api_client_id, count(*) as calls, sum(case when status >= 400 then 1 else 0 end) as errors')->get()->keyBy('api_client_id');

        return response()->json(['data' => ApiClient::query()->orderBy('name')->get()->map(fn (ApiClient $client): array => $client->only(self::CLIENT_FIELDS) + [
            'calls_24h' => (int) ($calls[$client->id]->calls ?? 0),
            'errors_24h' => (int) ($calls[$client->id]->errors ?? 0),
        ]), 'scopes' => ApiClient::SCOPES]);
    }

    public function storeClient(Request $request): JsonResponse
    {
        $data = $this->clientData($request, true);

        return DB::transaction(function () use ($data, $request): JsonResponse {
            $client = new ApiClient($data + ['expires_at' => now()->addYear()]);
            $client->forceFill(['created_by' => $request->user()->id])->save();
            $secret = $client->createToken('integration', $client->scopes, $client->expires_at)->plainTextToken;
            $this->audit->writeFor('api_client.created', $request->user(), 'ApiClient', $client->uuid, $client->owner_organization_id, null,
                $client->only(['name', 'scopes', 'owner_organization_id', 'allowed_ips', 'rate_limit_per_minute', 'expires_at']));

            return response()->json(['data' => $client->only(self::CLIENT_FIELDS), 'secret' => $secret], 201);
        });
    }

    public function updateClient(Request $request, ApiClient $apiClient): JsonResponse
    {
        $this->assertNotRevoked($apiClient);
        $data = $this->clientData($request, false);

        return DB::transaction(function () use ($data, $request, $apiClient): JsonResponse {
            $old = $apiClient->only(array_keys($data));
            $apiClient->fill($data)->save();
            // The token carries the same scopes and expiry as its client.
            $apiClient->tokens()->update(['abilities' => json_encode($apiClient->scopes), 'expires_at' => $apiClient->expires_at]);
            $this->audit->writeFor('api_client.updated', $request->user(), 'ApiClient', $apiClient->uuid, $apiClient->owner_organization_id, $old, $apiClient->only(array_keys($data)));

            return response()->json(['data' => $apiClient->only(self::CLIENT_FIELDS)]);
        });
    }

    public function rotateClient(Request $request, ApiClient $apiClient): JsonResponse
    {
        $this->assertNotRevoked($apiClient);

        return DB::transaction(function () use ($request, $apiClient): JsonResponse {
            $apiClient->tokens()->delete();
            $secret = $apiClient->createToken('integration', $apiClient->scopes, $apiClient->expires_at)->plainTextToken;
            $apiClient->forceFill(['secret_rotated_at' => now()])->save();
            $this->audit->writeFor('api_client.rotated', $request->user(), 'ApiClient', $apiClient->uuid, $apiClient->owner_organization_id);

            return response()->json(['data' => $apiClient->only(self::CLIENT_FIELDS), 'secret' => $secret]);
        });
    }

    public function revokeClient(Request $request, ApiClient $apiClient): JsonResponse
    {
        $this->assertNotRevoked($apiClient);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return DB::transaction(function () use ($request, $apiClient, $data): JsonResponse {
            $apiClient->tokens()->delete();
            $apiClient->forceFill(['revoked_at' => now(), 'revoked_by' => $request->user()->id, 'revocation_reason' => $data['reason'], 'is_active' => false])->save();
            $this->audit->writeFor('api_client.revoked', $request->user(), 'ApiClient', $apiClient->uuid, $apiClient->owner_organization_id, null, ['revoked' => true], $data['reason']);

            return response()->json(['data' => $apiClient->only(self::CLIENT_FIELDS)]);
        });
    }

    public function logs(Request $request): JsonResponse
    {
        $filters = $request->validate(['client' => ['sometimes', 'uuid'], 'errors' => ['sometimes', 'boolean']]);
        $query = ApiRequestLog::query()->leftJoin('api_clients', 'api_clients.id', '=', 'api_request_logs.api_client_id')
            ->when($filters['client'] ?? null, fn ($q, string $uuid) => $q->where('api_clients.uuid', $uuid))
            ->when($request->boolean('errors'), fn ($q) => $q->where('api_request_logs.status', '>=', 400))
            ->orderByDesc('api_request_logs.created_at')->orderByDesc('api_request_logs.id');

        return response()->json($query->paginate(50, ['api_request_logs.id', 'api_clients.uuid as client_uuid', 'api_clients.name as client_name', 'api_request_logs.method',
            'api_request_logs.route', 'api_request_logs.status', 'api_request_logs.duration_ms', 'api_request_logs.ip', 'api_request_logs.denial_code',
            'api_request_logs.request_id', 'api_request_logs.created_at'])->withQueryString());
    }

    public function webhooks(): JsonResponse
    {
        $counts = WebhookDelivery::query()->groupBy('webhook_endpoint_id', 'status')->selectRaw('webhook_endpoint_id, status, count(*) as total')->get()
            ->groupBy('webhook_endpoint_id');

        return response()->json(['data' => WebhookEndpoint::query()->orderBy('name')->get()->map(fn (WebhookEndpoint $endpoint): array => $endpoint->only([
            'id', 'name', 'url', 'event_types', 'scope_organization_id', 'is_active', 'secret_rotated_at', 'last_success_at', 'last_failure_at', 'created_at',
        ]) + ['deliveries' => ($counts[$endpoint->id] ?? collect())->pluck('total', 'status')->map(fn ($n): int => (int) $n)]), 'event_types' => DomainEvents::types()]);
    }

    public function storeWebhook(Request $request): JsonResponse
    {
        $data = $this->webhookData($request, true);

        return DB::transaction(function () use ($request, $data): JsonResponse {
            $secret = 'whsec_'.Str::random(40);
            $endpoint = new WebhookEndpoint($data);
            $endpoint->forceFill(['secret' => $secret, 'created_by' => $request->user()->id, 'secret_rotated_at' => now()])->save();
            $this->audit->writeFor('webhook.created', $request->user(), 'WebhookEndpoint', $endpoint->id, $endpoint->scope_organization_id, null, $endpoint->only(['name', 'url', 'event_types']));

            return response()->json(['data' => $endpoint->only(['id', 'name', 'url', 'event_types', 'scope_organization_id', 'is_active']), 'secret' => $secret], 201);
        });
    }

    public function updateWebhook(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        $data = $this->webhookData($request, false);
        $old = $webhook->only(array_keys($data));
        $webhook->fill($data)->save();
        $this->audit->writeFor('webhook.updated', $request->user(), 'WebhookEndpoint', $webhook->id, $webhook->scope_organization_id, $old, $webhook->only(array_keys($data)));

        return response()->json(['data' => $webhook->only(['id', 'name', 'url', 'event_types', 'scope_organization_id', 'is_active'])]);
    }

    public function rotateWebhook(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        $secret = 'whsec_'.Str::random(40);
        $webhook->forceFill(['secret' => $secret, 'secret_rotated_at' => now()])->save();
        $this->audit->writeFor('webhook.secret_rotated', $request->user(), 'WebhookEndpoint', $webhook->id, $webhook->scope_organization_id);

        return response()->json(['secret' => $secret]);
    }

    public function deliveries(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['sometimes', Rule::in([WebhookDelivery::PENDING, WebhookDelivery::DELIVERED, WebhookDelivery::FAILED])], 'webhook' => ['sometimes', 'uuid']]);
        $query = WebhookDelivery::query()->join('outbox_events', 'outbox_events.id', '=', 'webhook_deliveries.outbox_event_id')
            ->join('webhook_endpoints', 'webhook_endpoints.id', '=', 'webhook_deliveries.webhook_endpoint_id')
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('webhook_deliveries.status', $status))
            ->when($filters['webhook'] ?? null, fn ($q, string $id) => $q->where('webhook_deliveries.webhook_endpoint_id', $id))
            ->orderByDesc('webhook_deliveries.created_at');

        return response()->json($query->paginate(50, ['webhook_deliveries.id', 'webhook_endpoints.name as endpoint_name', 'outbox_events.event_type', 'outbox_events.entity_id',
            'webhook_deliveries.status', 'webhook_deliveries.attempts', 'webhook_deliveries.response_code', 'webhook_deliveries.error',
            'webhook_deliveries.next_attempt_at', 'webhook_deliveries.last_attempt_at', 'webhook_deliveries.delivered_at', 'webhook_deliveries.created_at'])->withQueryString());
    }

    public function retryDelivery(Request $request, WebhookDelivery $delivery): JsonResponse
    {
        if ($delivery->status === WebhookDelivery::DELIVERED) {
            throw ValidationException::withMessages(['delivery' => 'This event was already delivered.']);
        }
        $delivery->forceFill(['status' => WebhookDelivery::PENDING, 'next_attempt_at' => now()])->save();
        $this->audit->writeFor('webhook.delivery_retried', $request->user(), 'WebhookDelivery', $delivery->id, null);

        return response()->json(['data' => $delivery->only(['id', 'status', 'attempts', 'next_attempt_at'])]);
    }

    public function events(Request $request): JsonResponse
    {
        $type = $request->validate(['type' => ['sometimes', 'string', 'max:64']])['type'] ?? null;

        return response()->json(OutboxEvent::query()->when($type, fn ($q, string $t) => $q->where('event_type', $t))
            ->orderByDesc('occurred_at')->paginate(50, ['id', 'event_type', 'entity_type', 'entity_id', 'organization_id', 'payload_version', 'data', 'occurred_at'])->withQueryString());
    }

    /** @return array<string, mixed> */
    private function clientData(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$required, 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'owner_organization_id' => ['nullable', 'uuid', Rule::exists('organizations', 'id')->whereNull('deleted_at')],
            'scopes' => [$required, 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(ApiClient::SCOPES)],
            'allowed_ips' => ['nullable', 'array', 'max:50'],
            'allowed_ips.*' => ['string', 'max:64', 'regex:/^[0-9a-fA-F:.]+(\/\d{1,3})?$/'],
            'rate_limit_per_minute' => ['sometimes', 'integer', 'min:10', 'max:6000'],
            'is_active' => ['sometimes', 'boolean'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);
        if (isset($data['scopes'])) {
            $data['scopes'] = array_values(array_unique($data['scopes']));
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function webhookData(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $https = app()->environment(['local', 'testing']) ? 'url:http,https' : 'url:https';

        return $request->validate([
            'name' => [$required, 'string', 'max:160'],
            'url' => [$required, 'string', 'max:500', $https],
            'event_types' => [$required, 'array', 'min:1'],
            'event_types.*' => ['string', Rule::in([...DomainEvents::types(), '*'])],
            'scope_organization_id' => ['nullable', 'uuid', Rule::exists('organizations', 'id')->whereNull('deleted_at')],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function assertNotRevoked(ApiClient $client): void
    {
        if ($client->revoked_at !== null) {
            throw ValidationException::withMessages(['client' => 'A revoked client stays revoked; register a new one.']);
        }
    }
}
