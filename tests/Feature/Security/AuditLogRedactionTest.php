<?php

declare(strict_types=1);

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\AuditLog;

it('never stores credential, identity or token values in the audit log, at any depth', function (): void {
    $sensitive = [
        'password' => 'Secret#123',
        'current_password' => 'Old#123',
        'national_id' => '1234567890123456',
        'phone_number' => '+251911000000',
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb', 'cccc-dddd'],
        'otp_code' => '482913',
        'access_token' => 'plain-access-token',
        'client_secret' => 'plain-client-secret',
        'Authorization' => 'Bearer plain-bearer',
        'token' => 'plain-reset-token',
    ];

    $log = app(WriteAuditLogAction::class)->execute(
        AuditEventType::UserUpdated,
        null,
        oldValues: $sensitive,
        newValues: ['name' => 'Abebe', 'nested' => $sensitive],
    );

    $stored = AuditLog::query()->findOrFail($log->getKey());
    $json = json_encode([$stored->old_values, $stored->new_values], JSON_UNESCAPED_UNICODE);

    foreach ($sensitive as $key => $value) {
        expect($stored->old_values[$key])->toBe('[REDACTED]')
            ->and($stored->new_values['nested'][$key])->toBe('[REDACTED]');
        foreach ((array) $value as $plain) {
            expect($json)->not->toContain($plain);
        }
    }

    expect($stored->new_values['name'])->toBe('Abebe');
});
