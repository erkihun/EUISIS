<?php

declare(strict_types=1);

use App\Actions\SystemSettings\UpdateSystemSettingsGroupAction;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Branding\BrandingService;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Rebrand brief §36 (1–7): the product identity is resolved in one place —
 * System Settings over config over nothing — and institution branding never
 * replaces it.
 */

const BRAND_OFFICIAL_AM = 'የአዲስ አበባ የተቀናጀ የሰራተኛ አስተዳደርና አገልግሎት ሥርዓት';

beforeEach(function (): void {
    Permission::findOrCreate('system-settings.manageGeneral', 'web');
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');
});

it('resolves Addis One, AA-IEMSS and both official names by default', function (): void {
    $branding = app(BrandingService::class);

    expect($branding->brandName())->toBe('Addis One')
        ->and($branding->shortName())->toBe('AA-IEMSS')
        ->and($branding->officialNameEn())->toBe('Addis Ababa Integrated Employee Management & Service System')
        ->and($branding->officialNameAm())->toBe(BRAND_OFFICIAL_AM)
        ->and($branding->officialName('am'))->toBe(BRAND_OFFICIAL_AM)
        ->and($branding->officialName('en'))->toBe('Addis Ababa Integrated Employee Management & Service System')
        ->and(SystemSettingsRegistry::definitions()['general']['application_name']['default'])->toBe('Addis One')
        ->and(SystemSettingsRegistry::definitions()['general']['application_short_name']['default'])->toBe('AA-IEMSS')
        ->and(config('branding.short_name'))->toBe('AA-IEMSS');
});

it('shares the branding with every page and puts the brand in the document title', function (): void {
    $response = $this->get('/login')->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->where('branding.brandName', 'Addis One')
        ->where('branding.shortName', 'AA-IEMSS')
        ->where('branding.officialNameEn', 'Addis Ababa Integrated Employee Management & Service System')
        ->where('branding.officialNameAm', BRAND_OFFICIAL_AM)
        ->has('branding.logoUrl')
        ->has('branding.faviconUrl'));
    $response->assertSee('<title inertia>Addis One</title>', false)
        ->assertSee('<meta name="application-name" content="Addis One">', false)
        ->assertDontSee('EUISIS');
});

it('prefers System Settings and treats a blank setting as unset', function (): void {
    app(UpdateSystemSettingsGroupAction::class)->execute('general', [
        'application_name' => 'City HR Platform', 'application_short_name' => 'CHP',
        'official_name_en' => 'City Human Resource Platform', 'official_name_am' => 'የከተማ የሰው ኃይል መድረክ',
    ], $this->admin);

    $branding = app(BrandingService::class);
    expect($branding->brandName())->toBe('City HR Platform')
        ->and($branding->shortName())->toBe('CHP')
        ->and($branding->officialNameEn())->toBe('City Human Resource Platform')
        ->and($branding->officialNameAm())->toBe('የከተማ የሰው ኃይል መድረክ');

    DB::table('system_settings')->where('group', 'general')->whereIn('key', ['application_name', 'official_name_am'])->update(['value' => '']);
    app(SystemSettingsService::class)->clearCache();

    expect(app(BrandingService::class)->brandName())->toBe('Addis One')
        ->and(app(BrandingService::class)->officialNameAm())->toBe(BRAND_OFFICIAL_AM)
        ->and(app(BrandingService::class)->shortName())->toBe('CHP');

    // Without any settings row the config layer decides.
    DB::table('system_settings')->where('group', 'general')->where('key', 'application_short_name')->delete();
    app(SystemSettingsService::class)->clearCache();
    config(['branding.short_name' => 'ENV-SHORT']);
    expect(app(BrandingService::class)->shortName())->toBe('AA-IEMSS'); // registry default still wins over config while the registry has one
});

it('invalidates the shared settings cache when the brand changes, so every node sees the new name', function (): void {
    expect(app(BrandingService::class)->brandName())->toBe('Addis One');
    expect(app(SystemSettingsService::class)->get('general', 'application_name'))->toBe('Addis One');

    app(UpdateSystemSettingsGroupAction::class)->execute('general', ['application_name' => 'Renamed Brand'], $this->admin);

    // No manual cache clearing: the action must have dropped the shared cache entries.
    expect(app(BrandingService::class)->brandName())->toBe('Renamed Brand')
        ->and($this->get('/login')->viewData('page')['props']['branding']['brandName'])->toBe('Renamed Brand');
});

it('keeps the global brand when an institution has its own name and logo', function (): void {
    $type = OrganizationType::query()->firstOrCreate(['code' => 'BR-TYPE'], ['name_en' => 'Bureau']);
    Organization::query()->create([
        'organization_type_id' => $type->id, 'code' => 'BR-ORG', 'name_en' => 'Bureau of Trade', 'name_am' => 'የንግድ ቢሮ',
        'status' => 'active', 'logo_path' => 'organizations/logos/bureau.png',
    ]);

    $branding = app(BrandingService::class);
    expect($branding->brandName())->toBe('Addis One')
        ->and($branding->officialNameEn())->toBe('Addis Ababa Integrated Employee Management & Service System')
        ->and($branding->logoUrl())->toBeNull();
    $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('branding.brandName', 'Addis One')
        ->where('branding.logoUrl', null));
});
