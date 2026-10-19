<?php

declare(strict_types=1);

use App\Actions\IdCards\RevokeCardAction;
use App\Models\Integration\IntegrationEvent;
use App\Models\Integration\WebhookDelivery;
use App\Models\Integration\WebhookSubscription;
use App\Models\User;
use App\Services\Integration\IntegrationMonitor;
use App\Models\IntelligenceIncident;
use App\Models\SystemSetting;
use App\Services\Intelligence\System\Detection\DetectionEngine;
use App\Services\Intelligence\System\Telemetry\SignalRepository;
use App\Services\SystemSettings\SystemSettingsService;
use App\Services\Intelligence\System\Telemetry\Metrics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\Support\IntegrationScenario;

/**
 * Integration administration: permissions, secret shown once, delivery
 * history without payloads, audited replay and payload inspection, real KPIs,
 * retention that never loses unpublished events.
 */
beforeEach(function (): void {
    $this->s = IntegrationScenario::build();
    IntegrationScenario::publish();
    foreach (['integrations.view', 'integrations.manage', 'integrations.deliveries.view', 'integrations.replay', 'integrations.payload.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    [$this->partner] = IntegrationScenario::application(['id_cards.verify', 'employees.basic_read']);
});

function integrationUser(array $permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

it('keeps the integration area from users without the permission', function (): void {
    $this->actingAs(User::factory()->create())->get(route('integrations.index'))->assertForbidden();
    $viewer = integrationUser(['integrations.view']);
    $this->actingAs($viewer)->get(route('integrations.subscriptions.create'))->assertForbidden();
    $this->actingAs($viewer)->post(route('integrations.subscriptions.store'), [])->assertForbidden();
});

it('shows real KPIs and a subscription list without secrets', function (): void {
    [$subscription, $secret] = IntegrationScenario::subscription($this->partner, ['id_card.revoked']);

    $this->actingAs(integrationUser(['integrations.view']))->get(route('integrations.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Integrations/Index')
            ->where('kpis.active_subscriptions', 1)
            ->where('kpis.pending_events', 0)
            ->where('kpis.feed_position', IntegrationEvent::query()->max('feed_position'))
            ->where('subscriptions.0.id', $subscription->id)
            ->where('subscriptions.0.health', 'unknown')
            ->where('failedDeliveries', [])
            ->etc())
        ->assertDontSee($secret);
});

it('creates a subscription and reveals its secret exactly once', function (): void {
    $manager = integrationUser(['integrations.view', 'integrations.manage']);

    $response = $this->actingAs($manager)->post(route('integrations.subscriptions.store'), [
        'external_application_id' => $this->partner->id, 'name' => 'Cafeteria pilot',
        'callback_url' => 'https://93.184.216.34/addis', 'event_types' => ['id_card.revoked', 'employee.status_changed'],
    ]);
    $subscription = WebhookSubscription::query()->where('name', 'Cafeteria pilot')->sole();
    $response->assertRedirect(route('integrations.subscriptions.show', $subscription))->assertSessionHas('flash.generated_secret');

    $this->actingAs($manager)->get(route('integrations.subscriptions.show', $subscription))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Integrations/Show')->missing('subscription.secret')->etc());

    $this->actingAs($manager)->post(route('integrations.subscriptions.store'), [
        'external_application_id' => $this->partner->id, 'name' => 'Bad', 'callback_url' => 'https://169.254.169.254/x', 'event_types' => ['id_card.revoked'],
    ])->assertSessionHasErrors('callback_url');
});

it('requires the replay permission and audits every replay', function (): void {
    [$subscription] = IntegrationScenario::subscription($this->partner, ['id_card.revoked']);
    Http::fake(['93.184.216.34/*' => Http::response('', 404)]);
    app(RevokeCardAction::class)->execute($this->s->woredaCard, IntegrationScenario::admin(), 'lost');
    IntegrationScenario::publish();
    $this->artisan('integrations:deliver')->assertSuccessful();

    $payload = ['mode' => 'failed', 'reason' => 'Partner fixed the endpoint'];
    $this->actingAs(integrationUser(['integrations.view', 'integrations.manage']))->post(route('integrations.subscriptions.replay', $subscription), $payload)->assertForbidden();

    $this->actingAs(integrationUser(['integrations.view', 'integrations.replay']))->post(route('integrations.subscriptions.replay', $subscription), $payload)->assertRedirect();
    expect(WebhookDelivery::query()->where('webhook_subscription_id', $subscription->id)->where('generation', 1)->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('event_type', 'integration.replay_requested')->count())->toBe(1);
});

it('shows delivery history only with its permission, and payloads only with theirs, audited', function (): void {
    [$subscription] = IntegrationScenario::subscription($this->partner, ['id_card.revoked']);
    Http::fake(['93.184.216.34/*' => Http::response('', 200)]);
    app(RevokeCardAction::class)->execute($this->s->woredaCard, IntegrationScenario::admin(), 'lost');
    IntegrationScenario::publish();
    $this->artisan('integrations:deliver')->assertSuccessful();
    $event = IntegrationEvent::query()->where('event_type', 'id_card.revoked')->sole();

    $this->actingAs(integrationUser(['integrations.view']))->get(route('integrations.subscriptions.show', $subscription))
        ->assertInertia(fn (Assert $page) => $page->where('deliveries', null)->etc());
    $this->actingAs(integrationUser(['integrations.view', 'integrations.deliveries.view']))->get(route('integrations.subscriptions.show', $subscription))
        ->assertInertia(fn (Assert $page) => $page->where('deliveries.data.0.event_id', $event->event_id)->where('deliveries.data.0.status', 'succeeded')->missing('deliveries.data.0.payload')->etc());

    $this->actingAs(integrationUser(['integrations.view', 'integrations.deliveries.view']))->get(route('integrations.events.show', $event->event_id))->assertForbidden();
    $this->actingAs(integrationUser(['integrations.payload.view']))->get(route('integrations.events.show', $event->event_id))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('event.data.card_uuid', $this->s->woredaCard->public_card_uuid)->etc());
    expect(DB::table('audit_logs')->where('event_type', 'integration.payload_viewed')->count())->toBe(1);
});

it('disables a subscription without touching its history or the application', function (): void {
    [$subscription] = IntegrationScenario::subscription($this->partner, ['id_card.revoked']);
    $this->actingAs(integrationUser(['integrations.view', 'integrations.manage']))
        ->post(route('integrations.subscriptions.status', $subscription), ['status' => 'disabled'])->assertRedirect();

    expect($subscription->fresh()->status)->toBe('disabled')->and($this->partner->fresh()->status)->toBe('active');

    app(RevokeCardAction::class)->execute($this->s->woredaCard, IntegrationScenario::admin(), 'lost');
    IntegrationScenario::publish();
    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('sends a clearly synthetic test event', function (): void {
    [$subscription] = IntegrationScenario::subscription($this->partner, ['id_card.revoked']);
    Http::fake(['93.184.216.34/*' => Http::response('', 200)]);

    $this->actingAs(integrationUser(['integrations.view', 'integrations.manage']))->post(route('integrations.subscriptions.test', $subscription))->assertRedirect();

    Http::assertSent(fn ($request) => $request->header('Addis-Event-Type')[0] === 'webhook.test' && json_decode($request->body(), true)['data']['test'] === true);
    expect(IntegrationEvent::query()->where('event_type', 'webhook.test')->exists())->toBeFalse();
});

it('counts backlog, oldest pending and dead deliveries from real rows', function (): void {
    $this->travel(-10)->minutes();
    $this->s->bureau->update(['name_en' => 'Backlogged']);
    $this->travelBack();

    $snapshot = app(IntegrationMonitor::class)->snapshot();
    expect($snapshot['pending_events'])->toBe(1)
        ->and($snapshot['oldest_pending_seconds'])->toBeGreaterThanOrEqual(590)
        ->and($snapshot['dead_deliveries'])->toBe(0)
        ->and($snapshot['webhooks_enabled'])->toBe('on');
});

it('opens no outbox incident without a configured limit, and one when the limit is passed', function (): void {
    config(['cache.default' => 'array']);
    \Illuminate\Support\Facades\Notification::fake();
    $now = \Carbon\CarbonImmutable::now();
    app(SignalRepository::class)->write([
        ['metric' => Metrics::INTEGRATION_OUTBOX_OLDEST_SECONDS, 'component' => 'integrations', 'dimension' => null, 'value' => 1200, 'bucket_start' => $now->subMinutes(5)],
        ['metric' => Metrics::INTEGRATION_OUTBOX_PENDING, 'component' => 'integrations', 'dimension' => null, 'value' => 40, 'bucket_start' => $now->subMinutes(5)],
    ]);

    app(DetectionEngine::class)->run($now);
    expect(IntelligenceIncident::query()->where('rule', 'event_integration')->count())->toBe(0);

    SystemSetting::query()->updateOrCreate(['group' => 'intelligence', 'key' => 'integration_outbox_oldest_minutes'], ['value' => '10', 'type' => 'integer', 'label_en' => 'x', 'label_am' => 'x']);
    app(SystemSettingsService::class)->clearCache();
    app(DetectionEngine::class)->run($now);

    $incident = IntelligenceIncident::query()->where('rule', 'event_integration')->sole();
    expect($incident->severity)->toBe('high')->and($incident->component)->toBe('integrations');
});

it('purges nothing without a retention policy, and never unpublished events', function (): void {
    $this->artisan('integrations:purge')->expectsOutputToContain('NEEDS_POLICY_DECISION_EVENT_RETENTION')->assertSuccessful();

    config(['integration.retention.events_days' => 1]);
    $this->s->bureau->update(['name_en' => 'Still pending']);
    $pending = IntegrationEvent::query()->where('status', 'pending')->count();
    $published = IntegrationEvent::query()->where('status', 'published')->count();
    $this->travel(3)->days();

    $this->artisan('integrations:purge')->assertSuccessful();

    expect(IntegrationEvent::query()->where('status', 'pending')->count())->toBe($pending)
        ->and(IntegrationEvent::query()->where('status', 'published')->count())->toBe(0)
        ->and($published)->toBeGreaterThan(0);
});
