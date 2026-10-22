<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SystemSettings\SystemSettingsRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The ID card animated on the public sign-in page shows sample photos an administrator uploads in
 * System Settings (General > Sign-in), never photos from the employee registry. They can be taken
 * down again, and nothing else can be removed through the same field.
 */

function loginPhotoPayload(array $overrides = []): array
{
    return array_merge([
        'application_name' => 'Agelgay', 'application_short_name' => 'AA-IEMSS',
        'official_name_en' => 'Addis Ababa Integrated Employee Management & Service System',
        'official_name_am' => 'የአዲስ አበባ የተቀናጀ የሰራተኛ አስተዳደርና አገልግሎት ሥርዓት',
        'organization_name' => 'Addis Ababa City Administration', 'default_dashboard_route' => 'dashboard',
    ], $overrides);
}

function loginPhotoAdmin(): User
{
    foreach (['system-settings.view', 'system-settings.manageGeneral', 'dashboard.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Settings Manager', 'web')->syncPermissions(['system-settings.view', 'system-settings.manageGeneral']);

    return User::factory()->create()->assignRole('Settings Manager');
}

function loginPhotoUrls($test): array
{
    $settings = [];
    $test->get(route('login'))->assertOk()->assertInertia(function (Assert $page) use (&$settings): void {
        $settings = $page->toArray()['props']['settings'] ?? [];
    });

    return $settings['general.login_card_photo_urls'] ?? [];
}

beforeEach(function (): void {
    Storage::fake('public');
});

it('publishes uploaded sign-in card photos to the public sign-in page, in order', function (): void {
    $admin = loginPhotoAdmin();

    expect(loginPhotoUrls($this))->toBe([]);

    $this->actingAs($admin)->patch(route('system-settings.general.update'), loginPhotoPayload([
        'login_card_photo_1' => UploadedFile::fake()->image('first.jpg', 300, 380),
        'login_card_photo_3' => UploadedFile::fake()->image('third.png', 300, 380),
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $paths = SystemSetting::query()->where('group', 'general')->whereIn('key', SystemSettingsRegistry::LOGIN_CARD_PHOTO_KEYS)->pluck('value', 'key');
    expect($paths->keys()->sort()->values()->all())->toBe(['login_card_photo_1', 'login_card_photo_3']);
    foreach ($paths as $path) {
        Storage::disk('public')->assertExists($path);
    }

    $this->app['auth']->forgetGuards();
    $urls = loginPhotoUrls($this);
    expect($urls)->toHaveCount(2)
        ->and($urls[0])->toBe('/storage/'.$paths['login_card_photo_1'])
        ->and($urls[1])->toBe('/storage/'.$paths['login_card_photo_3']);
});

it('takes a sign-in card photo down: the file is deleted, the page stops showing it, and it is audited', function (): void {
    $admin = loginPhotoAdmin();
    $this->actingAs($admin)->patch(route('system-settings.general.update'), loginPhotoPayload([
        'login_card_photo_2' => UploadedFile::fake()->image('person.jpg', 300, 380),
    ]))->assertSessionHasNoErrors();
    $path = SystemSetting::query()->where('group', 'general')->where('key', 'login_card_photo_2')->value('value');
    Storage::disk('public')->assertExists($path);

    $this->actingAs($admin)->patch(route('system-settings.general.update'), loginPhotoPayload([
        'remove_assets' => ['login_card_photo_2'],
    ]))->assertRedirect()->assertSessionHasNoErrors();

    Storage::disk('public')->assertMissing($path);
    expect(SystemSetting::query()->where('group', 'general')->where('key', 'login_card_photo_2')->value('value'))->toBeNull()
        ->and(DB::table('audit_logs')->where('event_type', 'setting_asset_removed')->exists())->toBeTrue();

    $this->app['auth']->forgetGuards();
    expect(loginPhotoUrls($this))->toBe([]);
});

it('removes nothing but sign-in card photos through remove_assets', function (): void {
    $admin = loginPhotoAdmin();

    $this->actingAs($admin)->patch(route('system-settings.general.update'), loginPhotoPayload([
        'remove_assets' => ['identity_system_logo'],
    ]))->assertSessionHasErrors('remove_assets.0');
});

it('accepts only images for sign-in card photos', function (): void {
    $admin = loginPhotoAdmin();

    $this->actingAs($admin)->patch(route('system-settings.general.update'), loginPhotoPayload([
        'login_card_photo_1' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'),
    ]))->assertSessionHasErrors('login_card_photo_1');
});

it('refuses sign-in card photo changes without the general settings permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('system-settings.general.update'), loginPhotoPayload([
        'login_card_photo_1' => UploadedFile::fake()->image('first.jpg', 300, 380),
    ]))->assertForbidden();

    expect(SystemSetting::query()->where('key', 'login_card_photo_1')->exists())->toBeFalse();
});
