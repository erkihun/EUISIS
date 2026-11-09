<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Security\UserSessionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The Devices tab of profile settings (docs/session-management.md §6). Rows
 * are seeded straight into the `sessions` table the way Laravel's database
 * handler writes them; the driver is switched to `database` so the registry
 * reports the list as supported.
 */

const IPHONE_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const WINDOWS_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

function seedSession(User $user, string $agent = WINDOWS_AGENT, ?int $lastActivity = null, string $ip = '10.0.0.5'): string
{
    $id = Str::random(40);

    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => (string) $user->getKey(),
        'ip_address' => $ip,
        'user_agent' => $agent,
        'payload' => base64_encode(serialize([])),
        'last_activity' => $lastActivity ?? now()->getTimestamp(),
    ]);

    return $id;
}

beforeEach(function (): void {
    config(['session.driver' => 'database']);
});

test('the profile lists the account\'s own live sessions and nobody else\'s', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    seedSession($user, IPHONE_AGENT, ip: '10.0.0.9');
    seedSession($user, WINDOWS_AGENT, now()->subMinutes((int) config('session.lifetime') + 5)->getTimestamp()); // expired
    seedSession($other, WINDOWS_AGENT);

    $this->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Edit')
            ->where('devices.supported', true)
            ->has('devices.sessions', 1)
            ->where('devices.sessions.0.browser', 'Safari')
            ->where('devices.sessions.0.os', 'iOS')
            ->where('devices.sessions.0.device', 'phone')
            ->where('devices.sessions.0.ip_address', '10.0.0.9')
            ->where('devices.sessions.0.current', false)
            ->has('devices.recent_sign_ins')
            ->has('security.two_factor_enabled'));
});

test('the device list is reported unavailable without server-side sessions', function (): void {
    config(['session.driver' => 'array']);
    $user = User::factory()->create();
    seedSession($user);

    $this->actingAs($user)
        ->get('/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->where('devices.supported', false)
            ->has('devices.sessions', 0));
});

test('the owner can end one of their other sessions, and it is audited without the id', function (): void {
    $user = User::factory()->create();
    $id = seedSession($user, IPHONE_AGENT, ip: '10.0.0.9');

    $this->actingAs($user)
        ->delete("/profile/devices/{$id}")
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('flash.type', 'success');

    expect(DB::table('sessions')->where('id', $id)->exists())->toBeFalse();

    $log = AuditLog::query()->where('event_type', AuditEventType::SessionRevoked->value)->latest('created_at')->first();
    expect($log)->not->toBeNull()
        ->and($log->reason)->toBe(UserSessionRegistry::REASON_REVOKED_BY_USER)
        ->and($log->new_values)->toBe(['ip_address' => '10.0.0.9', 'device' => 'Safari on iOS'])
        ->and(json_encode($log->new_values))->not->toContain($id);
});

test('a session of another account cannot be ended from the profile', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $id = seedSession($other);

    $this->actingAs($user)
        ->delete("/profile/devices/{$id}")
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('flash.type', 'error');

    expect(DB::table('sessions')->where('id', $id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event_type', AuditEventType::SessionRevoked->value)->exists())->toBeFalse();
});

test('a malformed session handle is not found', function (): void {
    $this->actingAs(User::factory()->create())
        ->delete('/profile/devices/not-a-session-id!')
        ->assertNotFound();
});

test('the owner can end every other session at once', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $mine = [seedSession($user), seedSession($user, IPHONE_AGENT)];
    $theirs = seedSession($other);

    $this->actingAs($user)
        ->delete('/profile/devices')
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('flash.type', 'success');

    expect(DB::table('sessions')->whereIn('id', $mine)->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', $theirs)->exists())->toBeTrue();

    $log = AuditLog::query()->where('event_type', AuditEventType::SessionRevoked->value)->latest('created_at')->first();
    expect($log?->reason)->toBe(UserSessionRegistry::REASON_REVOKED_OTHER_DEVICES)
        ->and($log?->new_values)->toBe(['sessions' => 2]);
});

test('recent sign-ins come from the login audit rows', function (): void {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->actingAs($user)
        ->get('/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->has('devices.recent_sign_ins', 1)
            ->has('devices.recent_sign_ins.0.at')
            ->has('devices.recent_sign_ins.0.device'));
});
