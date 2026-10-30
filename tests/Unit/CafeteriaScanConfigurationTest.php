<?php

declare(strict_types=1);

use Modules\Cafeteria\Domain\Enums\CafeteriaUsageMode;
use Modules\Cafeteria\Http\Requests\ProcessCafeteriaQrScanRequest;

test('cafeteria usage modes are fixed subsidy modes only', function (): void {
    expect(array_map(fn (CafeteriaUsageMode $mode): string => $mode->value, CafeteriaUsageMode::cases()))
        ->toBe(['single_day', 'use_remaining_week']);
});

test('cafeteria scan request requires idempotency nonce and rejects custom amount fields', function (): void {
    $rules = (new ProcessCafeteriaQrScanRequest)->rules();

    expect($rules['scan_nonce'])->toContain('required')
        ->and($rules['requested_subsidy_amount'])->toContain('prohibited')
        ->and(json_encode($rules['usage_mode'], JSON_THROW_ON_ERROR))->not->toContain('custom_amount');
});

test('cafeteria frontend translations no longer expose custom amount usage mode', function (): void {
    $english = file_get_contents(__DIR__.'/../../resources/js/i18n/en/cafeteria.ts');
    $amharic = file_get_contents(__DIR__.'/../../resources/js/i18n/am/cafeteria.ts');

    expect($english)->not->toContain('usageModeCustomAmount')
        ->and($english)->not->toContain('custom_amount')
        ->and($amharic)->not->toContain('usageModeCustomAmount')
        ->and($amharic)->not->toContain('custom_amount');
});
