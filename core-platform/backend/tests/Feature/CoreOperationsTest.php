<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\EmployeeVerification;
use App\Actions\SaveUser;
use App\Models\ApiClient;
use App\Models\CoreAuditLog;
use App\Models\CoreNotification;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\AuditWriter;
use App\Services\SystemHealth;
use App\Support\CoreDates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** P4/P5 (ADR 0011): notifications, scoped dashboard, System Health, localization and production security. */
class CoreOperationsTest extends TestCase
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
            'name' => $role.' '.Str::random(4), 'email' => Str::random(8).'@core.test', 'password' => 'secret-password-1', 'role' => $role,
            'scope_organization_id' => $scope?->id, 'scope_type' => $scope === null ? User::SCOPE_CITYWIDE : User::SCOPE_SUBTREE, 'is_active' => true,
        ]);
    }

    private function signIn(User $user): User
    {
        Auth::forgetGuards();
        $this->actingAs($user, 'web');

        return $user;
    }

    /** A draft employee in bureau A, ready to submit. */
    private function draft(): Employee
    {
        $employee = $this->placedEmployee('EMP-0100', $this->positionA, '2024-01-01');
        $employee->forceFill(['status' => Employee::DRAFT, 'verification_status' => Employee::DRAFT])->save();
        $employee->currentAssignment->forceFill(['assignment_status' => 'pending'])->save();

        return $employee->fresh();
    }

    public function test_a_change_notifies_the_people_in_scope_after_it_commits_in_their_language(): void
    {
        $verifierA = $this->person('core_officer', $this->bureauA);
        $verifierB = $this->person('core_officer', $this->bureauB);
        $clerk = $this->person('core_officer', $this->bureauA);
        $employee = $this->draft();

        app(EmployeeVerification::class)->submit($employee, $clerk);
        $notification = CoreNotification::query()->where('user_id', $verifierA->id)->where('event', 'employee_submitted')->sole();
        $this->assertFalse(CoreNotification::query()->where('user_id', $verifierB->id)->exists());
        $this->assertFalse(CoreNotification::query()->where('user_id', $clerk->id)->exists());
        $this->assertStringContainsString('EMP-0100', $notification->text('en')['title']);
        $this->assertStringContainsString('ማረጋገጫ', $notification->text('am')['title']);
        $this->assertSame(0, DB::table('notification_deliveries')->count()); // e-mail is off by default

        // The screen lists it and marks it read.
        $this->signIn($verifierA);
        $this->get('/core/notifications')->assertInertia(fn ($page) => $page->component('Core/Notifications')->where('unread', 1)->where('notifications.data.0.event', 'employee_submitted'));
        $this->post('/core/notifications/read')->assertSessionHasNoErrors();
        $this->assertNotNull($notification->fresh()->read_at);

        // A change rolled back notifies nobody.
        try {
            DB::transaction(function (): void {
                app(AuditWriter::class)->write('employee.submitted', null, $this->employee, (string) $this->bureauA->id);
                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(1, CoreNotification::query()->count());
    }

    public function test_a_failing_e_mail_is_recorded_and_never_undoes_the_change(): void
    {
        DB::table('core_settings')->insert(['group' => 'notifications', 'key' => 'email_enabled', 'value' => json_encode(true), 'created_at' => now(), 'updated_at' => now()]);
        $verifier = $this->person('core_officer', $this->bureauA);
        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('SMTP down'));
        $employee = $this->draft();

        app(EmployeeVerification::class)->submit($employee, $this->person('core_officer', $this->bureauA));
        $this->assertSame(Employee::SUBMITTED, $employee->fresh()->status);
        $this->assertTrue(CoreNotification::query()->where('user_id', $verifier->id)->exists());
        $this->assertSame('failed', DB::table('notification_deliveries')->where('channel', 'email')->value('status'));
    }

    public function test_the_dashboard_counts_only_the_readers_organizations(): void
    {
        $this->placedEmployee('EMP-0200', $this->positionB, '2024-01-01');
        $this->signIn($this->person('core_officer', $this->bureauA));
        $this->get('/core')->assertInertia(fn ($page) => $page->where('metrics.citywide', false)->where('metrics.domains', function ($domains): bool {
            $metrics = collect($domains)->flatMap(fn ($d) => $d['metrics'])->pluck('value', 'key');

            return $metrics['employees_total'] === 1 && ! $metrics->has('failed_jobs') && ! $metrics->has('mfa_coverage') && ! $metrics->has('hierarchy_pending');
        }));
        $this->signIn($this->person('core_admin'));
        $this->get('/core')->assertInertia(fn ($page) => $page->where('metrics.domains', function ($domains): bool {
            $metrics = collect($domains)->flatMap(fn ($d) => $d['metrics'])->pluck('value', 'key');

            return $metrics['employees_total'] === 2 && $metrics->has('failed_jobs') && $metrics->has('break_glass_active');
        }));
    }

    public function test_health_never_claims_a_backup_and_shows_queue_and_webhook_trouble(): void
    {
        $health = app(SystemHealth::class);
        $this->assertSame('unknown', $health->checks()['backup']['status']);

        $file = tempnam(sys_get_temp_dir(), 'backup-');
        config(['core.backup_status_file' => $file]);
        file_put_contents($file, json_encode(['last_success_at' => now()->subHours(3)->toIso8601String(), 'type' => 'pg_dump full', 'restore_tested_at' => now()->subDays(3)->toIso8601String(), 'restore_test_result' => 'passed']));
        $this->assertSame('ok', $health->checks()['backup']['status']);
        file_put_contents($file, json_encode(['last_success_at' => now()->subHours(3)->toIso8601String(), 'last_failure_at' => now()->subHour()->toIso8601String()]));
        $this->assertSame('error', $health->checks()['backup']['status']);
        @unlink($file);

        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\GenerateExport', 'data' => ['secret' => 'never shown']]), 'exception' => "RuntimeException: boom\n#0 trace", 'failed_at' => now()]);
        $this->assertSame('warning', $health->checks()['queue']['status']);
        $endpoint = new WebhookEndpoint(['name' => 'Down', 'url' => 'https://down.test/h', 'event_types' => ['*'], 'is_active' => true]);
        $endpoint->forceFill(['secret' => 'whsec_x'])->save();
        $event = OutboxEvent::query()->create(['id' => (string) Str::uuid7(), 'event_type' => 'card.revoked', 'entity_type' => 'IdCard', 'entity_id' => 'x', 'payload_version' => 1, 'data' => [], 'occurred_at' => now()]);
        WebhookDelivery::query()->create(['webhook_endpoint_id' => $endpoint->id, 'outbox_event_id' => $event->id, 'status' => WebhookDelivery::FAILED]);
        $this->assertSame('warning', $health->checks()['webhooks']['status']);

        $this->signIn($this->person('core_admin', $this->bureauA));
        $this->get('/core/system-health')->assertForbidden();
        $admin = $this->signIn($this->person('core_admin'));
        $response = $this->get('/core/system-health')->assertOk()->assertInertia(fn ($page) => $page->component('Core/SystemHealth')
            ->where('failed_jobs.0.job', 'App\\Jobs\\GenerateExport'));
        $this->assertStringNotContainsString('never shown', (string) $response->getContent());
        $uuid = (string) DB::table('failed_jobs')->value('uuid');
        $this->post("/core/system-health/jobs/{$uuid}/forget")->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertTrue(CoreAuditLog::query()->where('action', 'queue.job_forgotten')->where('actor_user_id', $admin->id)->exists());
    }

    public function test_dates_follow_the_language_on_screens_and_stay_iso_in_the_api(): void
    {
        $this->assertSame('2026-10-25', CoreDates::display('2026-10-25', 'en'));
        $this->assertStringContainsString('2019', CoreDates::display('2026-10-25', 'am')); // Tikimt 15, 2019 E.C.
        $this->assertStringNotContainsString('2026', CoreDates::display('2026-10-25', 'am'));

        $client = new ApiClient(['name' => 'Payroll', 'scopes' => ['employees:basic-read', 'assignments:read'], 'scope_type' => User::SCOPE_CITYWIDE, 'is_active' => true]);
        $client->save();
        $secret = $client->createToken('integration', ['employees:basic-read', 'assignments:read'])->plainTextToken;
        $this->withCookie('core_locale', 'am')->getJson('/api/v1/integration/employees/'.$this->employee->id.'/assignment', ['Authorization' => 'Bearer '.$secret, 'Accept-Language' => 'am'])
            ->assertOk()->assertJsonPath('data.effective_from', '2024-01-01');
    }

    public function test_access_changes_end_sign_ins_and_sign_in_is_throttled_and_audited(): void
    {
        $user = $this->person('core_officer', $this->bureauA);
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => '', 'last_activity' => time()]);
        app(SaveUser::class)->execute($user, ['name' => $user->name, 'email' => $user->email, 'role' => 'core_viewer', 'scope_type' => User::SCOPE_SUBTREE,
            'scope_organization_id' => $this->bureauA->id, 'is_active' => true], $this->person('core_admin'));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());

        Auth::forgetGuards();
        foreach (range(1, 5) as $ignored) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->assertSame(5, CoreAuditLog::query()->where('action', 'auth.sign_in_failed')->count());
        $this->assertSame(1, CoreAuditLog::query()->where('action', 'auth.locked_out')->count());
        $this->assertStringNotContainsString($user->email, (string) CoreAuditLog::query()->where('action', 'auth.sign_in_failed')->get()->toJson());

        // Across organizations a record is simply not there.
        $this->signIn($this->person('core_officer', $this->bureauB));
        $this->get('/employees/'.$this->employee->id)->assertNotFound();
    }
}
