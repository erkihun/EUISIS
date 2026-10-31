<?php

declare(strict_types=1);

beforeEach(function () {
    config()->set('court.compliance.security.app_headers_enabled', true);
});

test('responses include a traceable request identifier and baseline security headers', function () {
    $response = $this->getJson('/court/api/health');

    $response
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

    expect($response->headers->get('X-Request-ID'))
        ->toBeString()
        ->toMatch('/^[0-9a-f-]{36}$/i');
});

test('a valid caller request identifier is preserved', function () {
    $requestId = 'external-request-12345';

    $this->withHeader('X-Request-ID', $requestId)
        ->getJson('/court/api/health')
        ->assertOk()
        ->assertHeader('X-Request-ID', $requestId);
});

test('hsts is opt in and is emitted only for secure requests', function () {
    config()->set('court.compliance.security.hsts_enabled', true);

    $this->getJson('https://localhost/api/health')
        ->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});
