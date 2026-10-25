<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\ApiRequestLog;
use App\Models\OutboxEvent;
use App\Models\WebhookDelivery;
use App\Services\WebhookDeliverer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** API clients, the versioned integration API, and signed webhook delivery of Core events. */
class CoreIntegrationTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
    }

    /**
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $extra
     * @return array{0: string, 1: string} uuid, secret
     */
    private function client(array $scopes, array $extra = []): array
    {
        $this->actingAsCore('core_admin');
        $response = $this->postJson('/api/v1/api-clients', ['name' => 'Cafeteria', 'scopes' => $scopes] + $extra + ['scope_type' => isset($extra['owner_organization_id']) ? 'subtree' : 'citywide'])->assertCreated();
        Auth::forgetGuards();

        return [$response->json('data.uuid'), $response->json('secret')];
    }

    /** @return array<string, string> */
    private function bearer(string $secret): array
    {
        Auth::forgetGuards();

        return ['Authorization' => 'Bearer '.$secret];
    }

    public function test_a_client_reads_only_its_scopes_and_organizations_with_minimal_fields(): void
    {
        [, $secret] = $this->client(['employees:basic-read', 'cards:verify'], ['owner_organization_id' => $this->bureauA->id]);
        $other = $this->placedEmployee('EMP-0002', $this->positionB, '2024-01-01');

        $list = $this->getJson('/api/v1/integration/employees', $this->bearer($secret))->assertOk();
        $this->assertSame([$this->employee->id], array_column($list->json('data'), 'id'));
        $row = $list->json('data.0');
        foreach (['national_id', 'national_id_hash', 'phone', 'email', 'date_of_birth', 'photo_path', 'first_name'] as $private) {
            $this->assertArrayNotHasKey($private, $row);
        }
        $this->assertSame($this->bureauA->id, $row['current_assignment']['organization_id']);

        $this->getJson('/api/v1/integration/employees/'.$other->id, $this->bearer($secret))->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->getJson('/api/v1/integration/organizations', $this->bearer($secret))->assertForbidden()->assertJsonPath('code', 'SCOPE_DENIED');
        $this->getJson('/api/v1/integration/employees?per_page=500', $this->bearer($secret))->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        // A client token is not a person's sign-in.
        $this->getJson('/api/v1/dashboard', $this->bearer($secret))->assertForbidden();
    }

    public function test_card_verification_gives_denial_codes_and_names_the_holder_only_with_the_employee_scope(): void
    {
        [, $withHolder] = $this->client(['cards:verify', 'employees:basic-read']);
        [, $codeOnly] = $this->client(['cards:verify']);

        $this->postJson('/api/v1/integration/cards/verify', ['card_uuid' => $this->card->public_card_uuid], $this->bearer($withHolder))->assertOk()
            ->assertJsonPath('valid', true)->assertJsonPath('code', null)->assertJsonPath('holder.employee_number', 'EMP-0001');
        $this->postJson('/api/v1/integration/cards/verify', ['card_uuid' => $this->card->public_card_uuid], $this->bearer($codeOnly))->assertOk()
            ->assertJsonPath('valid', true)->assertJsonPath('holder', null);

        $this->card->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->postJson('/api/v1/integration/cards/verify', ['card_uuid' => $this->card->public_card_uuid], $this->bearer($codeOnly))
            ->assertOk()->assertJsonPath('valid', false)->assertJsonPath('code', 'CARD_REVOKED');
        $this->postJson('/api/v1/integration/cards/verify', ['card_uuid' => (string) Str::uuid()], $this->bearer($codeOnly))
            ->assertNotFound()->assertJsonPath('code', 'CARD_NOT_FOUND');
    }

    public function test_a_revoked_expired_or_off_network_client_is_refused_and_every_call_is_logged(): void
    {
        [$uuid, $secret] = $this->client(['organizations:read']);
        $this->getJson('/api/v1/integration/organizations', $this->bearer($secret))->assertOk()->assertJsonCount(3, 'data');

        ApiClient::query()->where('uuid', $uuid)->update(['allowed_ips' => json_encode(['10.0.0.0/8'])]);
        $this->getJson('/api/v1/integration/organizations', $this->bearer($secret))->assertForbidden()->assertJsonPath('code', 'IP_NOT_ALLOWED');
        ApiClient::query()->where('uuid', $uuid)->update(['allowed_ips' => json_encode(['127.0.0.0/8'])]);
        $this->getJson('/api/v1/integration/organizations', $this->bearer($secret))->assertOk();

        $this->actingAsCore('core_admin');
        $this->postJson("/api/v1/api-clients/{$uuid}/revoke", ['reason' => 'Contract ended'])->assertOk()->assertJsonPath('data.revocation_reason', 'Contract ended');
        $this->getJson('/api/v1/integration/organizations', $this->bearer($secret))->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');

        $this->assertSame(3, ApiRequestLog::query()->count());
        $this->assertSame(1, ApiRequestLog::query()->where('denial_code', 'IP_NOT_ALLOWED')->count());
        $this->actingAsCore('core_admin');
        $this->getJson('/api/v1/api-logs?errors=1')->assertOk()->assertJsonPath('total', 1);
        $this->assertDatabaseHas('core_audit_logs', ['action' => 'api_client.revoked', 'subject_id' => $uuid]);
    }

    public function test_a_rotated_secret_replaces_the_old_one_and_the_rate_limit_applies(): void
    {
        [$uuid, $old] = $this->client(['positions:read'], ['rate_limit_per_minute' => 10]);
        $this->actingAsCore('core_admin');
        $new = $this->postJson("/api/v1/api-clients/{$uuid}/rotate")->assertOk()->json('secret');

        $this->getJson('/api/v1/integration/positions', $this->bearer($old))->assertUnauthorized();
        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/v1/integration/positions', $this->bearer($new))->assertOk();
        }
        $this->getJson('/api/v1/integration/positions', $this->bearer($new))->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
    }

    public function test_only_city_wide_administrators_manage_clients_and_scopes_are_checked(): void
    {
        $this->actingAsCore('core_admin', $this->bureauA);
        $this->getJson('/api/v1/api-clients')->assertForbidden();

        $this->actingAsCore('core_admin');
        $this->postJson('/api/v1/api-clients', ['name' => 'X', 'scopes' => ['employees:write']])->assertUnprocessable()->assertJsonValidationErrors('scopes.0');
        $this->getJson('/api/v1/api-clients')->assertOk()->assertJsonMissingPath('data.0.secret');
    }

    public function test_events_are_written_with_the_change_and_delivered_signed_outside_it(): void
    {
        $this->actingAsCore('core_admin');
        $created = $this->postJson('/api/v1/webhooks', ['name' => 'Cafeteria', 'url' => 'https://cafeteria.test/hooks', 'event_types' => ['card.revoked', 'employee.updated']])->assertCreated();
        $secret = $created->json('secret');
        $this->getJson('/api/v1/webhooks')->assertOk()->assertJsonMissingPath('data.0.secret');

        $this->actingAsCore('core_officer');
        $this->postJson('/api/v1/id-cards/'.$this->card->id.'/revoke', ['reason' => 'Left the service'])->assertOk();
        $this->putJson('/api/v1/employees/'.$this->employee->id, ['first_name' => 'Kebede'])->assertOk();

        $revoked = OutboxEvent::query()->where('event_type', 'card.revoked')->sole();
        $this->assertSame($this->card->id, $revoked->entity_id);
        $updated = OutboxEvent::query()->where('event_type', 'employee.updated')->sole();
        $this->assertSame(['first_name'], $updated->data['changed_fields']);
        $this->assertStringNotContainsString('Kebede', json_encode($updated->data));
        $this->assertSame(2, WebhookDelivery::query()->where('status', WebhookDelivery::PENDING)->count());

        Http::fake(['cafeteria.test/*' => Http::response('ok', 200)]);
        Artisan::call('core:deliver-webhooks');

        $this->assertSame(2, WebhookDelivery::query()->where('status', WebhookDelivery::DELIVERED)->count());
        Http::assertSent(function (HttpRequest $request) use ($secret, $revoked): bool {
            if ($request->header('X-Core-Event-Id')[0] !== $revoked->id) {
                return false;
            }
            $expected = 'v1='.WebhookDeliverer::signature($secret, $request->header('X-Core-Timestamp')[0], $request->body());

            return $request->header('X-Core-Signature')[0] === $expected && $request['event_type'] === 'card.revoked' && $request['payload_version'] === 1;
        });
    }

    public function test_a_failing_receiver_is_retried_with_back_off_then_failed_and_can_be_retried_by_hand(): void
    {
        $this->actingAsCore('core_admin');
        $this->postJson('/api/v1/webhooks', ['name' => 'Down', 'url' => 'https://down.test/hooks', 'event_types' => ['*']])->assertCreated();
        $this->actingAsCore('core_officer');
        $this->postJson('/api/v1/id-cards/'.$this->card->id.'/suspend', ['reason' => 'Under investigation'])->assertOk();
        // The business change committed whatever happens to the receiver.
        $this->assertSame('suspended', $this->card->fresh()->status);

        Http::fake(['down.test/*' => Http::response('no', 500)]);
        Artisan::call('core:deliver-webhooks');
        $delivery = WebhookDelivery::query()->sole();
        $this->assertSame(WebhookDelivery::PENDING, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(500, $delivery->response_code);
        $this->assertTrue($delivery->next_attempt_at->isFuture());

        foreach (WebhookDeliverer::BACKOFF_MINUTES as $ignored) {
            WebhookDelivery::query()->update(['next_attempt_at' => now()->subSecond()]);
            Artisan::call('core:deliver-webhooks');
        }
        $this->assertSame(WebhookDelivery::FAILED, $delivery->fresh()->status);

        $this->actingAsCore('core_admin');
        $this->getJson('/api/v1/webhook-deliveries?status=failed')->assertOk()->assertJsonPath('total', 1);
        $this->postJson('/api/v1/webhook-deliveries/'.$delivery->id.'/retry')->assertOk()->assertJsonPath('data.status', WebhookDelivery::PENDING);
    }
}
