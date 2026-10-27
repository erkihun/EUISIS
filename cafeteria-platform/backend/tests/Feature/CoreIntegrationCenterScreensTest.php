<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\CoreAuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** P1: the Integration Center on the EUISIS screens, over ManageIntegrations and IntegrationCenter. */
class CoreIntegrationCenterScreensTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
    }

    private function signIn(string $role = 'core_admin', ?Organization $scope = null): User
    {
        $user = User::query()->create([
            'name' => $role.' '.Str::random(4), 'email' => Str::random(8).'@core.test', 'password' => 'secret-password',
            'role' => $role, 'scope_organization_id' => $scope?->id,
            'scope_type' => $scope === null ? User::SCOPE_CITYWIDE : User::SCOPE_SUBTREE, 'is_active' => true,
        ]);
        Auth::forgetGuards();
        $this->actingAs($user, 'web');

        return $user;
    }

    /** @return array<string, string> */
    private function bearer(string $secret): array
    {
        Auth::forgetGuards();

        return ['Authorization' => 'Bearer '.$secret];
    }

    public function test_an_api_client_is_registered_with_its_secret_shown_once_then_edited_rotated_and_revoked(): void
    {
        $admin = $this->signIn();
        $this->get('/core/integration')->assertOk()->assertInertia(fn ($page) => $page->component('Core/IntegrationCenter')
            ->where('center.tab', 'clients')->where('center.clients', [])->where('center.options.scopes', ApiClient::SCOPES));

        $client = ['name' => 'Payroll', 'description' => '', 'scope_type' => 'citywide', 'owner_organization_id' => '', 'scopes' => ['organizations:read'],
            'allowed_ips' => '', 'rate_limit_per_minute' => '60', 'expires_at' => ''];
        $this->post('/core/integration/clients', ['scope_type' => 'subtree'] + $client)->assertSessionHasErrors('owner_organization_id');
        $this->post('/core/integration/clients', $client)->assertSessionHasNoErrors()->assertRedirect('/core/integration?tab=clients');
        $secret = session('integration_secret_once')['secret'];
        $this->assertNotEmpty($secret);

        $this->get('/core/integration?tab=clients')->assertInertia(fn ($page) => $page->where('flash.integration_secret_once.secret', $secret)->where('center.clients.0.name', 'Payroll'));
        $again = $this->get('/core/integration?tab=clients')->assertInertia(fn ($page) => $page->where('flash.integration_secret_once', null));
        $this->assertStringNotContainsString($secret, json_encode($again->viewData('page')));
        $this->getJson('/api/v1/integration/organizations', $this->bearer($secret))->assertOk();

        $uuid = ApiClient::query()->sole()->uuid;
        $this->actingAs($admin, 'web');
        $this->put("/core/integration/clients/{$uuid}", ['scope_type' => 'subtree', 'owner_organization_id' => $this->bureauA->id] + $client)->assertSessionHasNoErrors();
        $this->assertSame($this->bureauA->id, ApiClient::query()->sole()->owner_organization_id);

        $this->post("/core/integration/clients/{$uuid}/rotate")->assertSessionHasNoErrors();
        $rotated = session('integration_secret_once')['secret'];
        $this->getJson('/api/v1/integration/organizations', $this->bearer($secret))->assertUnauthorized();
        $this->getJson('/api/v1/integration/organizations', $this->bearer($rotated))->assertOk();

        $this->actingAs($admin, 'web');
        $this->post("/core/integration/clients/{$uuid}/revoke", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/core/integration/clients/{$uuid}/revoke", ['reason' => 'Contract ended'])->assertSessionHasNoErrors();
        $this->getJson('/api/v1/integration/organizations', $this->bearer($rotated))->assertUnauthorized();
        $this->actingAs($admin, 'web');
        $this->put("/core/integration/clients/{$uuid}", $client)->assertSessionHasErrors('client');

        foreach (['api_client.created', 'api_client.updated', 'api_client.rotated', 'api_client.revoked'] as $action) {
            $this->assertDatabaseHas('core_audit_logs', ['action' => $action, 'subject_id' => $uuid]);
        }
        $audit = (string) CoreAuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString($secret, $audit);
        $this->assertStringNotContainsString($rotated, $audit);
    }

    public function test_a_webhook_is_registered_edited_turned_off_and_its_secret_rotated(): void
    {
        $this->signIn();
        $webhook = ['name' => 'HR system', 'url' => 'https://hr.test/hooks', 'event_types' => ['employee.updated'], 'scope_organization_id' => ''];
        $this->post('/core/integration/webhooks', ['event_types' => ['nonsense']] + $webhook)->assertSessionHasErrors('event_types.0');
        $this->post('/core/integration/webhooks', $webhook)->assertSessionHasNoErrors()->assertRedirect('/core/integration?tab=webhooks');
        $secret = session('integration_secret_once')['secret'];
        $this->assertStringStartsWith('whsec_', $secret);
        $endpoint = WebhookEndpoint::query()->sole();
        $this->assertSame($secret, $endpoint->secret);

        $page = $this->get('/core/integration?tab=webhooks')->assertInertia(fn ($page) => $page->where('center.webhooks.0.name', 'HR system')->where('center.webhooks.0.is_active', true));
        $this->put("/core/integration/webhooks/{$endpoint->id}", ['event_types' => ['*'], 'scope_organization_id' => $this->bureauA->id] + $webhook)->assertSessionHasNoErrors();
        $this->put("/core/integration/webhooks/{$endpoint->id}", ['is_active' => false])->assertSessionHasNoErrors();
        $endpoint->refresh();
        $this->assertFalse($endpoint->is_active);
        $this->assertSame(['*'], $endpoint->event_types);

        $this->post("/core/integration/webhooks/{$endpoint->id}/rotate-secret")->assertSessionHasNoErrors();
        $rotated = session('integration_secret_once')['secret'];
        $this->assertNotSame($secret, $rotated);
        $this->assertSame($rotated, $endpoint->fresh()->secret);
        $later = json_encode($this->get('/core/integration?tab=webhooks')->viewData('page')['props']['center']);
        $this->assertStringNotContainsString($rotated, $later);
        $this->assertStringNotContainsString($rotated, (string) CoreAuditLog::query()->get()->toJson());
        $this->assertDatabaseHas('core_audit_logs', ['action' => 'webhook.secret_rotated', 'subject_id' => $endpoint->id]);
    }

    public function test_a_failed_delivery_is_retried_by_hand_and_the_event_and_call_logs_are_listed(): void
    {
        $admin = $this->signIn();
        $this->post('/core/integration/webhooks', ['name' => 'Down', 'url' => 'https://down.test/hooks', 'event_types' => ['*']])->assertSessionHasNoErrors();
        $this->actingAsCore('core_officer');
        $this->postJson('/api/v1/id-cards/'.$this->card->id.'/suspend', ['reason' => 'Under investigation'])->assertOk();
        Http::fake(['down.test/*' => Http::response('no', 500)]);
        Artisan::call('core:deliver-webhooks');
        $delivery = WebhookDelivery::query()->sole();
        $delivery->forceFill(['status' => WebhookDelivery::FAILED])->save();

        Auth::forgetGuards();
        $this->actingAs($admin, 'web');
        $this->get('/core/integration?tab=deliveries&status=failed')->assertOk()->assertInertia(fn ($page) => $page->where('center.deliveries.meta.total', 1)
            ->where('center.deliveries.data.0.event_type', 'card.suspended')->where('center.deliveries.data.0.retry_url', route('core.integration.deliveries.retry', $delivery->id))
            ->where('center.summary.failed_deliveries', 1));
        $this->post("/core/integration/deliveries/{$delivery->id}/retry")->assertSessionHasNoErrors();
        $this->assertSame(WebhookDelivery::PENDING, $delivery->fresh()->status);
        $this->assertDatabaseHas('core_audit_logs', ['action' => 'webhook.delivery_retried', 'subject_id' => $delivery->id]);

        $delivery->forceFill(['status' => WebhookDelivery::DELIVERED])->save();
        $this->post("/core/integration/deliveries/{$delivery->id}/retry")->assertSessionHasErrors('delivery');
        $this->get('/core/integration?tab=deliveries')->assertInertia(fn ($page) => $page->where('center.deliveries.data.0.retry_url', null));

        $this->get('/core/integration?tab=events&type=card.suspended')->assertOk()->assertInertia(fn ($page) => $page->where('center.events.meta.total', 1)
            ->where('center.events.data.0.event_type', 'card.suspended'));
        $this->get('/core/integration?tab=logs')->assertOk()->assertInertia(fn ($page) => $page->has('center.logs.data')->has('center.logs.meta'));
        $this->get('/core/integration?tab=nonsense')->assertSessionHasErrors('tab');
    }

    public function test_only_a_city_wide_administrator_reaches_the_integration_center(): void
    {
        $this->signIn();
        $this->get('/cafeteria')->assertInertia(fn ($page) => $page->where('workspace.nav', fn ($nav) => str_contains(json_encode($nav), '"core.integration"')));

        foreach ([['core_admin', $this->bureauA], ['core_officer', null], ['core_viewer', null]] as [$role, $scope]) {
            $this->signIn($role, $scope);
            $this->get('/cafeteria')->assertInertia(fn ($page) => $page->where('workspace.nav', fn ($nav) => ! str_contains(json_encode($nav), '"core.integration"')));
            $this->get('/core/integration')->assertForbidden();
            $this->post('/core/integration/clients', ['name' => 'Mine', 'scope_type' => 'citywide', 'scopes' => ['organizations:read']])->assertForbidden();
            $this->post('/core/integration/webhooks', ['name' => 'Mine', 'url' => 'https://x.test', 'event_types' => ['*']])->assertForbidden();
        }
        $this->assertSame(0, ApiClient::query()->count());
        $this->assertSame(0, WebhookEndpoint::query()->count());
    }
}
