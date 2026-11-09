<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Branding\BrandingService;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\PublicSite\Domain\Models\PublicPageSection;

/*
 * Rebrand brief §30–§31, §49, §58–§60: stored legacy defaults move to the new
 * identity, administrator-chosen values and historical records stay exactly
 * as they are, the command is idempotent, and the readiness gate reports the
 * branding state.
 */

beforeEach(function (): void {
    Permission::findOrCreate('system-settings.manageGeneral', 'web');
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');
});

function legacySetting(string $group, string $key, string $value): void
{
    SystemSetting::query()->updateOrCreate(['group' => $group, 'key' => $key], ['value' => $value, 'type' => 'string']);
    app(SystemSettingsService::class)->clearCache();
}

it('moves stored legacy defaults to the current brand, keeps customised values and historical records, and is idempotent', function (): void {
    legacySetting('general', 'application_name', 'Addis Ababa Employee Unified ID & Service Platform');
    legacySetting('general', 'application_short_name', 'AA Employee ID');
    legacySetting('general', 'organization_name', 'Bureau of Trade');          // customised: must stay
    legacySetting('branding', 'app_name_en', 'EUISIS');                         // legacy demo group
    legacySetting('branding', 'app_name_am', 'ኢዩሲሲስ');
    PublicPageSection::query()->updateOrCreate(['page' => 'home', 'section_key' => 'intro'], ['title_en' => 'Unified Employee ID & Service Integration System', 'title_am' => 'የተቀናጀ የሠራተኛ መታወቂያ እና አገልግሎት ስርዓት', 'is_visible' => true, 'sort_order' => 1]);
    PublicPageSection::query()->updateOrCreate(['page' => 'home', 'section_key' => 'custom'], ['title_en' => 'EUISIS helped us', 'is_visible' => true, 'sort_order' => 2]);
    DB::table('audit_logs')->insert([
        'id' => (string) Str::uuid(), 'event_type' => 'setting_updated', 'actor_type' => 'system',
        'reason' => 'EUISIS branding set', 'old_values' => json_encode(['application_name' => 'EUISIS']), 'created_at' => now(),
    ]);

    Artisan::call('branding:migrate');
    $output = Artisan::output();

    $branding = app(BrandingService::class);
    expect($branding->brandName())->toBe('Agelgay')
        ->and($branding->shortName())->toBe('AA-IEMSS')
        ->and(app(SystemSettingsService::class)->get('general', 'organization_name'))->toBe('Bureau of Trade')
        ->and(SystemSetting::query()->where('group', 'branding')->where('key', 'app_name_en')->value('value'))->toBe('Agelgay')
        ->and(PublicPageSection::query()->where('section_key', 'intro')->value('title_en'))->toBe('Addis Ababa Integrated Employee Management & Service System')
        ->and(PublicPageSection::query()->where('section_key', 'intro')->value('title_am'))->toBe('የአዲስ አበባ የተቀናጀ የሰራተኛ አስተዳደርና አገልግሎት ሥርዓት')
        ->and(PublicPageSection::query()->where('section_key', 'custom')->value('title_en'))->toBe('EUISIS helped us')
        ->and(DB::table('audit_logs')->where('reason', 'EUISIS branding set')->exists())->toBeTrue()
        ->and($output)->toContain('NEEDS_REVIEW')->toContain('custom');

    $before = DB::table('system_settings')->orderBy('id')->get()->toArray();
    Artisan::call('branding:migrate');
    expect(DB::table('system_settings')->orderBy('id')->get()->toArray())->toEqual($before);
});

it('moves the Addis One defaults to Agelgay and አገልጋይ, also under the command name of the previous rebrand', function (): void {
    legacySetting('general', 'application_name', 'Addis One');
    legacySetting('general', 'application_name_am', 'Addis One');
    legacySetting('branding', 'app_name_en', 'Addis One');
    legacySetting('branding', 'app_name_am', 'Addis One');

    Artisan::call('branding:migrate-addis-one');

    $branding = app(BrandingService::class);
    expect($branding->brandNameEn())->toBe('Agelgay')
        ->and($branding->brandNameAm())->toBe('አገልጋይ')
        ->and($branding->brandName('am'))->toBe('አገልጋይ')
        ->and($branding->brandName('en'))->toBe('Agelgay')
        ->and(SystemSetting::query()->where('group', 'branding')->where('key', 'app_name_am')->value('value'))->toBe('አገልጋይ')
        ->and(Artisan::output())->toContain('4 setting(s) moved');
});

it('refuses to move a value onto a former name still set in the environment', function (): void {
    config(['branding.name' => 'Addis One']);
    legacySetting('general', 'application_name', 'Addis One');

    Artisan::call('branding:migrate');

    expect(app(SystemSettingsService::class)->get('general', 'application_name'))->toBe('Addis One')
        ->and(Artisan::output())->toContain('NEEDS_REVIEW')->toContain('update APP_NAME / APP_NAME_AM in .env first');
});

it('leaves a customised brand alone and reports nothing to move', function (): void {
    legacySetting('general', 'application_name', 'City HR Platform');

    Artisan::call('branding:migrate');

    expect(app(BrandingService::class)->brandName())->toBe('City HR Platform')
        ->and(Artisan::output())->toContain('0 setting(s) moved');
});

it('reports branding in the production readiness gate without blocking on a missing logo', function (): void {
    Artisan::call('production:readiness');
    $output = preg_replace('/\x1b\[[0-9;]*m/', '', Artisan::output());

    expect($output)->toContain('Branding (docs/branding.md)')
        ->toContain('Brand resolves to a current name (not a former one)')
        ->toContain('No former product name in current settings')
        ->toContain('BRAND_ASSET_REQUIRED');
});
