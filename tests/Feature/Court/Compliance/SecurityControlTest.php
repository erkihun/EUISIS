<?php

declare(strict_types=1);

beforeEach(function () {
    config()->set('court.compliance.security.app_headers_enabled', true);
});


test('a valid caller request identifier is preserved', function () {
    $requestId = 'external-request-12345';

    $this->withHeader('X-Request-ID', $requestId)
        ->getJson('/court/api/health')
        ->assertOk()
        ->assertHeader('X-Request-ID', $requestId);
});

