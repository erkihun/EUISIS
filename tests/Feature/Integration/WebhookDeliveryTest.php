<?php

declare(strict_types=1);

use App\Actions\IdCards\RevokeCardAction;
use App\Models\Integration\IntegrationEvent;
use App\Models\Integration\IntegrationReplay;
use App\Models\Integration\WebhookDelivery;
use App\Models\Integration\WebhookDeliveryAttempt;
use App\Services\Integration\Webhooks\CallbackUrlPolicy;
use App\Services\Integration\Webhooks\DeliveryDispatcher;
use App\Services\Integration\Webhooks\ReplayService;
use App\Services\Integration\Webhooks\RetryPolicy;
use App\Services\Integration\Webhooks\SubscriptionService;
use App\Services\Integration\Webhooks\WebhookSigner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\IntegrationScenario;

/**
 * Signed webhook delivery: signature scheme, retry classification, dead
 * deliveries and replay with the original event id, secret rotation, SSRF
 * guard, scope-bound subscriptions and partner-facing endpoints.
 */
beforeEach(function (): void {
    $this->s = IntegrationScenario::build();
    IntegrationScenario::publish();
    [$this->app_, $this->token] = IntegrationScenario::application(['id_cards.verify', 'webhooks.manage', 'webhooks.replay']);
    [$this->subscription, $this->secret] = IntegrationScenario::subscription($this->app_, ['id_card.revoked', 'id_card.status_changed']);
});

function revokeAndPublish($test): IntegrationEvent
{
    app(RevokeCardAction::class)->execute($test->s->woredaCard->fresh(), IntegrationScenario::admin(), 'lost');
    IntegrationScenario::publish();

    return IntegrationEvent::query()->where('event_type', 'id_card.revoked')->latest('id')->firstOrFail();
}

// ── Signature ───────────────────────────────────────────────────────────

it('verifies a correct signature and rejects tampering, a wrong secret and a stale timestamp', function (): void {
    $now = 1_800_000_000;
    $header = WebhookSigner::signatureHeader(['whsec_good'], $now, 'evt-1', '{"a":1}');

    expect(WebhookSigner::verify('whsec_good', $header, 'evt-1', '{"a":1}', $now))->toBeTrue()
        ->and(WebhookSigner::verify('whsec_good', $header, 'evt-1', '{"a":2}', $now))->toBeFalse()
        ->and(WebhookSigner::verify('whsec_good', $header, 'evt-2', '{"a":1}', $now))->toBeFalse()
        ->and(WebhookSigner::verify('whsec_bad', $header, 'evt-1', '{"a":1}', $now))->toBeFalse()
        ->and(WebhookSigner::verify('whsec_good', $header, 'evt-1', '{"a":1}', $now + 301))->toBeFalse()
        ->and(WebhookSigner::verify('whsec_good', 'v1=abc', 'evt-1', '{"a":1}', $now))->toBeFalse();
});

it('delivers a card revocation signed, minimised and promptly', function (): void {
    Http::fake(['93.184.216.34/*' => Http::response('', 204)]);
    $event = revokeAndPublish($this);

    $counts = app(DeliveryDispatcher::class)->run();

    expect($counts)->toBe(['succeeded' => 1]);
    Http::assertSent(function (ClientRequest $request) use ($event): bool {
        $body = $request->body();
        $data = json_decode($body, true);

        return $request->hasHeader('Addis-Event-Id', $event->event_id)
            && WebhookSigner::verify($this->secret, $request->header('Addis-Signature')[0], $event->event_id, $body)
            && $data['event_type'] === 'id_card.revoked'
            && $data['data']['card_uuid'] === $this->s->woredaCard->public_card_uuid
            && ! str_contains($body, 'national_id') && ! str_contains($body, 'Hana');
    });

    $delivery = WebhookDelivery::query()->where('event_id', $event->event_id)->sole();
    expect($delivery->status)->toBe('succeeded')->and($delivery->attempts()->count())->toBe(1)
        ->and($this->subscription->fresh()->health())->toBe('healthy');
});

it('classifies outcomes: success, retryable and permanent', function (?int $status, ?string $error, string $outcome): void {
    expect(app(RetryPolicy::class)->classify($status, $error)[0])->toBe($outcome);
})->with([
    '200' => [200, null, 'succeeded'],
    '204' => [204, null, 'succeeded'],
    '429' => [429, null, 'retry'],
    '500' => [500, null, 'retry'],
    '503' => [503, null, 'retry'],
    'timeout' => [null, 'timeout', 'retry'],
    'network' => [null, 'network_error', 'retry'],
    '400' => [400, null, 'permanent'],
    '401' => [401, null, 'permanent'],
    '403' => [403, null, 'permanent'],
    '404' => [404, null, 'permanent'],
    '302' => [302, null, 'permanent'],
]);

it('retries a 500 and a timeout with backoff, then succeeds', function (): void {
    Http::fakeSequence('93.184.216.34/*')
        ->push('', 500)
        ->pushFailedConnection()
        ->push('', 200);
    $event = revokeAndPublish($this);
    $dispatcher = app(DeliveryDispatcher::class);

    $dispatcher->run();
    $delivery = WebhookDelivery::query()->where('event_id', $event->event_id)->sole();
    expect($delivery->status)->toBe('retrying')->and($delivery->next_attempt_at->isFuture())->toBeTrue();

    $this->travelTo($delivery->next_attempt_at->copy()->addSecond());
    $dispatcher->run();
    expect($delivery->fresh()->status)->toBe('retrying')->and($delivery->fresh()->last_error_category)->toBe('network_error');

    $this->travelTo($delivery->fresh()->next_attempt_at->copy()->addSecond());
    $dispatcher->run();
    expect($delivery->fresh()->status)->toBe('succeeded')
        ->and(WebhookDeliveryAttempt::query()->where('webhook_delivery_id', $delivery->id)->pluck('outcome')->all())->toBe(['retry', 'retry', 'succeeded']);
});

it('marks a 401 dead at once without retrying', function (): void {
    Http::fake(['93.184.216.34/*' => Http::response('', 401)]);
    $event = revokeAndPublish($this);

    app(DeliveryDispatcher::class)->run();

    $delivery = WebhookDelivery::query()->where('event_id', $event->event_id)->sole();
    expect($delivery->status)->toBe('dead')->and($delivery->last_error_category)->toBe('partner_auth_rejected')->and($delivery->attempt_count)->toBe(1);
});

it('never follows a redirect', function (): void {
    Http::fake(['93.184.216.34/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);
    revokeAndPublish($this);

    app(DeliveryDispatcher::class)->run();

    Http::assertSentCount(1);
    expect(WebhookDelivery::query()->latest('created_at')->first()->last_error_category)->toBe('redirect_refused');
});

it('gives up after the configured attempts and replays with the original event id', function (): void {
    config(['integration.webhooks.max_attempts' => 2]);
    Http::fakeSequence('93.184.216.34/*')->push('', 503)->push('', 503)->push('', 200);
    $event = revokeAndPublish($this);
    $dispatcher = app(DeliveryDispatcher::class);

    $dispatcher->run();
    $this->travel(2)->hours();
    $dispatcher->run();

    $delivery = WebhookDelivery::query()->where('event_id', $event->event_id)->sole();
    expect($delivery->status)->toBe('dead')->and($delivery->attempt_count)->toBe(2);

    // Partner fixed (the next response is 200); an administrator replays the failed deliveries.
    $replay = app(ReplayService::class)->replay($this->subscription, ['only_failed' => true], 'Partner endpoint restored', IntegrationScenario::admin());
    expect($replay->event_count)->toBe(1);
    $dispatcher->run();

    $generations = WebhookDelivery::query()->where('event_id', $event->event_id)->orderBy('generation')->get();
    expect($generations->pluck('generation')->all())->toBe([0, 1])
        ->and($generations->last()->status)->toBe('succeeded')
        ->and($generations->last()->replay_id)->toBe($replay->id)
        ->and(IntegrationEvent::query()->where('event_id', $event->event_id)->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('event_type', 'integration.replay_requested')->exists())->toBeTrue();
});

it('lets a consumer process a duplicate delivery once by event id', function (): void {
    $received = [];
    Http::fake(function (ClientRequest $request) use (&$received) {
        $received[] = $request->header('Addis-Event-Id')[0];

        return Http::response('', 200);
    });
    $event = revokeAndPublish($this);
    app(DeliveryDispatcher::class)->run();
    app(ReplayService::class)->replay($this->subscription, ['event_ids' => [$event->event_id]], 'duplicate drill', IntegrationScenario::admin());
    app(DeliveryDispatcher::class)->run();

    $processed = [];
    foreach ($received as $eventId) {
        $processed[$eventId] = ($processed[$eventId] ?? 0) + 1;
    }
    expect($received)->toBe([$event->event_id, $event->event_id])->and(array_keys($processed))->toBe([$event->event_id]);
});

it('rotates the secret, signs with both during the overlap, and never stores plaintext', function (): void {
    $old = $this->secret;
    $new = app(SubscriptionService::class)->rotateSecret($this->subscription->fresh(), IntegrationScenario::admin());
    $subscription = $this->subscription->fresh();

    $raw = DB::table('webhook_subscriptions')->where('id', $subscription->id)->first();
    expect($raw->secret)->not->toContain($new)->and($raw->previous_secret)->not->toContain($old)
        ->and($subscription->toArray())->not->toHaveKey('secret');

    $header = WebhookSigner::signatureHeader($subscription->signingSecrets(), time(), 'evt-r', 'body');
    expect(WebhookSigner::verify($new, $header, 'evt-r', 'body'))->toBeTrue()
        ->and(WebhookSigner::verify($old, $header, 'evt-r', 'body'))->toBeTrue();

    $this->travel(25)->hours();
    expect($subscription->fresh()->signingSecrets())->toBe([$new]);
});

it('refuses unsafe callback destinations', function (string $url): void {
    expect(app(CallbackUrlPolicy::class)->check($url))->not->toBeNull();
})->with([
    'plain http' => ['http://93.184.216.34/hook'],
    'loopback' => ['https://127.0.0.1/hook'],
    'localhost' => ['https://localhost/hook'],
    'metadata' => ['https://169.254.169.254/latest/meta-data'],
    'ipv6 loopback' => ['https://[::1]/hook'],
    'ipv4-mapped loopback' => ['https://[::ffff:127.0.0.1]/hook'],
    'private 10/8' => ['https://10.0.0.5/hook'],
    'private 192.168' => ['https://192.168.1.10/hook'],
    'private 172.16' => ['https://172.16.0.1/hook'],
    'cgnat' => ['https://100.64.0.1/hook'],
    'credentials' => ['https://user:pass@93.184.216.34/hook'],
    'odd port' => ['https://93.184.216.34:22/hook'],
    '.internal' => ['https://billing.internal/hook'],
]);

it('accepts a public HTTPS destination and an explicitly allowed internal host', function (): void {
    expect(app(CallbackUrlPolicy::class)->check('https://93.184.216.34/hook'))->toBeNull();

    config(['integration.webhooks.allowed_internal_hosts' => ['10.0.0.5']]);
    expect(app(CallbackUrlPolicy::class)->check('https://10.0.0.5/hook'))->toBeNull();
});

it('refuses a subscription to event types the application has no scope for', function (): void {
    expect(fn () => IntegrationScenario::subscription($this->app_, ['employee.updated']))->toThrow(ValidationException::class);
    expect(fn () => IntegrationScenario::subscription($this->app_, ['id_card.revoked'], 'https://127.0.0.1/hook'))->toThrow(ValidationException::class);
});

it('stops delivering when the application loses the scope or the subscription is disabled', function (): void {
    Http::fake(['93.184.216.34/*' => Http::response('', 200)]);
    revokeAndPublish($this);
    $this->app_->forceFill(['allowed_scopes' => ['webhooks.manage']])->save();

    app(DeliveryDispatcher::class)->run();

    Http::assertNothingSent();
    expect(WebhookDelivery::query()->latest('created_at')->first()->status)->toBe('skipped');
});

it('keeps the master-data change when the partner is down', function (): void {
    Http::fake(['93.184.216.34/*' => fn () => throw new ConnectionException('Connection refused')]);

    $event = revokeAndPublish($this);
    app(DeliveryDispatcher::class)->run();

    expect($this->s->woredaCard->fresh()->status->value)->toBe('revoked')
        ->and(WebhookDelivery::query()->where('event_id', $event->event_id)->sole()->status)->toBe('retrying');
});

it('re-delivers a delivery whose worker died mid-flight', function (): void {
    Http::fake(['93.184.216.34/*' => Http::response('', 200)]);
    $event = revokeAndPublish($this);
    $delivery = WebhookDelivery::query()->where('event_id', $event->event_id)->sole();
    $delivery->forceFill(['status' => 'in_flight', 'locked_until' => now()->subMinute()])->save();

    app(DeliveryDispatcher::class)->run();

    expect($delivery->fresh()->status)->toBe('succeeded');
});

it('holds a later event for the same aggregate until the earlier one is settled', function (): void {
    Http::fake(['93.184.216.34/*' => Http::sequence()->push('', 500)->push('', 200)->push('', 200)]);
    $card = $this->s->woredaCard;
    $card->update(['status' => 'suspended']);
    IntegrationScenario::publish();
    $card->update(['status' => 'damaged']);
    IntegrationScenario::publish();

    app(DeliveryDispatcher::class)->run();
    $deliveries = WebhookDelivery::query()->orderBy('feed_position')->get();
    expect($deliveries->pluck('status')->all())->toBe(['retrying', 'pending']);

    $this->travel(2)->hours();
    app(DeliveryDispatcher::class)->run();
    expect(WebhookDelivery::query()->orderBy('feed_position')->pluck('status')->all())->toBe(['succeeded', 'succeeded']);
});

it('shows a partner only its own subscriptions and deliveries, and lets it replay its own', function (): void {
    Http::fake(['93.184.216.34/*' => Http::response('', 500)]);
    $event = revokeAndPublish($this);
    app(DeliveryDispatcher::class)->run();

    $this->withToken($this->token)->getJson('/api/v1/webhook-subscriptions')->assertOk()
        ->assertJsonPath('data.0.subscription_id', $this->subscription->id)
        ->assertJsonMissingPath('data.0.secret');
    $this->withToken($this->token)->getJson('/api/v1/webhook-subscriptions/'.$this->subscription->id.'/deliveries')->assertOk()
        ->assertJsonPath('data.0.event_id', $event->event_id);

    [$intruder, $intruderToken] = IntegrationScenario::application(['webhooks.manage', 'webhooks.replay', 'id_cards.verify']);
    app('auth')->forgetGuards();
    $this->withToken($intruderToken)->getJson('/api/v1/webhook-subscriptions/'.$this->subscription->id.'/deliveries')->assertNotFound();
    $this->withToken($intruderToken)->postJson('/api/v1/webhook-subscriptions/'.$this->subscription->id.'/replay', ['reason' => 'x', 'event_ids' => [$event->event_id]])->assertNotFound();

    [, $noReplay] = IntegrationScenario::application(['webhooks.manage']);
    app('auth')->forgetGuards();
    $this->withToken($noReplay)->postJson('/api/v1/webhook-subscriptions/'.$this->subscription->id.'/replay', ['reason' => 'x'])->assertForbidden();

    app('auth')->forgetGuards();
    $this->withToken($this->token)->postJson('/api/v1/webhook-subscriptions/'.$this->subscription->id.'/replay', ['reason' => 'Missed while down', 'event_ids' => [$event->event_id]])
        ->assertStatus(202)->assertJsonPath('event_count', 1);
    expect(IntegrationReplay::query()->where('initiated_by_application_id', $this->app_->id)->exists())->toBeTrue();
});

it('writes no delivery while webhooks are switched off (shadow mode)', function (): void {
    config(['integration.webhooks.enabled' => false]);
    revokeAndPublish($this);

    expect(WebhookDelivery::query()->count())->toBe(0)
        ->and(IntegrationEvent::query()->where('event_type', 'id_card.revoked')->whereNotNull('feed_position')->exists())->toBeTrue();
});
