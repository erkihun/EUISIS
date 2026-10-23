<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

// ── INSA Annex B: Rate limiting, account lockout ──────────────────────────────

beforeEach(function (): void {
    // Clear rate limiter buckets before each test so they don't bleed between tests
    RateLimiter::clear('login');
    app()->setLocale('en');
});

test('login is rate limited after 5 failed attempts', function (): void {
    $user = User::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);
    }

    // The 6th attempt should return a validation error containing the throttle message
    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))
        ->toMatch('/Too many|attempts/i'); // matches auth.throttle translation key
});

test('session regenerates on successful login', function (): void {
    $user = User::factory()->create();

    $sessionBefore = session()->getId();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $sessionAfter = session()->getId();

    // Session ID must change after login to prevent session fixation
    expect($sessionAfter)->not->toBe($sessionBefore);
});

test('logout invalidates session', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout');

    $this->assertGuest();
});

test('forgot-password endpoint has rate limiting', function (): void {
    // Hit the forgot-password endpoint 5 times — 6th should be throttled
    for ($i = 0; $i < 5; $i++) {
        $this->post('/forgot-password', ['email' => 'test@test.local']);
    }

    $response = $this->post('/forgot-password', ['email' => 'test@test.local']);

    // Should be 429 (Too Many Requests)
    $response->assertStatus(429);
});

test('reset-password endpoint has rate limiting', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $this->post('/reset-password', [
            'token' => 'invalid',
            'email' => 'test@test.local',
            'password' => 'NewPass123!',
            'password_confirmation' => 'NewPass123!',
        ]);
    }

    $response = $this->post('/reset-password', [
        'token' => 'invalid',
        'email' => 'test@test.local',
        'password' => 'NewPass123!',
        'password_confirmation' => 'NewPass123!',
    ]);

    $response->assertStatus(429);
});

test('public card verification is rate limited', function (): void {
    $uuid = Str::uuid()->toString();

    // The route has throttle:30,1 — hit it 30 times rapidly
    for ($i = 0; $i < 30; $i++) {
        $this->get("/verify/card/{$uuid}");
    }

    // 31st request should be throttled
    $response = $this->get("/verify/card/{$uuid}");
    $response->assertStatus(429);
});

test('MFA challenge endpoint is rate limited', function (): void {
    $user = User::factory()->create();

    // The MFA challenge has throttle:5,1
    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($user)->post('/mfa/challenge', ['code' => '000000']);
    }

    $response = $this->actingAs($user)->post('/mfa/challenge', ['code' => '000000']);
    $response->assertStatus(429);
});
