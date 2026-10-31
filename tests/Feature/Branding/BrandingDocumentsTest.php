<?php

declare(strict_types=1);

use App\Actions\SystemSettings\UpdateSystemSettingsGroupAction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\BackupHealthNotification;
use App\Services\Branding\BrandingService;
use Illuminate\Auth\Notifications\ResetPassword;
use Modules\Feedback\Application\Services\OrganizationFeedbackQrService;
use Modules\Feedback\Application\Services\PublicFeedbackTargetResolver;
use Tests\Support\PublicFeedbackScenario;

/*
 * Rebrand brief §38–§40 (14–21): newly generated PDFs, reports, e-mail and
 * notifications carry the current system identity; the employee and
 * institution QR codes and their URLs are untouched by a brand change.
 */

beforeEach(function (): void {
    Permission::findOrCreate('system-settings.manageGeneral', 'web');
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');
    config(['app.name' => app(BrandingService::class)->brandName()]);
});

it('prints the official system identity on the institution QR poster under the institution name', function (): void {
    $html = view('pdf.organization-feedback-qr', [
        'qr' => 'data:image/svg+xml;base64,', 'url' => 'https://example.test/feedback/abc',
        'organization_en' => 'Bureau of Trade', 'organization_am' => 'የንግድ ቢሮ', 'logo' => null,
    ])->render();
    // The repository folder name appears in embedded font paths; that is a path, not branding.
    $html = str_replace([base_path(), str_replace(DIRECTORY_SEPARATOR, '/', base_path())], '', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    expect($html)->toContain('Bureau of Trade')
        ->toContain('Addis Ababa Integrated Employee Management & Service System (AA-IEMSS)')
        ->toContain('የአዲስ አበባ የተቀናጀ የሰራተኛ አስተዳደርና አገልግሎት ሥርዓት')
        ->not->toContain('EUISIS')
        ->not->toContain('Unified ID');
    // The institution stays the primary identity: it appears before the system line.
    expect(strpos($html, 'Bureau of Trade'))->toBeLessThan(strpos($html, 'AA-IEMSS'));
});

it('heads generated reports with the formal system line, not the former name', function (): void {
    foreach (['daily-activities/report-pdf.blade.php', 'cafeteria/exports/transaction-statement.blade.php'] as $file) {
        $source = file_get_contents(resource_path('views/'.$file));
        expect($source)->toContain('BrandingService')->not->toContain('EUISIS');
    }
});

it('brands e-mail subjects and the password reset mail with the current brand', function (): void {
    $backup = (new BackupHealthNotification('WARNING', []))->toMail($this->admin);
    expect($backup->subject)->toStartWith('Agelgay Backup & Recovery')->not->toContain('EUISIS');

    $reset = (new ResetPassword('token'))->toMail($this->admin);
    $rendered = (string) $reset->render();
    expect($rendered)->toContain('Agelgay')->not->toContain('EUISIS');
});

it('keeps employee and institution QR codes and their URLs when the brand changes', function (): void {
    $a = PublicFeedbackScenario::employeeTarget('BQR');
    $employeeToken = $a->token->token;
    $orgCode = app(OrganizationFeedbackQrService::class)->ensureActive($a->organization, $this->admin);
    $orgToken = $orgCode->public_token;

    app(UpdateSystemSettingsGroupAction::class)->execute('general', [
        'application_name' => 'Agelgay', 'application_short_name' => 'AA-IEMSS',
        'official_name_en' => 'Addis Ababa Integrated Employee Management & Service System',
        'official_name_am' => 'የአዲስ አበባ የተቀናጀ የሰራተኛ አስተዳደርና አገልግሎት ሥርዓት',
    ], $this->admin);

    expect($a->token->fresh()->token)->toBe($employeeToken)
        ->and($orgCode->fresh()->public_token)->toBe($orgToken)
        ->and(app(PublicFeedbackTargetResolver::class)->resolveEmployeeToken($employeeToken)?->employeeId)->toBe($a->employee->id)
        ->and(app(PublicFeedbackTargetResolver::class)->resolveOrganizationToken($orgToken))->not->toBeNull();
    $this->get('/service-feedback/'.$employeeToken)->assertOk();
    $this->get('/feedback/'.$orgToken)->assertOk();
});
