<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\ManageIntegrations;
use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\DomainEvents;
use App\Services\IntegrationCenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * EUISIS Core/IntegrationCenter for city-wide administrators, with its
 * optional `center` prop: API clients, webhook endpoints, deliveries with
 * manual retry, the event log and the API call log, one tab at a time. The
 * changes are ManageIntegrations'. A created or rotated secret is flashed
 * once (integration_secret_once) and never sent again.
 */
class IntegrationCenterController extends Controller
{
    public const SECRET_FLASH = 'integration_secret_once';

    private const TABS = ['clients', 'webhooks', 'deliveries', 'events', 'logs'];

    public function __construct(private readonly ManageIntegrations $integrations, private readonly IntegrationCenter $center) {}

    public function show(Request $request): Response
    {
        $filters = $request->validate([
            'tab' => ['sometimes', Rule::in(self::TABS)],
            'status' => ['sometimes', 'nullable', Rule::in([WebhookDelivery::PENDING, WebhookDelivery::DELIVERED, WebhookDelivery::FAILED])],
            'webhook' => ['sometimes', 'nullable', 'uuid'],
            'type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'client' => ['sometimes', 'nullable', 'uuid'],
            'errors' => ['sometimes', 'boolean'],
        ]);
        $tab = $filters['tab'] ?? 'clients';
        $filters = array_filter($filters, fn ($value) => $value !== null && $value !== '');

        return Inertia::render('Core/IntegrationCenter', [
            // The EUISIS summary sections stay off: the Core's own center replaces them.
            'applications' => null,
            'events' => null,
            'can' => ['applications' => false, 'events' => false],
            'center' => [
                'tab' => $tab,
                'summary' => $this->center->summary(),
                'filters' => $filters,
                'clients' => $tab === 'clients' ? array_map(fn (array $client): array => $client + ['urls' => [
                    'update' => route('core.integration.clients.update', $client['uuid']),
                    'rotate' => route('core.integration.clients.rotate', $client['uuid']),
                    'revoke' => route('core.integration.clients.revoke', $client['uuid']),
                ]], $this->center->clients()) : null,
                'webhooks' => $tab === 'webhooks' ? array_map(fn (array $webhook): array => $webhook + ['urls' => [
                    'update' => route('core.integration.webhooks.update', $webhook['id']),
                    'rotate' => route('core.integration.webhooks.rotate', $webhook['id']),
                ]], $this->center->webhooks()) : null,
                'deliveries' => $tab === 'deliveries' ? $this->page($this->center->deliveries($filters, 25), fn ($delivery): array => $delivery->toArray() + [
                    'retry_url' => $delivery->status === WebhookDelivery::DELIVERED ? null : route('core.integration.deliveries.retry', $delivery->id),
                ]) : null,
                'events' => $tab === 'events' ? $this->page($this->center->events($filters['type'] ?? null, 25), fn ($event): array => $event->toArray()) : null,
                'logs' => $tab === 'logs' ? $this->page($this->center->logs($filters, (bool) ($filters['errors'] ?? false), 25), fn ($log): array => $log->toArray()) : null,
                'options' => [
                    'scopes' => ApiClient::SCOPES,
                    'event_types' => DomainEvents::types(),
                    'organizations' => in_array($tab, ['clients', 'webhooks'], true) ? Organization::query()->orderBy('name_en')
                        ->get(['id', 'code', 'name_en', 'name_am'])->map(fn (Organization $organization): array => $organization->only(['id', 'code', 'name_en', 'name_am']))->all() : [],
                    'webhooks' => $tab === 'deliveries' ? WebhookEndpoint::query()->orderBy('name')->get(['id', 'name'])->map->only(['id', 'name'])->all() : [],
                    'clients' => $tab === 'logs' ? ApiClient::query()->orderBy('name')->get(['uuid', 'name'])->map->only(['uuid', 'name'])->all() : [],
                ],
                'urls' => [
                    'page' => route('core.integration'),
                    'store_client' => route('core.integration.clients.store'),
                    'store_webhook' => route('core.integration.webhooks.store'),
                ],
            ],
        ]);
    }

    public function storeClient(Request $request): RedirectResponse
    {
        [$client, $secret] = $this->integrations->createClient($this->clientInput($request), $request->user());

        return $this->back('clients', __('integrations.client_created'), ['kind' => 'client', 'name' => $client->name, 'secret' => $secret]);
    }

    public function updateClient(Request $request, ApiClient $apiClient): RedirectResponse
    {
        $this->integrations->updateClient($apiClient, $this->clientInput($request), $request->user());

        return $this->back('clients', __('integrations.client_updated'));
    }

    public function rotateClient(Request $request, ApiClient $apiClient): RedirectResponse
    {
        $secret = $this->integrations->rotateClient($apiClient, $request->user());

        return $this->back('clients', __('integrations.client_rotated'), ['kind' => 'client', 'name' => $apiClient->name, 'secret' => $secret]);
    }

    public function revokeClient(Request $request, ApiClient $apiClient): RedirectResponse
    {
        $this->integrations->revokeClient($apiClient, $request->only('reason'), $request->user());

        return $this->back('clients', __('integrations.client_revoked'));
    }

    public function storeWebhook(Request $request): RedirectResponse
    {
        [$endpoint, $secret] = $this->integrations->createWebhook($this->webhookInput($request), $request->user());

        return $this->back('webhooks', __('integrations.webhook_created'), ['kind' => 'webhook', 'name' => $endpoint->name, 'secret' => $secret]);
    }

    public function updateWebhook(Request $request, WebhookEndpoint $webhook): RedirectResponse
    {
        $this->integrations->updateWebhook($webhook, $this->webhookInput($request), $request->user());

        return $this->back('webhooks', __('integrations.webhook_updated'));
    }

    public function rotateWebhook(Request $request, WebhookEndpoint $webhook): RedirectResponse
    {
        $secret = $this->integrations->rotateWebhook($webhook, $request->user());

        return $this->back('webhooks', __('integrations.webhook_rotated'), ['kind' => 'webhook', 'name' => $webhook->name, 'secret' => $secret]);
    }

    public function retryDelivery(Request $request, WebhookDelivery $delivery): RedirectResponse
    {
        $this->integrations->retryDelivery($delivery, $request->user());

        return back()->with('flash', ['message' => __('integrations.delivery_retried'), 'type' => 'success']);
    }

    /** The form sends empty fields as empty strings; the action reads them as absent. */
    private function clientInput(Request $request): array
    {
        $input = $request->only(['name', 'description', 'scope_type', 'owner_organization_id', 'scopes', 'allowed_ips', 'rate_limit_per_minute', 'is_active', 'expires_at']);
        foreach (['description', 'owner_organization_id', 'expires_at', 'scope_type'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] === '') {
                $input[$field] = null;
            }
        }
        if (array_key_exists('allowed_ips', $input) && is_string($input['allowed_ips'])) {
            $input['allowed_ips'] = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $input['allowed_ips']) ?: [])));
        }

        return $input;
    }

    private function webhookInput(Request $request): array
    {
        $input = $request->only(['name', 'url', 'event_types', 'scope_organization_id', 'is_active']);
        if (array_key_exists('scope_organization_id', $input) && $input['scope_organization_id'] === '') {
            $input['scope_organization_id'] = null;
        }

        return $input;
    }

    /** @param array{kind: string, name: string, secret: string}|null $secret */
    private function back(string $tab, string $message, ?array $secret = null): RedirectResponse
    {
        $redirect = to_route('core.integration', ['tab' => $tab])->with('flash', ['message' => $message, 'type' => 'success']);

        return $secret === null ? $redirect : $redirect->with(self::SECRET_FLASH, $secret);
    }

    /** @return array{data: list<array<string, mixed>>, meta: array<string, int|null>} */
    private function page(LengthAwarePaginator $page, callable $row): array
    {
        return [
            'data' => collect($page->items())->map($row)->values()->all(),
            'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'perPage' => $page->perPage(),
                'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ];
    }
}
