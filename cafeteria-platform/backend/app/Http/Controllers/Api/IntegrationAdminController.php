<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\ManageIntegrations;
use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\DomainEvents;
use App\Services\IntegrationCenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Integration Center for city-wide administrators, as JSON: API clients,
 * webhook endpoints, deliveries with manual retry, the event log and the API
 * call log. The changes are ManageIntegrations' (the screens use the same);
 * a secret is in the answer only when it is created or rotated.
 */
class IntegrationAdminController extends Controller
{
    public function __construct(private readonly ManageIntegrations $integrations, private readonly IntegrationCenter $center) {}

    public function clients(): JsonResponse
    {
        return response()->json(['data' => $this->center->clients(), 'scopes' => ApiClient::SCOPES]);
    }

    public function storeClient(Request $request): JsonResponse
    {
        [$client, $secret] = $this->integrations->createClient($request->all(), $request->user());

        return response()->json(['data' => $client->only(ManageIntegrations::CLIENT_FIELDS), 'secret' => $secret], 201);
    }

    public function updateClient(Request $request, ApiClient $apiClient): JsonResponse
    {
        $client = $this->integrations->updateClient($apiClient, $request->all(), $request->user());

        return response()->json(['data' => $client->only(ManageIntegrations::CLIENT_FIELDS)]);
    }

    public function rotateClient(Request $request, ApiClient $apiClient): JsonResponse
    {
        $secret = $this->integrations->rotateClient($apiClient, $request->user());

        return response()->json(['data' => $apiClient->only(ManageIntegrations::CLIENT_FIELDS), 'secret' => $secret]);
    }

    public function revokeClient(Request $request, ApiClient $apiClient): JsonResponse
    {
        $client = $this->integrations->revokeClient($apiClient, $request->all(), $request->user());

        return response()->json(['data' => $client->only(ManageIntegrations::CLIENT_FIELDS)]);
    }

    public function logs(Request $request): JsonResponse
    {
        $filters = $request->validate(['client' => ['sometimes', 'uuid'], 'errors' => ['sometimes', 'boolean']]);

        return response()->json($this->center->logs($filters, $request->boolean('errors')));
    }

    public function webhooks(): JsonResponse
    {
        return response()->json(['data' => $this->center->webhooks(), 'event_types' => DomainEvents::types()]);
    }

    public function storeWebhook(Request $request): JsonResponse
    {
        [$endpoint, $secret] = $this->integrations->createWebhook($request->all(), $request->user());

        return response()->json(['data' => $endpoint->only(ManageIntegrations::WEBHOOK_FIELDS), 'secret' => $secret], 201);
    }

    public function updateWebhook(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        $endpoint = $this->integrations->updateWebhook($webhook, $request->all(), $request->user());

        return response()->json(['data' => $endpoint->only(ManageIntegrations::WEBHOOK_FIELDS)]);
    }

    public function rotateWebhook(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        return response()->json(['secret' => $this->integrations->rotateWebhook($webhook, $request->user())]);
    }

    public function deliveries(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['sometimes', Rule::in([WebhookDelivery::PENDING, WebhookDelivery::DELIVERED, WebhookDelivery::FAILED])], 'webhook' => ['sometimes', 'uuid']]);

        return response()->json($this->center->deliveries($filters));
    }

    public function retryDelivery(Request $request, WebhookDelivery $delivery): JsonResponse
    {
        $delivery = $this->integrations->retryDelivery($delivery, $request->user());

        return response()->json(['data' => $delivery->only(['id', 'status', 'attempts', 'next_attempt_at'])]);
    }

    public function events(Request $request): JsonResponse
    {
        $type = $request->validate(['type' => ['sometimes', 'string', 'max:64']])['type'] ?? null;

        return response()->json($this->center->events($type));
    }
}
