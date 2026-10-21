<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Branding\BrandingService;
use App\Support\Rbac\DefaultRoleMatrix;
use Illuminate\Support\Facades\DB;

/*
 * Rebrand brief §34–§35, §41 (22–24): only a settings administrator changes
 * the global brand, an Organizational Admin cannot, and the change is audited
 * without binary content.
 */

function brandPayload(array $overrides = []): array
{
    return array_merge([
        'application_name' => 'Agelgay', 'application_short_name' => 'AA-IEMSS',
        'official_name_en' => 'Addis Ababa Integrated Employee Management & Service System',
        'official_name_am' => 'የአዲስ አበባ የተቀናጀ የሰራተኛ አስተዳደርና አገልግሎት ሥርዓት',
        'organization_name' => 'Addis Ababa City Administration', 'default_dashboard_route' => 'dashboard',
    ], $overrides);
}

beforeEach(function (): void {
    foreach (['system-settings.view', 'system-settings.manageGeneral', 'dashboard.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    foreach (DefaultRoleMatrix::permissionsFor('Organizational Admin') as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Organizational Admin', 'web')->syncPermissions(DefaultRoleMatrix::permissionsFor('Organizational Admin'));
    Role::findOrCreate('Settings Manager', 'web')->syncPermissions(['system-settings.view', 'system-settings.manageGeneral']);
});

it('refuses the global brand change to a user without the settings permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('system-settings.general.update'), brandPayload(['application_name' => 'Hijacked']))->assertForbidden();
    expect(app(BrandingService::class)->brandName())->toBe('Agelgay');
});

it('refuses the global brand change to an Organizational Admin', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Organizational Admin');

    expect(in_array('system-settings.manageGeneral', DefaultRoleMatrix::permissionsFor('Organizational Admin'), true))->toBeFalse();
    $this->actingAs($user)->patch(route('system-settings.general.update'), brandPayload(['application_name' => 'My Bureau System']))->assertForbidden();
    expect(app(BrandingService::class)->brandName())->toBe('Agelgay');
});

it('lets a settings administrator change the brand and audits it without the uploaded files', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Settings Manager');

    $this->actingAs($user)
        ->patch(route('system-settings.general.update'), brandPayload(['application_name' => 'Agelgay Pilot', 'application_short_name' => 'AA-IEMSS-P']))
        ->assertRedirect();

    expect(app(BrandingService::class)->brandName())->toBe('Agelgay Pilot')
        ->and(app(BrandingService::class)->shortName())->toBe('AA-IEMSS-P');

    $audit = DB::table('audit_logs')->where('event_type', 'setting_updated')->latest('id')->first();
    expect($audit)->not->toBeNull();
    $payload = json_encode($audit);
    expect($payload)->toContain('Agelgay Pilot')
        ->and($payload)->not->toContain('data:image')
        ->and(strlen($payload))->toBeLessThan(20000);
});
