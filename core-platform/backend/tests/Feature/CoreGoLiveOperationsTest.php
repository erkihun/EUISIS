<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AuditHeads;
use App\Services\AuditWriter;
use App\Services\ConfigAudit;
use App\Services\SystemHealth;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Go-live operations (ADR 0012): the production configuration review, seal heads off the server, probes and the schedule. */
class CoreGoLiveOperationsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> setting => status */
    private function audit(): array
    {
        return collect(app(ConfigAudit::class)->results())->mapWithKeys(fn (array $r): array => [$r['key'] => $r['status']])->all();
    }

    private function writeAudit(int $rows): void
    {
        for ($i = 0; $i < $rows; $i++) {
            app(AuditWriter::class)->writeFor('test.entry', null, 'Test', (string) Str::uuid7(), null, null, ['n' => $i]);
        }
    }

    public function test_production_refuses_derived_or_shared_keys_and_missing_operations(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $audit = $this->audit();
        foreach (['CORE_CREDENTIAL_KEYS', 'CORE_AUDIT_SEAL_KEY', 'NATIONAL_ID_HASH_KEY', 'CORE_AUDIT_HEAD_DISK', 'CORE_BACKUP_STATUS_FILE', 'BACKUP_STATUS', 'SCHEDULER'] as $key) {
            $this->assertSame('fail', $audit[$key], $key);
        }
        $this->artisan('core:config-audit')->assertFailed();

        // A seal key that is the application key, or a credential key reused as the seal key, is never independent.
        config(['core.audit_seal_key' => (string) config('app.key')]);
        $this->assertSame('fail', $this->audit()['KEY_INDEPENDENCE']);
        $shared = 'base64:'.base64_encode(random_bytes(32));
        config(['core.audit_seal_key' => $shared, 'core.credential_keys' => '1:'.$shared]);
        $this->assertSame('fail', $this->audit()['KEY_INDEPENDENCE']);
        // A key too short to use is not "configured": the credentials would silently fall back to APP_KEY.
        config(['core.credential_keys' => '1:short']);
        $this->assertSame('fail', $this->audit()['CORE_CREDENTIAL_KEYS']);

        config([
            'core.credential_keys' => '1:base64:'.base64_encode(random_bytes(32)),
            'core.audit_seal_key' => 'base64:'.base64_encode(random_bytes(32)),
            'core.national_id_hash_key' => Str::random(48),
            'core.audit_head_disk' => 'audit_heads',
        ]);
        $audit = $this->audit();
        foreach (['CORE_CREDENTIAL_KEYS', 'CORE_AUDIT_SEAL_KEY', 'NATIONAL_ID_HASH_KEY', 'KEY_INDEPENDENCE', 'CORE_AUDIT_HEAD_DISK', 'PRIVATE_STORAGE'] as $key) {
            $this->assertSame('pass', $audit[$key], $key);
        }

        // Outside production the same gaps are warnings, so development is not blocked.
        $this->app->detectEnvironment(fn (): string => 'local');
        config(['core.credential_keys' => '', 'core.audit_seal_key' => '', 'core.audit_head_disk' => '']);
        $this->assertSame(['warning', 'warning', 'warning'], [$this->audit()['CORE_CREDENTIAL_KEYS'], $this->audit()['CORE_AUDIT_SEAL_KEY'], $this->audit()['CORE_AUDIT_HEAD_DISK']]);
    }

    public function test_a_trusted_proxy_makes_https_and_the_client_address_visible(): void
    {
        $headers = ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.9'];
        $this->get('/login', $headers)->assertOk()->assertHeaderMissing('Strict-Transport-Security');

        config(['trustedproxy.proxies' => '127.0.0.1']);
        $this->get('/login', $headers)->assertOk()->assertHeader('Strict-Transport-Security');
        $this->assertSame('pass', $this->audit()['TRUSTED_PROXIES']);
    }

    public function test_a_disk_inside_the_web_root_is_exposed(): void
    {
        config(['filesystems.disks.exports.root' => public_path('exports')]);
        $this->assertSame('fail', $this->audit()['PRIVATE_STORAGE']);
    }

    public function test_seal_heads_are_shipped_off_the_server_and_verification_compares_them(): void
    {
        Storage::fake('audit_heads');
        config(['core.audit_head_disk' => 'audit_heads']);
        $this->writeAudit(3);
        $this->artisan('core:audit-seal')->assertSuccessful();
        $this->writeAudit(2);
        $this->artisan('core:audit-seal')->assertSuccessful();

        $this->artisan('core:audit-ship-heads')->expectsOutputToContain('2 seal head(s) shipped.')->assertSuccessful();
        $this->artisan('core:audit-ship-heads')->expectsOutputToContain('0 seal head(s) shipped.')->assertSuccessful();
        Storage::disk('audit_heads')->assertExists([AuditHeads::path(3), AuditHeads::path(5), AuditHeads::LATEST]);
        $this->artisan('core:audit-verify')->expectsOutputToContain('All 2 off-server seal heads match.')->assertSuccessful();

        // A seal rewritten in the database (here: its copy differs) is found.
        $copy = json_decode((string) Storage::disk('audit_heads')->get(AuditHeads::path(3)), true);
        Storage::disk('audit_heads')->put(AuditHeads::path(3), json_encode(['head_hash' => str_repeat('0', 64)] + $copy));
        $this->artisan('core:audit-verify')->expectsOutputToContain('differs from its off-server copy')->assertFailed();
        Storage::disk('audit_heads')->put(AuditHeads::path(3), json_encode($copy));

        // Seals the store has but the database lacks: removed seals, or a restore to an earlier point.
        Storage::disk('audit_heads')->put(AuditHeads::LATEST, json_encode(['to_sequence' => 9]));
        $this->artisan('core:audit-verify')->expectsOutputToContain('sealed rows are missing')->assertFailed();
        Storage::disk('audit_heads')->put(AuditHeads::LATEST, json_encode(['to_sequence' => 5]));

        // A seal past the grace period without a copy is found; a fresh one is not yet expected.
        Storage::disk('audit_heads')->delete(AuditHeads::path(5));
        $this->artisan('core:audit-verify')->assertSuccessful();
        $this->travel(AuditHeads::GRACE_MINUTES + 1)->minutes();
        $this->artisan('core:audit-verify')->expectsOutputToContain('has no off-server copy')->assertFailed();
    }

    public function test_a_failed_shipment_shows_on_system_health_until_a_run_succeeds(): void
    {
        $this->writeAudit(1);
        $this->artisan('core:audit-seal')->assertSuccessful();
        config(['core.audit_head_disk' => 'missing-disk']);
        $this->artisan('core:audit-ship-heads')->assertFailed();
        $this->assertSame('error', app(SystemHealth::class)->checks()['audit_integrity']['status']);

        Storage::fake('audit_heads');
        config(['core.audit_head_disk' => 'audit_heads']);
        $this->artisan('core:audit-ship-heads')->assertSuccessful();
        $this->assertNull(Cache::get('core.audit_heads.failed_at'));
        $this->assertSame(1, Cache::get('core.audit_heads.shipped_through'));
    }

    public function test_probes_answer_without_a_session_and_the_monitor_needs_its_token(): void
    {
        $this->get('/health/live')->assertOk()->assertExactJson(['status' => 'ok'])->assertCookieMissing(config('session.cookie'));
        $this->get('/health/ready')->assertOk()->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks', ['database' => 'ok', 'cache' => 'ok', 'queue' => 'ok', 'storage' => 'ok']);

        $this->getJson('/health/monitor')->assertNotFound();
        $token = Str::random(40);
        config(['core.monitor_token' => $token]);
        $this->getJson('/health/monitor')->assertUnauthorized();
        $this->getJson('/health/monitor', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
        $body = $this->getJson('/health/monitor', ['Authorization' => "Bearer {$token}"])->assertOk()
            ->assertJsonStructure(['status', 'checked_at', 'release', 'checks' => ['database', 'queue', 'scheduler', 'backup', 'audit_integrity'],
                'metrics' => ['api_p95_ms_5m', 'card_verification_p95_ms_5m', 'queue_waiting', 'scheduler_stale_commands', 'backup_status', 'storage_free_percent']])
            ->getContent();
        $this->assertStringNotContainsString('@', (string) $body);
        $this->assertSame('unknown', json_decode((string) $body, true)['checks']['backup']);
    }

    public function test_every_scheduled_command_is_protected_from_overlap_and_runs_on_one_server(): void
    {
        $events = collect(app(Schedule::class)->events());
        $this->assertTrue($events->contains(fn ($event): bool => str_contains((string) $event->command, 'core:audit-ship-heads')));
        foreach ($events as $event) {
            $this->assertTrue($event->withoutOverlapping, (string) $event->command);
            $this->assertTrue($event->onOneServer, (string) $event->command);
        }
        $scheduled = $events->map(fn ($event): string => trim(explode('artisan', (string) $event->command)[1] ?? ''))->map(fn (string $c): string => explode(' ', trim($c, "' \""))[0]);
        foreach (array_keys(SystemHealth::SCHEDULED) as $command) {
            $this->assertContains($command, $scheduled->all(), "System Health watches {$command}, which must be scheduled.");
        }
    }
}
