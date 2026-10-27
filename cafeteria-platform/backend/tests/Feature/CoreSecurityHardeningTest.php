<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\ManageBreakGlass;
use App\Models\BreakGlassGrant;
use App\Models\CoreAuditLog;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\AuditChain;
use App\Services\AuditWriter;
use App\Services\BreakGlassAccess;
use App\Services\OrganizationScope;
use App\Services\WebhookDeliverer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** P3 (ADR 0010): audit tamper evidence, break-glass access and webhook reliability. */
class CoreSecurityHardeningTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
    }

    private function person(string $role, ?Organization $scope = null): User
    {
        return User::query()->create([
            'name' => $role.' '.Str::random(4), 'email' => Str::random(8).'@core.test', 'password' => 'secret-password', 'role' => $role,
            'scope_organization_id' => $scope?->id, 'scope_type' => $scope === null ? User::SCOPE_CITYWIDE : User::SCOPE_SUBTREE, 'is_active' => true,
        ]);
    }

    private function signIn(User $user): User
    {
        Auth::forgetGuards();
        $this->actingAs($user, 'web');

        return $user;
    }

    /** As someone with database access would: the append-only trigger out of the way. */
    private function bypassTriggers(): void
    {
        match (DB::getDriverName()) {
            'sqlite' => DB::unprepared('DROP TRIGGER core_audit_logs_no_change; DROP TRIGGER core_audit_logs_no_delete;'),
            'pgsql' => DB::unprepared('ALTER TABLE core_audit_logs DISABLE TRIGGER core_audit_logs_append_only'),
            default => $this->markTestSkipped('No trigger bypass for this driver.'),
        };
    }

    public function test_sealed_audit_rows_are_chained_append_only_and_a_change_is_found(): void
    {
        $audit = app(AuditWriter::class);
        foreach (['one', 'two', 'three'] as $reason) {
            $audit->writeFor('test.entry', null, 'Test', (string) Str::uuid7(), null, null, ['value' => $reason], $reason);
        }
        $this->artisan('core:audit-seal')->assertSuccessful();
        $rows = DB::table('core_audit_logs')->orderBy('sequence')->get();
        $this->assertSame([1, 2, 3], $rows->pluck('sequence')->map(fn ($s) => (int) $s)->all());
        $this->assertSame(1, DB::table('audit_seals')->count());
        $this->artisan('core:audit-verify')->assertSuccessful();
        $this->assertTrue((bool) DB::table('audit_verifications')->latest('id')->value('ok'));

        // The database refuses to change or remove a sealed row.
        foreach ([fn () => DB::table('core_audit_logs')->where('sequence', 2)->update(['reason' => 'rewritten']), fn () => DB::table('core_audit_logs')->where('sequence', 2)->delete()] as $attempt) {
            try {
                DB::transaction($attempt); // a savepoint: PostgreSQL aborts the test transaction otherwise
                $this->fail('A sealed audit row was changed.');
            } catch (QueryException) {
            }
        }

        // Past the trigger, the verification still finds the change, and which row it was.
        $this->bypassTriggers();
        DB::table('core_audit_logs')->where('sequence', 2)->update(['reason' => 'rewritten']);
        $this->artisan('core:audit-verify')->assertFailed();
        $result = app(AuditChain::class)->verify();
        $this->assertSame([false, 2], [$result['ok'], $result['first_bad_sequence']]);
    }

    public function test_a_removed_audit_row_breaks_the_sequence(): void
    {
        $audit = app(AuditWriter::class);
        foreach (['one', 'two', 'three'] as $reason) {
            $audit->writeFor('test.entry', null, 'Test', (string) Str::uuid7(), null, null, null, $reason);
        }
        app(AuditChain::class)->seal();
        $this->bypassTriggers();
        DB::table('core_audit_logs')->where('sequence', 2)->delete();

        $result = app(AuditChain::class)->verify();
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('missing', $result['message']);
    }

    public function test_break_glass_needs_another_person_widens_scope_for_its_time_and_marks_the_audit_trail(): void
    {
        $officer = $this->person('core_officer', $this->bureauA);
        $scope = app(OrganizationScope::class);
        $this->assertFalse($scope->allows($officer, (string) $this->bureauB->id));
        $breakGlass = app(ManageBreakGlass::class);

        try {
            $breakGlass->request(['permissions' => ['roles.manage'], 'scope_type' => 'organization', 'organization_id' => $this->bureauB->id, 'duration_minutes' => 60,
                'reason' => 'Need to fix roles in an emergency situation'], $officer);
            $this->fail('Role management was granted through break-glass.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('permissions.0', $e->errors());
        }
        $grant = $breakGlass->request(['permissions' => ['employees.view', 'cards.view'], 'scope_type' => 'organization', 'organization_id' => $this->bureauB->id,
            'duration_minutes' => 30, 'reason' => 'Bureau B card desk is unstaffed during the flood response'], $officer);

        try {
            $breakGlass->decide($grant, true, null, $officer);
            $this->fail('The requester approved their own emergency access.');
        } catch (\Throwable $e) {
            $this->assertTrue($e instanceof ValidationException || $e instanceof HttpException);
        }
        $breakGlass->decide($grant, true, 'Confirmed with the bureau head', $this->person('core_admin'));
        $this->assertTrue($scope->allows($officer, (string) $this->bureauB->id));
        $log = app(AuditWriter::class)->write('test.under_break_glass', $officer, $this->employee, (string) $this->bureauB->id);
        $this->assertSame((string) $grant->id, (string) $log->break_glass_grant_id);

        // Its time runs out: nothing is granted any more, and the scheduler records it.
        $this->travel(31)->minutes();
        app(BreakGlassAccess::class)->forget();
        $scope->clearCache();
        $this->assertFalse($scope->allows($officer, (string) $this->bureauB->id));
        $this->artisan('core:break-glass-expire')->assertSuccessful();
        $this->assertSame(BreakGlassGrant::EXPIRED, $grant->fresh()->status);
        $this->assertTrue(CoreAuditLog::query()->where('action', 'break_glass.expired')->where('subject_id', $grant->id)->exists());
    }

    public function test_the_security_center_lists_requests_and_lets_another_person_decide(): void
    {
        $officer = $this->signIn($this->person('core_officer', $this->bureauA));
        $this->post('/core/security/break-glass', ['permissions' => ['employees.view'], 'scope_type' => 'citywide', 'duration_minutes' => 20,
            'reason' => 'Citywide lookup needed for the audit visit today'])->assertSessionHasNoErrors();
        $grant = BreakGlassGrant::query()->sole();
        $this->get('/core/security')->assertOk()->assertInertia(fn ($page) => $page->component('Core/SecurityCenter')->where('grants.0.decide_url', null));

        $this->signIn($this->person('core_admin'));
        $this->get('/core/security')->assertInertia(fn ($page) => $page->where('grants.0.decide_url', route('core.security.break-glass.decide', $grant))
            ->where('integrity.sealed_through', 0));
        $this->post('/core/security/break-glass/'.$grant->id.'/decide', ['decision' => 'reject'])->assertSessionHasErrors('reason');
        $this->post('/core/security/break-glass/'.$grant->id.'/decide', ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame(BreakGlassGrant::ACTIVE, $grant->fresh()->status);
        $this->post('/core/security/break-glass/'.$grant->id.'/end', ['reason' => 'Audit visit finished'])->assertSessionHasNoErrors();
        $this->assertSame(BreakGlassGrant::REVOKED, $grant->fresh()->status);
        $this->assertSame((string) $officer->id, (string) $grant->user_id);
    }

    public function test_a_failing_webhook_is_suspended_and_resumed_and_a_rotated_secret_keeps_signing_for_its_grace(): void
    {
        DB::table('core_settings')->insert(['group' => 'integration', 'key' => 'webhook_suspend_after', 'value' => json_encode(3), 'created_at' => now(), 'updated_at' => now()]);
        $admin = $this->signIn($this->person('core_admin'));
        $this->post('/core/integration/webhooks', ['name' => 'Down', 'url' => 'https://down.test/hooks', 'event_types' => ['*']])->assertSessionHasNoErrors();
        $endpoint = WebhookEndpoint::query()->sole();
        $this->actingAsCore('core_officer');
        $this->postJson('/api/v1/id-cards/'.$this->card->id.'/suspend', ['reason' => 'Under investigation'])->assertOk();

        Http::fake(['down.test/*' => Http::response('no', 500)]);
        $delivery = WebhookDelivery::query()->sole();
        foreach (range(1, 3) as $ignored) {
            app(WebhookDeliverer::class)->send($delivery->fresh(['endpoint', 'event']));
        }
        $endpoint->refresh();
        $this->assertFalse($endpoint->is_active);
        $this->assertNotNull($endpoint->suspended_at);
        $this->assertTrue(CoreAuditLog::query()->where('action', 'webhook.suspended')->where('subject_id', $endpoint->id)->exists());
        $this->assertSame(WebhookDelivery::PENDING, $delivery->fresh()->status);

        $this->signIn($admin);
        $this->post('/core/integration/webhooks/'.$endpoint->id.'/resume')->assertSessionHasNoErrors();
        $endpoint->refresh();
        $this->assertSame([true, 0, null], [$endpoint->is_active, $endpoint->consecutive_failures, $endpoint->suspended_at]);

        $old = (string) $endpoint->secret;
        $this->post('/core/integration/webhooks/'.$endpoint->id.'/rotate-secret')->assertSessionHasNoErrors();
        Http::fake(['down.test/*' => Http::response('ok', 200)]);
        app(WebhookDeliverer::class)->send($delivery->fresh(['endpoint', 'event']));
        Http::assertSent(function (HttpRequest $request) use ($old): bool {
            $signatures = explode(',', $request->header('X-Core-Signature')[0]);
            $expected = 'v1='.WebhookDeliverer::signature($old, $request->header('X-Core-Timestamp')[0], $request->body());

            return count($signatures) === 2 && in_array($expected, $signatures, true);
        });
    }

    public function test_integration_changes_need_the_manage_permission_and_retention_keeps_undelivered_events(): void
    {
        $this->signIn($this->person('core_admin'));
        $this->post('/roles', ['name' => 'Integration readers', 'scope_type' => 'global', 'permissions' => ['core.access', 'integrations.view']])->assertSessionHasNoErrors();
        $key = (string) DB::table('core_roles')->where('name_en', 'Integration readers')->value('key');
        $this->signIn($this->person($key));
        $this->get('/core/integration')->assertOk();
        $this->post('/core/integration/clients', ['name' => 'Rogue', 'scopes' => ['employees:basic-read'], 'scope_type' => 'citywide'])->assertForbidden();
        $this->post('/core/integration/webhooks', ['name' => 'Rogue', 'url' => 'https://rogue.test/h', 'event_types' => ['*']])->assertForbidden();

        $endpoint = new WebhookEndpoint(['name' => 'Partner', 'url' => 'https://partner.test/h', 'event_types' => ['*'], 'is_active' => true]);
        $endpoint->forceFill(['secret' => 'whsec_x'])->save();
        $event = fn (): OutboxEvent => OutboxEvent::query()->create(['id' => (string) Str::uuid7(), 'event_type' => 'card.suspended', 'entity_type' => 'IdCard', 'entity_id' => (string) Str::uuid7(),
            'payload_version' => 1, 'data' => [], 'occurred_at' => now()->subDays(200)]);
        [$delivered, $pending] = [$event(), $event()];
        foreach ([[$delivered, WebhookDelivery::DELIVERED], [$pending, WebhookDelivery::PENDING]] as [$e, $status]) {
            WebhookDelivery::query()->create(['webhook_endpoint_id' => $endpoint->id, 'outbox_event_id' => $e->id, 'status' => $status]);
        }
        Artisan::call('core:prune-integration-data');
        $this->assertFalse(OutboxEvent::query()->whereKey($delivered->id)->exists());
        $this->assertTrue(OutboxEvent::query()->whereKey($pending->id)->exists());
    }
}
