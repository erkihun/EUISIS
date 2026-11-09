<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\ExternalApplication;
use App\Models\Integration\IntegrationEvent;
use App\Models\Integration\IntegrationReplay;
use App\Models\Integration\WebhookDelivery;
use App\Models\Integration\WebhookSubscription;
use App\Services\Integration\Events\ChangeVisibility;
use App\Services\Integration\Events\EventCatalog;
use App\Services\Integration\IntegrationMonitor;
use App\Services\Integration\Webhooks\ReplayService;
use App\Services\Integration\Webhooks\SubscriptionService;
use App\Services\Integration\Webhooks\WebhookDeliverer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * System Settings → Event Integration.
 *
 * Integration administrators see the outbox / change-feed / webhook KPIs,
 * manage subscriptions (secrets shown once), read delivery history (metadata
 * only), replay (separate, audited permission) and — with its own audited
 * permission — inspect a single event's minimised payload. Nothing here is
 * reachable by an Organizational Admin.
 */
class IntegrationController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly WriteAuditLogAction $audit,
    ) {}

    public function index(IntegrationMonitor $monitor): Response
    {
        $this->authorizePermission('integrations.view');
        $user = Auth::user();

        $subscriptions = WebhookSubscription::query()->with('application:id,name,code')->orderBy('name')->get();
        $failed = $user?->can('integrations.deliveries.view')
            ? WebhookDelivery::query()->whereIn('status', [WebhookDelivery::STATUS_DEAD, WebhookDelivery::STATUS_RETRYING])
                ->with(['subscription:id,name', 'event:id,event_type'])->orderByDesc('updated_at')->limit(20)->get()
            : collect();
        $feedPosition = (int) DB::table('integration_feed_state')->where('id', 1)->value('last_position');

        return Inertia::render('Integrations/Index', [
            'kpis' => $monitor->snapshot(),
            'subscriptions' => $subscriptions->map(fn (WebhookSubscription $subscription): array => $this->subscriptionRow($subscription))->all(),
            'failedDeliveries' => $failed->map(fn (WebhookDelivery $delivery): array => $this->deliveryRow($delivery) + ['subscription_id' => $delivery->webhook_subscription_id, 'subscription' => $delivery->subscription?->name])->all(),
            'consumers' => DB::table('integration_feed_consumers')
                ->join('external_applications', 'external_applications.id', '=', 'integration_feed_consumers.external_application_id')
                ->orderBy('external_applications.name')
                ->get(['external_applications.name', 'external_applications.code', 'integration_feed_consumers.last_position', 'integration_feed_consumers.last_read_at'])
                ->map(fn ($row): array => ['name' => $row->name, 'code' => $row->code, 'last_position' => (int) $row->last_position, 'behind' => max(0, $feedPosition - (int) $row->last_position), 'last_read_at' => $row->last_read_at])->all(),
            'can' => $this->can(),
        ]);
    }

    public function create(): Response
    {
        $this->authorizePermission('integrations.manage');

        return Inertia::render('Integrations/Form', ['subscription' => null, 'applications' => $this->applicationOptions()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission('integrations.manage');
        $data = $this->validated($request, true);
        $application = ExternalApplication::query()->findOrFail($data['external_application_id']);

        [$subscription, $secret] = $this->subscriptions->create($application, $data['name'], $data['callback_url'], $data['event_types'], $request->user());

        return to_route('integrations.subscriptions.show', $subscription)
            ->with('flash', ['message' => __('integrations.flash.created'), 'type' => 'success', 'generated_secret' => $secret]);
    }

    public function show(Request $request, WebhookSubscription $subscription): Response
    {
        $this->authorizePermission('integrations.view');
        $subscription->load('application:id,name,code,status');
        $status = $request->string('status')->toString();

        $deliveries = Auth::user()?->can('integrations.deliveries.view')
            ? $subscription->deliveries()
                ->when(in_array($status, ['pending', 'in_flight', 'retrying', 'succeeded', 'dead', 'skipped'], true), fn ($query) => $query->where('status', $status))
                ->with('event:id,event_type')
                ->orderByDesc('feed_position')->orderByDesc('generation')
                ->paginate(25)->withQueryString()
                ->through(fn (WebhookDelivery $delivery): array => $this->deliveryRow($delivery))
            : null;

        return Inertia::render('Integrations/Show', [
            'subscription' => $this->subscriptionRow($subscription) + [
                'callback_url' => $subscription->callback_url,
                'event_types' => $subscription->event_types,
                'secret_rotated_at' => $subscription->secret_rotated_at?->toDateTimeString(),
                'previous_secret_expires_at' => $subscription->previous_secret_expires_at?->isFuture() ? $subscription->previous_secret_expires_at->toDateTimeString() : null,
                'start_after_position' => $subscription->start_after_position,
            ],
            'deliveries' => $deliveries,
            'statusFilter' => $status,
            'replays' => IntegrationReplay::query()->where('webhook_subscription_id', $subscription->getKey())->with('initiator:id,name')->latest()->limit(10)->get()
                ->map(fn (IntegrationReplay $replay): array => [
                    'id' => $replay->getKey(), 'by' => $replay->initiator?->name ?? $replay->initiated_by_application_id, 'reason' => $replay->reason,
                    'event_count' => $replay->event_count, 'created_at' => $replay->created_at?->toDateTimeString(),
                ])->all(),
            'can' => $this->can(),
        ]);
    }

    public function edit(WebhookSubscription $subscription): Response
    {
        $this->authorizePermission('integrations.manage');

        return Inertia::render('Integrations/Form', [
            'subscription' => [
                'id' => $subscription->getKey(), 'name' => $subscription->name, 'callback_url' => $subscription->callback_url,
                'event_types' => $subscription->event_types, 'external_application_id' => $subscription->external_application_id,
            ],
            'applications' => $this->applicationOptions(),
        ]);
    }

    public function update(Request $request, WebhookSubscription $subscription): RedirectResponse
    {
        $this->authorizePermission('integrations.manage');
        $data = $this->validated($request, false);
        $this->subscriptions->update($subscription, $data['name'], $data['callback_url'], $data['event_types'], $request->user());

        return to_route('integrations.subscriptions.show', $subscription)->with('flash', ['message' => __('integrations.flash.updated'), 'type' => 'success']);
    }

    public function rotateSecret(Request $request, WebhookSubscription $subscription): RedirectResponse
    {
        $this->authorizePermission('integrations.manage');
        $secret = $this->subscriptions->rotateSecret($subscription, $request->user());

        return back()->with('flash', ['message' => __('integrations.flash.rotated'), 'type' => 'success', 'generated_secret' => $secret]);
    }

    public function setStatus(Request $request, WebhookSubscription $subscription): RedirectResponse
    {
        $this->authorizePermission('integrations.manage');
        $data = $request->validate(['status' => ['required', 'in:active,disabled']]);
        $this->subscriptions->setStatus($subscription, $data['status'], $request->user());

        return back()->with('flash', ['message' => __('integrations.flash.status_'.$data['status']), 'type' => 'success']);
    }

    public function test(WebhookSubscription $subscription, WebhookDeliverer $deliverer): RedirectResponse
    {
        $this->authorizePermission('integrations.manage');
        $result = $deliverer->sendTest($subscription);

        return back()->with('flash', [
            'message' => $result['ok']
                ? __('integrations.flash.test_ok', ['status' => $result['http_status'], 'ms' => $result['latency_ms']])
                : __('integrations.flash.test_failed', ['status' => $result['http_status'] ?? '—', 'category' => $result['error_category'] ?? '—']),
            'type' => $result['ok'] ? 'success' : 'error',
        ]);
    }

    public function replay(Request $request, WebhookSubscription $subscription, ReplayService $replays): RedirectResponse
    {
        $this->authorizePermission('integrations.replay');
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'mode' => ['required', 'in:failed,range,events'],
            'from' => ['nullable', 'required_if:mode,range', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'event_ids' => ['nullable', 'required_if:mode,events', 'string', 'max:20000'],
        ]);

        $criteria = match ($data['mode']) {
            'failed' => ['only_failed' => true],
            'range' => array_filter(['from' => $data['from'] ?? null, 'to' => $data['to'] ?? null]),
            default => ['event_ids' => array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $data['event_ids']) ?: [])))],
        };

        $replay = $replays->replay($subscription, $criteria, $data['reason'], $request->user());

        return back()->with('flash', ['message' => __('integrations.flash.replayed', ['count' => $replay->event_count]), 'type' => 'success']);
    }

    public function event(Request $request, string $eventId, ChangeVisibility $visibility): Response
    {
        $this->authorizePermission('integrations.payload.view');
        $event = IntegrationEvent::query()->where('event_id', $eventId)->firstOrFail();

        $this->audit->execute(AuditEventType::IntegrationPayloadViewed, $request->user(), reason: 'Integration event payload viewed: '.$event->event_id.' ('.$event->event_type.')', request: $request);

        return Inertia::render('Integrations/Event', [
            'event' => ChangeVisibility::envelope($event, $event->payload ?? [], $event->organization_id) + [
                'status' => $event->status,
                'feed_position' => $event->feed_position,
                'related_organization_uuid' => $event->related_organization_id,
                'error' => $event->error,
                'published_at' => $event->published_at?->toDateTimeString(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'external_application_id' => $creating ? ['required', 'string', 'exists:external_applications,id'] : ['nullable'],
            'name' => ['required', 'string', 'max:120'],
            'callback_url' => ['required', 'string', 'max:2048'],
            'event_types' => ['required', 'array', 'min:1'],
            'event_types.*' => ['string', 'in:'.implode(',', EventCatalog::types())],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function applicationOptions(): array
    {
        return ExternalApplication::query()->orderBy('name')->get(['id', 'name', 'code', 'status', 'allowed_scopes'])
            ->map(fn (ExternalApplication $application): array => [
                'value' => (string) $application->getKey(),
                'label' => $application->name.' ('.$application->code.')',
                'status' => $application->status,
                'event_types' => EventCatalog::typesForScopes($application->grantableScopes()),
            ])->all();
    }

    /** @return array<string, mixed> */
    private function subscriptionRow(WebhookSubscription $subscription): array
    {
        return [
            'id' => $subscription->getKey(),
            'name' => $subscription->name,
            'application' => $subscription->application?->name,
            'application_code' => $subscription->application?->code,
            'host' => parse_url($subscription->callback_url, PHP_URL_HOST),
            'event_type_count' => count($subscription->event_types ?? []),
            'status' => $subscription->status,
            'health' => $subscription->health(),
            'consecutive_failures' => $subscription->consecutive_failures,
            'last_success_at' => $subscription->last_success_at?->toDateTimeString(),
            'last_failure_at' => $subscription->last_failure_at?->toDateTimeString(),
            'last_failure_category' => $subscription->last_failure_category,
        ];
    }

    /** @return array<string, mixed> */
    private function deliveryRow(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->getKey(),
            'event_id' => $delivery->event_id,
            'event_type' => $delivery->event?->event_type,
            'generation' => $delivery->generation,
            'status' => $delivery->status,
            'attempts' => $delivery->attempt_count,
            'last_http_status' => $delivery->last_http_status,
            'last_error_category' => $delivery->last_error_category,
            'last_latency_ms' => $delivery->last_latency_ms,
            'next_attempt_at' => $delivery->next_attempt_at?->toDateTimeString(),
            'last_attempt_at' => $delivery->last_attempt_at?->toDateTimeString(),
            'created_at' => $delivery->created_at?->toDateTimeString(),
        ];
    }

    /** @return array<string, bool> */
    private function can(): array
    {
        $user = Auth::user();

        return [
            'manage' => $user?->can('integrations.manage') ?? false,
            'deliveries' => $user?->can('integrations.deliveries.view') ?? false,
            'replay' => $user?->can('integrations.replay') ?? false,
            'payload' => $user?->can('integrations.payload.view') ?? false,
        ];
    }

    private function authorizePermission(string $permission): void
    {
        abort_unless(Auth::user()?->can($permission) ?? false, 403);
    }
}
