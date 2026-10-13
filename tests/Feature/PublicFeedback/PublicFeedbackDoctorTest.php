<?php

declare(strict_types=1);

use App\Models\OrganizationFeedbackQrCode;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ServiceFeedback\OrganizationFeedbackQrService;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Str;
use Tests\Support\PublicFeedbackScenario;

/*
 * `public-feedback:doctor` is what an operator runs on a server where
 * "citizens cannot submit feedback": it names the switch, record or service
 * that is missing instead of leaving them to guess.
 */

it('reports a healthy module after migrations with an active institution QR code', function (): void {
    $target = PublicFeedbackScenario::employeeTarget('DOC');
    $admin = User::factory()->create();
    app(OrganizationFeedbackQrService::class)->ensureActive($target->organization, $admin);

    // One expected substring per printed line: the console test helper matches each write once.
    $this->artisan('public-feedback:doctor')
        ->expectsOutputToContain('none pending')
        ->expectsOutputToContain('Code rule for public complaints is active')
        ->expectsOutputToContain('Active institution QR codes')
        ->expectsOutputToContain('0 failed')
        ->assertSuccessful();
});

it('fails when the public form is switched off and warns when no QR code exists', function (): void {
    OrganizationFeedbackQrCode::query()->delete();
    config(['session.secure' => true, 'app.url' => 'http://feedback.example.test']);
    SystemSetting::query()->updateOrCreate(
        ['group' => SystemSettingsRegistry::GROUP_SERVICE_FEEDBACK, 'key' => 'enabled'],
        ['id' => (string) Str::uuid7(), 'value' => '0', 'type' => 'boolean', 'label_en' => 'Enabled', 'is_public' => false, 'is_system' => true],
    );
    app(SystemSettingsService::class)->clearCache();

    $this->artisan('public-feedback:doctor')
        ->expectsOutputToContain('Switched off')
        ->expectsOutputToContain('generate one from an institution')
        ->expectsOutputToContain('SESSION_SECURE_COOKIE=true but APP_URL is not https')
        ->assertFailed();
});
