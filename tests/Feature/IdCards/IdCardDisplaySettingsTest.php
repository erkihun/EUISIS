<?php

declare(strict_types=1);

use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\SystemSetting;
use App\Services\IdCards\CardQrPayloadService;
use App\Services\IdCards\IdCardLayoutSettingsService;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;

it('registers every configurable ID card visibility field', function (): void {
    $definitions = SystemSettingsRegistry::group(SystemSettingsRegistry::GROUP_ID_CARDS);

    foreach ([
        'show_photo',
        'show_full_name_en',
        'show_full_name_am',
        'show_employee_number',
        'show_card_number',
        'show_organization',
        'show_organization_unit',
        'show_position',
        'show_job_grade',
        'show_employment_status',
        'show_issue_date',
        'show_expiry_date',
        'show_signature',
        'show_qr',
        'show_return_notice',
        'show_emergency_contact',
    ] as $key) {
        expect($definitions)->toHaveKey($key)
            ->and($definitions[$key]['type'])->toBe('boolean')
            ->and($definitions[$key]['is_public'])->toBeTrue();
    }
});

it('maps disabled visibility settings into the shared render layout', function (): void {
    foreach (['show_photo', 'show_organization_unit', 'show_qr'] as $key) {
        $definition = SystemSettingsRegistry::definition(SystemSettingsRegistry::GROUP_ID_CARDS, $key);

        SystemSetting::query()->create([
            'group' => SystemSettingsRegistry::GROUP_ID_CARDS,
            'key' => $key,
            'value' => 'false',
            'type' => 'boolean',
            'label_en' => $definition['label_en'],
            'label_am' => $definition['label_am'],
            'default_value' => 'true',
            'is_public' => true,
        ]);
    }

    app(SystemSettingsService::class)->clearCache();
    $layout = app(IdCardLayoutSettingsService::class)->get();

    expect($layout->showPhoto)->toBeFalse()
        ->and($layout->showOrganizationUnit)->toBeFalse()
        ->and($layout->showQr)->toBeFalse();
});

it('does not rotate the QR reference when display settings change', function (): void {
    $employee = Employee::query()->create([
        'employee_number' => 'EMP-DISPLAY-'.uniqid(),
        'first_name' => 'Display',
        'last_name' => 'Employee',
        'full_name' => 'Display Employee',
        'status' => EmployeeStatus::Active,
    ]);

    $card = IdCard::query()->create([
        'employee_id' => $employee->id,
        'card_number' => 'CARD-DISPLAY-'.uniqid(),
        'status' => CardStatus::Active,
        'expires_at' => now()->addYear(),
        'token_version' => 1,
        'is_current' => true,
    ]);

    $qr = app(CardQrPayloadService::class);
    $qr->ensurePublicReference($card);
    $originalReference = $card->fresh()->public_card_uuid;

    $definition = SystemSettingsRegistry::definition(SystemSettingsRegistry::GROUP_ID_CARDS, 'show_job_grade');
    SystemSetting::query()->create([
        'group' => SystemSettingsRegistry::GROUP_ID_CARDS,
        'key' => 'show_job_grade',
        'value' => 'false',
        'type' => 'boolean',
        'label_en' => $definition['label_en'],
        'label_am' => $definition['label_am'],
        'default_value' => 'true',
        'is_public' => true,
    ]);

    expect($card->fresh()->public_card_uuid)->toBe($originalReference)
        ->and($card->fresh()->token_version)->toBe(1);
});
