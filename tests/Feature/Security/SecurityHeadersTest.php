<?php

declare(strict_types=1);

use App\Models\User;

// ── INSA Annex B: Content headers, anti-clickjacking, XCTO, CSP ──────────────

test('security headers are present on authenticated pages', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/profile');

    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');
    $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
    $response->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
});

test('security headers are present on public pages', function (): void {
    $response = $this->get('/login');

    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');
});

test('X-Content-Type-Options is nosniff', function (): void {
    $response = $this->get('/login');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('X-Frame-Options prevents clickjacking', function (): void {
    $response = $this->get('/login');

    // Must be DENY (not SAMEORIGIN) for government classification
    $response->assertHeader('X-Frame-Options', 'DENY');
});

test('Content-Security-Policy header is present', function (): void {
    $response = $this->get('/login');

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->not->toBeNull()
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain("base-uri 'self'")
        ->and($csp)->toContain("form-action 'self'");
});

test('local Content-Security-Policy permits the IPv6 Vite development server', function (): void {
    $originalEnvironment = app()->environment();
    app()->detectEnvironment(fn (): string => 'local');

    try {
        $response = $this->get('/login');
        $csp = $response->headers->get('Content-Security-Policy');

        expect($csp)
            ->toContain('http://[::1]:5173')
            ->toContain('ws://[::1]:5173');
    } finally {
        app()->detectEnvironment(fn (): string => $originalEnvironment);
    }
});

test('HSTS header is not sent over plain HTTP', function (): void {
    // In the test environment requests are not HTTPS, so HSTS must be absent
    // to prevent accidental HTTP pinning in development.
    $response = $this->get('/login');

    expect($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});

test('error page does not expose stack trace', function (): void {
    // Trigger a 404 — should return generic Inertia error page, not a stack trace.
    $response = $this->get('/nonexistent-route-'.uniqid());

    $response->assertStatus(404);

    $content = $response->getContent();
    expect($content)->not->toContain('Stack trace')
        ->and($content)->not->toContain('vendor/laravel')
        ->and($content)->not->toContain('Exception in');
});

test('500 response does not expose SQL or file paths', function (): void {
    // Check that an authenticated page response does not leak server internals.
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/profile');
    $response->assertStatus(200);

    // The response body must not contain raw PHP file paths or SQL
    expect($response->getContent())
        ->not->toContain('/var/www')
        ->not->toContain('/home/')
        ->not->toContain('SQLSTATE');
});
