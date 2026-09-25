<?php

declare(strict_types=1);

use App\Actions\Audit\WriteAuditLogAction;
use App\Contracts\SmsGateway;
use App\Enums\AuditEventType;
use App\Models\Employee;
use App\Models\User;
use App\Services\ErrorLoggingService;
use App\Services\Sms\BudgetedSmsGateway;
use App\Services\Sms\HttpSmsGateway;
use App\Services\SystemSettings\SystemSettingsService;
use App\Support\DailyActivity\DailyActivityRoles;
use App\Support\EmployeePhotoStorage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| Security boundary hardening — regression tests
|--------------------------------------------------------------------------
| See docs/security-boundary-hardening-report.md. Each test names the
| finding it guards.
*/

/*
 * SBH-001. Hidden navigation is not access control. A plain employee (the
 * self-service Employee role only) must not be able to open any admin page
 * by typing its URL. The three pages below are self-service by design.
 */
test('SBH-001 a plain employee cannot open admin pages by URL', function (): void {
    $selfService = ['vacancy-applications/my', 'grievances/my', 'grievances/create'];

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole(DailyActivityRoles::EMPLOYEE_ROLE);

    $opened = [];
    $checked = 0;
    foreach (Route::getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();
        if (! in_array('GET', $route->methods(), true)
            || ! in_array('admin.access', $middleware, true)
            || ! in_array('mfa', $middleware, true)
            || str_contains($route->uri(), '{')
            || in_array($route->uri(), $selfService, true)) {
            continue;
        }

        $checked++;
        if ($this->actingAs($user)->get('/'.$route->uri())->getStatusCode() === 200) {
            $opened[] = $route->uri();
        }
    }

    expect($checked)->toBeGreaterThan(50)
        ->and($opened)->toBe([]);
});

/*
 * SBH-002. The official seal and signature artwork is not shared with, nor
 * downloadable by, accounts that do not work with ID cards.
 */
test('SBH-002 card artwork is not shared with accounts that do not render cards', function (): void {
    $employee = User::factory()->create(['status' => 'active']);
    $employee->assignRole(DailyActivityRoles::EMPLOYEE_ROLE);

    $this->actingAs($employee)->get(route('employee.portal'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('idCardTemplate', null)
            ->where('idCardTemplates', null));
});

/*
 * SEC-013 (reopened). Admin photo uploads go to private storage, are never
 * reachable by URL without authorization, and legacy public photos can be
 * moved.
 */
test('SEC-013 admin-uploaded employee photos are private and only served with authorization', function (): void {
    Storage::fake('local');
    Storage::fake('public');

    $employee = Employee::query()->create([
        'employee_number' => 'SEC013-1', 'first_name' => 'Photo', 'last_name' => 'Owner',
        'full_name' => 'Photo Owner', 'status' => 'active',
    ]);
    $photo = UploadedFile::fake()->image('face.jpg', 200, 240);
    $path = EmployeePhotoStorage::store($photo);
    $employee->update(['photo_path' => $path]);

    expect($path)->toStartWith('employee-photos/')
        ->and(Storage::disk('local')->exists($path))->toBeTrue()
        ->and(Storage::disk('public')->allFiles())->toBe([])
        // The URL the app hands out is the authorized route, never /storage/.
        ->and($employee->fresh()->photo_url)->not->toStartWith('/storage/');

    // Anonymous: redirected to sign-in. Signed in without access: refused.
    $this->get($employee->fresh()->photo_url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['status' => 'active']))->get($employee->fresh()->photo_url)->assertForbidden();
});

test('SEC-013 legacy public photos are moved to private storage', function (): void {
    Storage::fake('local');
    Storage::fake('public');

    $employee = Employee::query()->create([
        'employee_number' => 'SEC013-2', 'first_name' => 'Old', 'last_name' => 'Photo',
        'full_name' => 'Old Photo', 'status' => 'active', 'photo_path' => 'employees/photos/x/old.jpg',
    ]);
    Storage::disk('public')->put('employees/photos/x/old.jpg', 'jpeg-bytes');

    $this->artisan('employees:privatize-photos')->assertSuccessful();

    $path = $employee->fresh()->photo_path;
    expect($path)->toStartWith('employee-photos/')
        ->and(Storage::disk('local')->get($path))->toBe('jpeg-bytes')
        ->and(Storage::disk('public')->exists('employees/photos/x/old.jpg'))->toBeFalse();
});

// ── SBH-003: paid SMS spend caps ────────────────────────────────────────────

/** A configured gateway that records deliveries instead of calling a provider. */
function sbhCountingSms(): object
{
    $inner = new class(app(SystemSettingsService::class)) extends HttpSmsGateway
    {
        public int $delivered = 0;

        public function send(string $phoneNumber, string $message): bool
        {
            $this->delivered++;

            return true;
        }

        public function isConfigured(): bool
        {
            return true;
        }
    };
    app()->instance(HttpSmsGateway::class, $inner);

    return $inner;
}

test('SBH-003 every SMS sender goes through the budgeted gateway', function (): void {
    expect(app(SmsGateway::class))->toBeInstanceOf(BudgetedSmsGateway::class);
});

test('SBH-003 the global daily SMS cap is a hard stop and every attempt is metered', function (): void {
    config(['security.external_usage.sms.daily_cap' => 3, 'security.external_usage.sms.per_recipient_daily_cap' => 0]);
    $inner = sbhCountingSms();
    $sms = app(SmsGateway::class);

    $results = [];
    foreach (range(1, 5) as $i) {
        // Different recipients, as an attacker rotating cards would use.
        $results[] = $sms->send('+25191100000'.$i, "Code {$i}");
    }

    $rows = DB::table('external_service_usages')->get();
    expect($results)->toBe([true, true, true, false, false])
        ->and($inner->delivered)->toBe(3)
        ->and($rows->where('status', 'sent'))->toHaveCount(3)
        ->and($rows->where('status', 'refused')->pluck('refusal_reason')->unique()->values()->all())->toBe(['daily_cap'])
        // Usage rows never hold the number or the message.
        ->and($rows->pluck('recipient_hash')->filter(fn ($h) => str_contains((string) $h, '2519110'))->all())->toBe([]);
});

test('SBH-003 one recipient cannot be flooded even under the global cap', function (): void {
    config(['security.external_usage.sms.daily_cap' => 100, 'security.external_usage.sms.per_recipient_daily_cap' => 2]);
    $inner = sbhCountingSms();
    $sms = app(SmsGateway::class);

    $results = array_map(fn () => $sms->send('0911 000 777', 'Code'), range(1, 4));

    expect($results)->toBe([true, true, false, false])->and($inner->delivered)->toBe(2);
});

test('SBH-003 the SMS kill switch stops all paid sends', function (): void {
    config(['security.external_usage.sms.enabled' => false]);
    $inner = sbhCountingSms();

    expect(app(SmsGateway::class)->send('+251911000001', 'Code'))->toBeFalse()
        ->and(app(SmsGateway::class)->isConfigured())->toBeFalse()
        ->and($inner->delivered)->toBe(0);
});

test('SBH-004 an SMS provider key in the request URL never reaches the log', function (): void {
    $settings = Mockery::mock(SystemSettingsService::class);
    $settings->shouldReceive('get')->andReturnUsing(fn (string $group, string $key, $default = null) => match ($key) {
        'sms_provider' => 'generic_http',
        'sms_api_url' => 'https://sms.example.test/send?api_key=TOPSECRET123',
        default => $default,
    });
    Http::fake(fn () => throw new ConnectionException(
        'cURL error 7: Failed to connect for https://sms.example.test/send?api_key=TOPSECRET123',
    ));
    $logged = [];
    Log::listen(function ($event) use (&$logged): void {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    expect((new HttpSmsGateway($settings))->send('+251911000001', 'Code 123456'))->toBeFalse()
        ->and(implode("\n", $logged))->not->toContain('TOPSECRET123')->not->toContain('123456');
});

/*
 * SBH-005. The contact-verification code is submitted as `otp`, a field the
 * error logger always redacts; the generic `code` field is not accepted.
 */
test('SBH-005 the contact verification code is posted under a redacted field name', function (): void {
    $user = User::factory()->create(['status' => 'active']);
    Employee::query()->create([
        'employee_number' => 'SBH005', 'first_name' => 'Otp', 'last_name' => 'Owner',
        'full_name' => 'Otp Owner', 'status' => 'active', 'email' => $user->email,
    ]);

    $this->actingAs($user)->post(route('employee.contact.confirm'), ['field' => 'phone', 'code' => '123456'])
        ->assertSessionHasErrors('otp');

    expect(app(ErrorLoggingService::class)->sanitizeInput(['field' => 'phone', 'otp' => '123456']))
        ->not->toContain('123456');
});

test('audit log redacts one-time codes, tokens and national IDs at any depth', function (): void {
    $log = app(WriteAuditLogAction::class)->execute(
        AuditEventType::SecurityEvent,
        null,
        null,
        null,
        null,
        ['otp' => '482913', 'nested' => ['access_token' => 'tok-abc', 'national_id' => '123456784821', 'keep' => 'visible']],
    );

    $stored = json_encode($log->fresh()->new_values);
    expect($stored)->not->toContain('482913')->not->toContain('tok-abc')->not->toContain('123456784821')
        ->and($stored)->toContain('visible');
});

// ── Configuration ───────────────────────────────────────────────────────────

test('a wildcard CORS origin is ignored so credentials are never exposed to every site', function (): void {
    putenv('CORS_ALLOWED_ORIGINS=*,https://portal.example.test');
    $config = require config_path('cors.php');
    putenv('CORS_ALLOWED_ORIGINS');

    expect($config['allowed_origins'])->toBe(['https://portal.example.test'])
        ->and($config['supports_credentials'])->toBeTrue();
});

test('the production gate fails on debug mode, insecure cookies and missing SMS caps', function (): void {
    config([
        'app.debug' => true,
        'session.secure' => false,
        'security.external_usage.sms.daily_cap' => 0,
    ]);

    $this->artisan('security:production-check', ['--strict' => true])
        ->expectsOutputToContain('APP_DEBUG is off')
        ->expectsOutputToContain('SMS spend caps are set')
        ->assertFailed();
});

test('the production gate passes a hardened configuration', function (): void {
    config([
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'session.secure' => true,
        'session.http_only' => true,
        'session.same_site' => 'strict',
        'session.encrypt' => true,
        'session.driver' => 'database',
        'security.registration_enabled' => false,
        'cors.allowed_origins' => ['https://portal.example.test'],
        'logging.channels.'.config('logging.default').'.level' => 'warning',
        'security.external_usage.sms.daily_cap' => 500,
        'security.external_usage.sms.monthly_cap' => 10000,
    ]);

    $this->artisan('security:production-check', ['--strict' => true])->assertSuccessful();
});

/*
 * SBH-006. Environment files carry APP_KEY and credentials; only the example
 * may ever be tracked. Removing a file does not revoke a leaked secret, so a
 * failure here also means rotating whatever was committed.
 */
test('SBH-006 no environment file other than the example is tracked by git', function (): void {
    exec('git -C '.escapeshellarg(base_path()).' ls-files 2>&1', $files, $exit);
    if ($exit !== 0) {
        $this->markTestSkipped('git is not available in this environment.');
    }

    $tracked = array_values(array_filter($files, fn (string $file): bool => (bool) preg_match('#(^|/)\.env($|\.)#', $file) && ! str_ends_with($file, '.env.example')));

    expect($tracked)->toBe([]);
});
