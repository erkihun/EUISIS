<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\MfaController;
use App\Models\AuditLog;
use App\Models\CafeteriaProvider;
use App\Models\Provider;
use App\Models\ProviderType;
use App\Models\ProviderUser;
use App\Models\ServiceType;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Security\SessionActivityService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;

/*
|--------------------------------------------------------------------------
| Session idle timeout — end to end
|--------------------------------------------------------------------------
| See docs/session-management.md. The idle clock is per session, server-side,
| moved only by meaningful requests. Each test sets a 30-minute timeout.
*/

beforeEach(function (): void {
    config(['security.session.idle_timeout_minutes' => 30]);
});

function stUser(array $attributes = []): User
{
    return User::factory()->create(array_merge(['status' => 'active'], $attributes));
}

function stProviderUser(): ProviderUser
{
    ServiceType::query()->firstOrCreate(['code' => 'cafeteria'], ['name_en' => 'Cafeteria Service', 'is_active' => true]);
    $type = ProviderType::query()->firstOrCreate(['code' => 'CAFETERIA'], ['name_en' => 'Cafeteria', 'is_active' => true]);
    $provider = Provider::query()->create([
        'provider_code' => 'ST-CAF', 'provider_type_id' => $type->id, 'name_en' => 'Session Test Cafeteria', 'status' => 'active',
    ]);
    DB::table('provider_services')->insert([
        'id' => (string) Str::uuid7(), 'provider_id' => $provider->id,
        'service_type_id' => ServiceType::query()->where('code', 'cafeteria')->value('id'),
        'status' => 'active', 'enabled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    CafeteriaProvider::query()->create([
        'provider_id' => $provider->id, 'code' => 'ST-CAF', 'name_en' => 'Session Test Cafeteria', 'is_active' => true,
    ]);

    return ProviderUser::query()->create([
        'provider_id' => $provider->id, 'name' => 'Session Operator', 'email' => 'session-operator@example.test',
        'username' => 'session-operator', 'password' => Hash::make('password'),
        'provider_role' => 'operator', 'status' => 'active', 'portal_enabled' => true,
    ]);
}

/** Enforce CSRF in tests (Laravel skips it while running unit tests). */
function stEnforceCsrf(): void
{
    app()->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });
}

// ── Idle policy ─────────────────────────────────────────────────────────────

test('1 the configured timeout is respected: just inside is fine, past it the session ends', function (): void {
    $this->actingAs(stUser())->get(route('profile.edit'))->assertOk();

    $this->travel(29)->minutes();
    $this->get(route('profile.edit'))->assertOk();

    $this->travel(31)->minutes();
    $this->get(route('profile.edit'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('2 and 13 a meaningful request resets the idle clock, so an active user is never timed out', function (): void {
    $this->actingAs(stUser())->get(route('profile.edit'))->assertOk();

    // Three hours of work, a request every 20 minutes: never idle for 30.
    foreach (range(1, 9) as $step) {
        $this->travel(20)->minutes();
        $this->get(route('profile.edit'))->assertOk();
    }

    $this->assertAuthenticated();
});

test('3 and security: background polling never keeps an idle session alive', function (): void {
    $this->actingAs(stUser())->get(route('profile.edit'))->assertOk();

    // The notification bell polls every few minutes; the dashboard marks its
    // refresh passive; the status check is passive by route. None count.
    foreach (range(1, 5) as $step) {
        $this->travel(5)->minutes();
        $this->getJson(route('notifications.feed'))->assertOk();
        $this->getJson(route('session.status'))->assertOk();
        $this->get(route('profile.edit'), ['X-Activity' => 'passive'])->assertOk();
    }

    $this->travel(6)->minutes(); // 31 minutes since the last meaningful request
    $this->get(route('profile.edit'))->assertRedirect(route('login'));
});

test('4 5 6 an idle session is invalidated: signed out, id and CSRF token destroyed, not revived', function (): void {
    $this->actingAs(stUser())->get(route('profile.edit'))->assertOk();
    $session = app('session.store');
    $oldId = $session->getId();
    $oldToken = $session->token();
    $session->put(MfaController::SESSION_VERIFIED_AT, now()->timestamp);
    // The browser keeps sending this session's cookie.
    $this->withCookie(config('session.cookie'), $oldId);
    $this->get(route('profile.edit'))->assertOk();

    $this->travel(31)->minutes();
    $this->get(route('profile.edit'))->assertRedirect(route('login'));

    $this->assertGuest();
    expect($session->getId())->not->toBe($oldId)
        ->and($session->token())->not->toBe($oldToken)
        // 18: MFA verification belonged to the ended session and is gone.
        ->and($session->has(MfaController::SESSION_VERIFIED_AT))->toBeFalse()
        // Restoring the old cookie finds nothing: the old session was destroyed.
        ->and($session->getHandler()->read($oldId))->toBe('');

    // Replaying the old cookie does not bring it back.
    $this->withCookie(config('session.cookie'), $oldId)->get(route('profile.edit'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('7 and 8 administrators and employees are sent to /login (the employee sign-in)', function (): void {
    $this->actingAs(stUser())->get(route('employee.portal'))->assertOk();
    $this->travel(31)->minutes();
    $this->get(route('employee.portal'))->assertRedirect(route('login'));

    // /employee/login is a shortcut to the same page.
    $this->get(route('employee.login'))->assertRedirect(route('login'));
});

test('9 an idle provider portal session goes to the provider login', function (): void {
    $this->actingAs(stProviderUser(), 'provider')->get(route('provider.portal.dashboard'))->assertOk();

    $this->travel(31)->minutes();
    $this->get(route('provider.portal.dashboard'))->assertRedirect(route('provider.portal.login'));
    $this->assertGuest('provider');

    $this->get(route('provider.portal.login'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('sessionNotice', 'idle_timeout'));
});

test('10 the login page states the inactivity reason, in English and Amharic', function (): void {
    $this->actingAs(stUser())->get(route('profile.edit'));
    $this->travel(31)->minutes();
    $this->get(route('profile.edit'))->assertRedirect(route('login'));

    $this->get(route('login'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('sessionNotice', 'idle_timeout'));

    // Shown once: a stale message never reappears on the next visit.
    $this->get(route('login'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('sessionNotice', null));

    $en = file_get_contents(resource_path('js/i18n/en/auth.ts'));
    $am = file_get_contents(resource_path('js/i18n/am/auth.ts'));
    expect($en)->toContain('Your session expired due to inactivity. Please sign in again.')
        ->and($am)->toContain('በተወሰነው ጊዜ ውስጥ እንቅስቃሴ ስላልነበረ የክፍለ ጊዜዎ ጊዜ አልፏል። እባክዎ እንደገና ይግቡ።');
});

test('11 explicit logout never shows the timeout message', function (): void {
    $user = stUser();
    $this->actingAs($user)->post(route('logout'))->assertRedirect('/');

    $this->assertGuest();
    $this->get(route('login'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('sessionNotice', null));
    expect(AuditLog::query()->where('event_type', 'user_logged_out')->where('actor_user_id', $user->id)->exists())->toBeTrue();
});

test('12 a 403 stays a 403: no timeout message, still signed in', function (): void {
    $this->actingAs(stUser())->get(route('users.index'))->assertForbidden();

    $this->assertAuthenticated();
    expect(session(SessionActivityService::NOTICE_KEY))->toBeNull();
});

test('14 tabs share one session: activity in one tab keeps the other signed in', function (): void {
    $this->actingAs(stUser())->get(route('profile.edit'))->assertOk();

    $this->travel(25)->minutes();
    $this->postJson(route('session.activity'))->assertOk(); // tab A: typing heartbeat

    $this->travel(25)->minutes();
    $this->get(route('employee.portal'))->assertOk(); // tab B: 50 min after its own last view
});

test('15 separate device sessions keep independent idle clocks', function (): void {
    $service = app(SessionActivityService::class);
    $laptop = new Store('laptop', new ArraySessionHandler(60));
    $phone = new Store('phone', new ArraySessionHandler(60));
    $service->touch($laptop);
    $service->touch($phone);

    $this->travel(20)->minutes();
    $service->touch($laptop); // only the laptop is used

    $this->travel(15)->minutes();
    expect($service->isIdleExpired($laptop))->toBeFalse()
        ->and($service->isIdleExpired($phone))->toBeTrue();
});

// ── Login, password change, MFA, forced change ──────────────────────────────

test('16 login regenerates the session id, starts the idle clock and issues no remember-me cookie', function (): void {
    $user = stUser(['password' => Hash::make('Correct-Horse-9')]);
    $this->get(route('login'));
    $before = app('session.store')->getId();

    $response = $this->post(route('login'), ['email' => $user->email, 'password' => 'Correct-Horse-9', 'remember' => true]);

    $this->assertAuthenticatedAs($user);
    expect(app('session.store')->getId())->not->toBe($before)
        ->and(app('session.store')->get(SessionActivityService::LAST_ACTIVITY_KEY))->toBe(now()->timestamp)
        ->and(collect($response->headers->getCookies())->map->getName()->filter(fn ($n) => str_starts_with($n, 'remember_'))->all())->toBe([])
        ->and(AuditLog::query()->where('event_type', 'user_logged_in')->where('actor_user_id', $user->id)->exists())->toBeTrue();
});

test('17 a password change signs out other sessions, with its own reason', function (): void {
    $user = stUser();
    $this->actingAs($user)->get(route('profile.edit'))->assertOk();

    // The password is changed from another device.
    $user->forceFill(['password' => Hash::make('Another-Device-7')])->save();

    $this->get(route('profile.edit'))->assertRedirect(route('login'));
    $this->assertGuest();
    $this->get(route('login'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('sessionNotice', 'password_changed'));
    expect(AuditLog::query()->where('event_type', 'session_revoked')->exists())->toBeTrue();
});

test('17 the session that changed the password stays signed in', function (): void {
    $user = stUser(['password' => Hash::make('Old-Password-1')]);
    $this->actingAs($user)->get(route('profile.edit'))->assertOk();

    $this->put(route('password.update'), [
        'current_password' => 'Old-Password-1',
        'password' => 'New-Password-2!x',
        'password_confirmation' => 'New-Password-2!x',
    ])->assertSessionHasNoErrors();

    $this->get(route('profile.edit'))->assertOk();
    $this->assertAuthenticatedAs($user);
});

test('19 the heartbeat does not get past a forced password change', function (): void {
    $user = stUser(['must_change_password' => true]);

    $this->actingAs($user)->postJson(route('session.activity'))->assertForbidden();
    $this->get(route('profile.edit'))->assertRedirect(route('password.forced'));
});

// ── Heartbeat / status ──────────────────────────────────────────────────────

test('the heartbeat counts as activity; the status check does not', function (): void {
    $this->actingAs(stUser())->get(route('profile.edit'));

    $this->travel(20)->minutes();
    $this->postJson(route('session.activity'))->assertOk()->assertJson(['remaining_seconds' => 1800]);

    $this->travel(20)->minutes();
    $this->getJson(route('session.status'))->assertOk()->assertJson(['remaining_seconds' => 600]);

    $this->travel(11)->minutes();
    $this->getJson(route('session.status'))->assertUnauthorized();
});

test('security: client timestamps are ignored and a stale heartbeat cannot revive an expired session', function (): void {
    $this->actingAs(stUser())->get(route('profile.edit'));

    $this->travel(10)->minutes();
    $this->postJson(route('session.activity'), ['last_activity_at' => now()->addDay()->timestamp], ['X-Last-Activity' => (string) now()->addDay()->timestamp])
        ->assertJson(['remaining_seconds' => 1800]);

    $this->travel(31)->minutes();
    $this->postJson(route('session.activity'), ['last_activity_at' => now()->timestamp])
        ->assertUnauthorized()
        ->assertJson(['reason' => 'idle_timeout', 'redirect' => route('login')]);
    $this->assertGuest();
});

test('the idle timeout is audited without session identifiers', function (): void {
    $user = stUser();
    $this->actingAs($user)->get(route('profile.edit'));
    $sessionId = app('session.store')->getId();
    $this->travel(31)->minutes();
    $this->get(route('profile.edit'));

    $log = AuditLog::query()->where('event_type', 'session_idle_timeout')->where('actor_user_id', $user->id)->sole();
    expect(json_encode($log->toArray()))->not->toContain($sessionId);
});

test('pages share the idle policy with signed-in users only', function (): void {
    $this->get(route('login'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('session_policy', null));

    $this->actingAs(stUser())->get(route('profile.edit'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('session_policy.idle_timeout_seconds', 1800)
            ->where('session_policy.warning_seconds', 300)
            ->where('session_policy.login_url', route('login'))
            ->where('session_policy.logout_url', route('logout')));
});

// ── System Settings ─────────────────────────────────────────────────────────

function stSecurityAdmin(): User
{
    Permission::findOrCreate('system-settings.view', 'web');
    Permission::findOrCreate('system-settings.manageSecurity', 'web');
    $user = stUser();
    $user->givePermissionTo(['system-settings.view', 'system-settings.manageSecurity']);

    return $user;
}

function stSecurityPayload(array $overrides = []): array
{
    return array_merge([
        'password_min_length' => 15, 'session_timeout_minutes' => 120, 'max_upload_size_mb' => 10,
        'password_max_length' => 128,
        'password_history_count' => 5,
        'password_block_personal_info' => true,
        'password_block_common' => true,
        'password_breach_check' => true,
        'password_complexity_enabled' => true, 'max_login_attempts' => 5, 'lockout_minutes' => 15,
        'password_expiry_days' => 90, 'mfa_enabled' => true, 'mfa_required_for_all' => false,
        'mfa_required_role_ids' => [], 'force_https' => false, 'maintenance_banner_enabled' => false,
        'maintenance_banner_message_en' => null, 'maintenance_banner_message_am' => null,
        'allowed_file_types' => ['jpg', 'png'], 'allowed_upload_mime_types' => ['image/jpeg', 'image/png'],
        'audit_retention_days' => 365, 'sensitive_export_requires_reason' => true,
        'api_rate_limit_per_minute' => 120, 'verification_rate_limit_per_minute' => 120,
    ], $overrides);
}

test('20 invalid timeout values are rejected', function (mixed $value): void {
    $this->actingAs(stSecurityAdmin())
        ->patch(route('system-settings.security.update'), stSecurityPayload(['session_timeout_minutes' => $value]))
        ->assertSessionHasErrors('session_timeout_minutes');
})->with([0, -5, 4, 1441, 'abc', null]);

test('21 a saved timeout applies on the next request, and storage always outlives it', function (): void {
    $this->actingAs(stSecurityAdmin())
        ->patch(route('system-settings.security.update'), stSecurityPayload(['session_timeout_minutes' => 15]))
        ->assertSessionHasNoErrors();

    // What every request does at boot, with the settings cache already cleared by the save.
    (new ReflectionMethod(AppServiceProvider::class, 'applyRuntimeSystemSettings'))
        ->invoke(app()->getProvider(AppServiceProvider::class));

    expect(config('security.session.idle_timeout_minutes'))->toBe(15)
        ->and(config('session.lifetime'))->toBe(15 + 30);

    $this->get(route('profile.edit'))->assertOk();
    $this->travel(16)->minutes();
    $this->get(route('profile.edit'))->assertRedirect(route('login'));
});

// ── 419 handling ────────────────────────────────────────────────────────────

test('22 and 23 a stale token on a live session goes back to a fresh page and nothing is replayed', function (): void {
    stEnforceCsrf();
    $user = stUser(['name' => 'Original Name']);
    $this->actingAs($user)->get(route('profile.edit'));

    $this->from(route('profile.edit'))
        ->patch(route('profile.update'), ['name' => 'Changed', 'email' => $user->email, '_token' => 'stale'])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('warning');

    expect($user->fresh()->name)->toBe('Original Name');
    $this->assertAuthenticated();
    expect(session(SessionActivityService::NOTICE_KEY))->toBe('page_expired');
});

test('22 a 419 after the session is gone goes to sign-in with the inactivity reason', function (): void {
    stEnforceCsrf();

    // No session at all (storage expired): a form posted to a signed-in route.
    $this->patch(route('profile.update'), ['name' => 'X', '_token' => 'stale'])->assertRedirect(route('login'));
    $this->get(route('login'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('sessionNotice', 'idle_timeout'));
});

test('22 a 419 on a guest form (login page left open) just asks to try again', function (): void {
    stEnforceCsrf();

    $this->from(route('login'))->post(route('login'), ['email' => 'a@example.test', 'password' => 'x', '_token' => 'stale'])
        ->assertRedirect(route('login'));
    $this->get(route('login'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('sessionNotice', 'page_expired'));
});

test('22 JSON callers get a typed 419, never a raw error page', function (): void {
    stEnforceCsrf();
    $this->actingAs(stUser())->get(route('profile.edit'));

    $this->postJson(route('notifications.read-all'), [], ['X-CSRF-TOKEN' => 'stale'])
        ->assertStatus(419)
        ->assertJson(['reason' => 'page_expired']);
});

// ── Configuration ───────────────────────────────────────────────────────────

test('24 session cookies are secure over HTTPS by default, HttpOnly and SameSite=lax', function (): void {
    // As a fresh deployment would read it: only APP_URL set, no overrides.
    $load = function (string $appUrl): array {
        $keys = ['APP_URL', 'SESSION_SECURE_COOKIE', 'SESSION_SAME_SITE', 'SESSION_HTTP_ONLY'];
        $saved = [];
        foreach ($keys as $key) {
            $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
        $_ENV['APP_URL'] = $_SERVER['APP_URL'] = $appUrl;

        try {
            return require config_path('session.php');
        } finally {
            foreach ($saved as $key => [$env, $server, $put]) {
                unset($_ENV[$key], $_SERVER[$key]);
                if ($env !== null) {
                    $_ENV[$key] = $env;
                }
                if ($server !== null) {
                    $_SERVER[$key] = $server;
                }
                $put === false ? putenv($key) : putenv("{$key}={$put}");
            }
        }
    };

    expect($load('https://euisis.example.gov.et')['secure'])->toBeTrue()
        ->and($load('http://127.0.0.1:8000')['secure'])->toBeFalse()
        ->and($load('https://euisis.example.gov.et')['same_site'])->toBe('lax')
        ->and($load('https://euisis.example.gov.et')['http_only'])->toBeTrue();
});

test('24 the production gate requires shared session storage', function (): void {
    config(['session.driver' => 'file']);

    $this->artisan('security:production-check', ['--strict' => true])
        ->expectsOutputToContain('Session storage is shared')
        ->assertFailed();
});

test('25 database session storage honours the derived lifetime', function (): void {
    $handler = new DatabaseSessionHandler(DB::connection(), 'sessions', (int) config('session.lifetime'), app());
    $id = Str::random(40);
    $handler->write($id, 'payload');

    $this->travel((int) config('session.lifetime') - 1)->minutes();
    expect($handler->read($id))->toBe('payload');

    $this->travel(2)->minutes();
    expect($handler->read($id))->toBe('');
    $handler->gc((int) config('session.lifetime') * 60);
    expect(DB::table('sessions')->where('id', $id)->exists())->toBeFalse()
        ->and((int) config('session.lifetime'))->toBeGreaterThan((int) config('security.session.idle_timeout_minutes'));
});
