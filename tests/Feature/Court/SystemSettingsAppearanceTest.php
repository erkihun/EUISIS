<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\SystemSetting;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Modules\Court\Http\Support\Ui\CourtSettingsSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/*
| System Settings > Appearance is the EUISIS section: brand colours, theme,
| density, shapes and shell switches, applied by the shell copied from EUISIS
| through the shared `settings` prop (Modules\Court\Http\Support\Ui\SharedSettings).
*/

function appearanceAdministrator(): User
{
    $user = User::factory()->create(['must_change_password' => false, 'email' => 'appearance-'.uniqid().'@example.test']);
    $user->roles()->attach(Role::query()->firstOrCreate(['name' => 'admin']));

    return $user;
}

/** @return array<string, mixed> */
function appearanceForm(array $overrides = []): array
{
    return [
        'primary_color' => '#0F5132', 'secondary_color' => '#14532D', 'accent_color' => '#B45309', 'sidebar_color' => '#0B1F3A',
        'default_theme' => 'dark', 'allow_user_theme_switching' => '0', 'enable_ui_animations' => '1',
        'table_density' => 'compact', 'button_style' => 'soft', 'card_radius' => 'lg', 'logo_position' => 'center',
        'sidebar_compact_default' => '1', 'show_breadcrumbs' => '0', 'show_language_switcher' => '1', 'sticky_table_headers' => '0',
        ...$overrides,
    ];
}

test('the appearance section has the EUISIS fields, showing the EUISIS defaults until court-ms sets its own', function () {
    SystemSetting::query()->create(['app_name' => 'Court MS']);

    $this->actingAs(appearanceAdministrator())->get(route('court.settings.system.edit', ['tab' => 'appearance']))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $fields = collect($page->toArray()['props']['settingGroups']['appearance']['fields'])->keyBy('key');

            expect($fields->keys()->all())->toBe(array_keys(CourtSettingsSchema::APPEARANCE_DEFAULTS))
                ->and($fields['primary_color'])->toMatchArray(['type' => 'color', 'value' => '#122170', 'default' => '#122170', 'label_en' => 'Primary Color'])
                ->and($fields['sidebar_color']['description_en'])->toContain('Text and icons adjust')
                ->and($fields['card_radius'])->toMatchArray(['type' => 'select', 'value' => 'xl', 'options' => ['sm', 'md', 'lg', 'xl', '2xl']])
                ->and($fields['show_breadcrumbs']['value'])->toBeTrue()
                // The old palette and custom CSS did nothing in the React screens.
                ->and($fields->has('accent_palette'))->toBeFalse()
                ->and($fields->has('custom_css'))->toBeFalse();

            return $page;
        });
});

test('saving appearance stores it and every page applies it', function () {
    $settings = SystemSetting::query()->create(['app_name' => 'Court MS']);
    $admin = appearanceAdministrator();

    $this->actingAs($admin)->patch(route('court.system-settings.appearance.update'), appearanceForm(['primary_color' => 'blue', 'card_radius' => 'huge']))
        ->assertSessionHasErrors(['primary_color', 'card_radius']);

    $this->actingAs($admin)->patch(route('court.system-settings.appearance.update'), appearanceForm())
        ->assertRedirect(route('court.settings.system.edit', ['tab' => 'appearance']))->assertSessionHasNoErrors();

    $settings->refresh();
    expect($settings->primary_color)->toBe('#0F5132')
        ->and($settings->sidebar_color)->toBe('#0B1F3A')
        ->and($settings->default_theme)->toBe('dark')
        ->and($settings->card_radius)->toBe('lg')
        ->and($settings->allow_user_theme_switching)->toBeFalse()
        ->and($settings->sidebar_compact_default)->toBeTrue();

    // The shell reads the shared settings on every page (the dashboard here). Their keys are flat, dotted names.
    $shared = $this->actingAs($admin)->get(route('court.dashboard'))->viewData('page')['props']['settings'];
    expect($shared)->toMatchArray([
        'appearance.primary_color' => '#0F5132',
        'appearance.secondary_color' => '#14532D',
        'appearance.accent_color' => '#B45309',
        'appearance.sidebar_color' => '#0B1F3A',
        'appearance.default_theme' => 'dark',
        'appearance.table_density' => 'compact',
        'appearance.button_style' => 'soft',
        'appearance.card_radius' => 'lg',
        'appearance.logo_position' => 'center',
        'appearance.allow_user_theme_switching' => false,
        'appearance.show_breadcrumbs' => false,
        'appearance.sticky_table_headers' => false,
    ]);
});

test('without a stored value the shell gets the EUISIS appearance defaults', function () {
    SystemSetting::query()->create(['app_name' => 'Court MS']);

    $shared = $this->actingAs(appearanceAdministrator())->get(route('court.dashboard'))->viewData('page')['props']['settings'];
    expect($shared)->toMatchArray([
        'appearance.primary_color' => '#122170',
        'appearance.sidebar_color' => '#FFFFFF',
        'appearance.card_radius' => 'xl',
        'appearance.show_language_switcher' => true,
    ]);
});

test('the saved message is in the language of the reader, not half in the default one', function () {
    SystemSetting::query()->create(['app_name' => 'Court MS', 'default_locale' => 'am']);
    $admin = appearanceAdministrator();

    $this->actingAs($admin)->withHeader('X-Locale', 'en')->patch(route('court.system-settings.appearance.update'), appearanceForm())->assertSessionHasNoErrors();
    // A new request starts in the default language; SetLocale (route middleware) switches it after the shared props are built.
    app()->setLocale('am');

    $flash = $this->actingAs($admin)->withHeader('X-Locale', 'en')->get(route('court.settings.system.edit', ['tab' => 'appearance']))->viewData('page')['props']['flash'];
    expect($flash['success'])->toBe('System settings updated.');
});
