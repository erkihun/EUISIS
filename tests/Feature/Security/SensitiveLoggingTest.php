<?php

declare(strict_types=1);

use App\Services\ErrorLoggingService;

/**
 * Secrets must never reach the log.
 *
 * ErrorLoggingService logs the whole request body when an exception escapes.
 * The public ID checker posts `otp` and the MFA challenge posts
 * `recovery_code`, so both have to be redacted before that happens.
 */
test('one-time and recovery secrets are redacted before logging', function (string $field): void {
    $sanitized = app(ErrorLoggingService::class)->sanitizeInput([$field => 'S3CR3T-VALUE']);

    expect($sanitized[$field])->toBe('[REDACTED]')
        ->and(json_encode($sanitized))->not->toContain('S3CR3T-VALUE');
})->with([
    'otp',
    'otp_code',
    'recovery_code',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'password',
    'api_key',
    'client_secret',
    'remember_token',
    'national_id',
]);

test('secrets nested inside the payload are redacted too', function (): void {
    $sanitized = app(ErrorLoggingService::class)->sanitizeInput([
        'wrapper' => ['otp' => '123456', 'password' => 'hunter2'],
    ]);

    expect(json_encode($sanitized))->not->toContain('123456')->not->toContain('hunter2');
});

/* Business codes must stay readable — redacting them would cost debugging. */
test('non-secret business codes are preserved', function (): void {
    $sanitized = app(ErrorLoggingService::class)->sanitizeInput(['code' => 'ORG-001']);

    expect($sanitized['code'])->toBe('ORG-001');
});
