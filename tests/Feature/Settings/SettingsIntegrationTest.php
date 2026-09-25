<?php

declare(strict_types=1);

use App\Http\Requests\DailyActivity\UpdateDailyActivitySettingsRequest;
use App\Http\Requests\Performance\UpdatePerformanceSettingsRequest;
use App\Http\Requests\PublicSite\UpdatePublicSiteSettingsRequest;
use App\Http\Requests\Settings\UpdateAppearanceSettingsRequest;
use App\Http\Requests\Settings\UpdateEmailSettingsRequest;
use App\Http\Requests\Settings\UpdateGeneralSettingsRequest;
use App\Http\Requests\Settings\UpdateIdCardSettingsRequest;
use App\Http\Requests\Settings\UpdateLocalizationSettingsRequest;
use App\Http\Requests\Settings\UpdateNotificationSettingsRequest;
use App\Http\Requests\Settings\UpdateSecuritySettingsRequest;
use App\Http\Requests\Settings\UpdateSmsSettingsRequest;
use App\Http\Requests\Settings\UpdateTelegramSettingsRequest;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SystemSettings\PublicSettingsService;
use App\Services\SystemSettings\SystemSettingsRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Spatie\Permission\Models\Permission;

/**
 * The controller persists `$request->validated()`, so a field that the request
 * does not declare a rule for is silently dropped: it renders in the form, the
 * browser submits it, the save reports success, and nothing changes.
 *
 * That is exactly how `sidebar_color` shipped broken. These tests make the
 * failure loud — the first one for every appearance field that exists now or
 * is added later, the rest for the sidebar colour end to end.
 */
function appearanceAdmin(): User
{
    foreach (['system-settings.view', 'system-settings.manageAppearance'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $admin = User::factory()->create();
    $admin->givePermissionTo(['system-settings.view', 'system-settings.manageAppearance']);

    return $admin;
}

/** A complete, valid payload for the appearance group. */
function appearancePayload(array $overrides = []): array
{
    return array_merge([
        'default_theme' => 'system',
        'primary_color' => '#122170',
        'secondary_color' => '#1D3084',
        'accent_color' => '#D12908',
        'sidebar_color' => '#FFFFFF',
        'table_density' => 'comfortable',
        'button_style' => 'rounded',
        'card_radius' => 'xl',
        'allow_user_theme_switching' => true,
        'sidebar_compact_default' => false,
        'show_breadcrumbs' => true,
        'show_language_switcher' => true,
        'dashboard_layout' => 'executive',
        'dashboard_refresh_seconds' => 60,
        'enable_ui_animations' => true,
        'sticky_table_headers' => true,
        'default_page_size' => 25,
        'logo_position' => 'start',
    ], $overrides);
}

/**
 * Every settings group, paired with the request that saves it.
 *
 * @return array<string, class-string<FormRequest>>
 */
function settingsGroupRequests(): array
{
    return [
        SystemSettingsRegistry::GROUP_GENERAL => UpdateGeneralSettingsRequest::class,
        SystemSettingsRegistry::GROUP_LOCALIZATION => UpdateLocalizationSettingsRequest::class,
        SystemSettingsRegistry::GROUP_NOTIFICATIONS => UpdateNotificationSettingsRequest::class,
        SystemSettingsRegistry::GROUP_EMAIL => UpdateEmailSettingsRequest::class,
        SystemSettingsRegistry::GROUP_SMS => UpdateSmsSettingsRequest::class,
        SystemSettingsRegistry::GROUP_TELEGRAM => UpdateTelegramSettingsRequest::class,
        SystemSettingsRegistry::GROUP_SECURITY => UpdateSecuritySettingsRequest::class,
        SystemSettingsRegistry::GROUP_APPEARANCE => UpdateAppearanceSettingsRequest::class,
        SystemSettingsRegistry::GROUP_ID_CARDS => UpdateIdCardSettingsRequest::class,
        SystemSettingsRegistry::GROUP_PUBLIC_SITE => UpdatePublicSiteSettingsRequest::class,
        SystemSettingsRegistry::GROUP_DAILY_ACTIVITY => UpdateDailyActivitySettingsRequest::class,
        SystemSettingsRegistry::GROUP_PERFORMANCE => UpdatePerformanceSettingsRequest::class,
    ];
}

/*
 * Runs for every group, not just appearance: the trap is structural, and both
 * bugs it has already caused (`appearance.sidebar_color`,
 * `localization.calendar_system_mode`) were invisible until someone noticed a
 * setting would not stick.
 */
it('declares a validation rule for every registered setting', function (string $group, string $requestClass): void {
    $registered = array_keys(SystemSettingsRegistry::definitions()[$group] ?? []);
    $validated = array_keys((new $requestClass)->rules());

    $missing = array_values(array_diff($registered, $validated));

    expect($missing)->toBe(
        [],
        sprintf(
            'These "%s" settings render in the form and submit, but are dropped on save because '
            .'%s declares no rule for them (the controller persists validated() only): %s',
            $group,
            class_basename($requestClass),
            implode(', ', $missing),
        ),
    );
})->with(array_map(
    static fn (string $group, string $class): array => [$group, $class],
    array_keys(settingsGroupRequests()),
    array_values(settingsGroupRequests()),
));

/*
 * Settings that are public but reach the frontend under a different key, so
 * they are legitimately absent from the shared payload by their own name.
 */
const SHARED_UNDER_ANOTHER_KEY = [
    'general.application_name' => 'app.name',
    'general.application_short_name' => 'app.short_name',
    'general.identity_system_logo' => 'general.identity_system_logo_url',
    'general.favicon' => 'general.favicon_url',
    'general.seal' => 'general.seal_url',
];

/*
 * A field marked `isPublic` that PublicSettingsService never emits is invisible
 * to the frontend: `getString()` silently returns its hardcoded fallback, so
 * the control appears in System Settings, saves correctly, and changes nothing.
 * That is how `appearance.show_breadcrumbs`, `appearance.sticky_table_headers`
 * and `id_cards.verification_url` came to be inert.
 */
it('shares every setting it marks as public', function (): void {
    $shared = app(PublicSettingsService::class)->shareableSettings();

    $missing = [];
    foreach (SystemSettingsRegistry::definitions() as $group => $fields) {
        foreach ($fields as $key => $definition) {
            if (! ($definition['is_public'] ?? false)) {
                continue;
            }

            $qualified = "$group.$key";
            $target = SHARED_UNDER_ANOTHER_KEY[$qualified] ?? $qualified;

            if (! array_key_exists($target, $shared)) {
                $missing[] = $qualified;
            }
        }
    }

    expect($missing)->toBe(
        [],
        'These settings are marked public but never reach the frontend, so their '
        .'controls do nothing: '.implode(', ', $missing),
    );
});

it('persists a chosen sidebar colour', function (): void {
    $this->actingAs(appearanceAdmin())
        ->patch(route('system-settings.appearance.update'), appearancePayload([
            'sidebar_color' => '#122170',
        ]))
        ->assertRedirect();

    expect(SystemSetting::query()
        ->where('group', SystemSettingsRegistry::GROUP_APPEARANCE)
        ->where('key', 'sidebar_color')
        ->value('value'))->toBe('#122170');
});

it('shares the saved sidebar colour with the frontend', function (): void {
    $this->actingAs(appearanceAdmin())
        ->patch(route('system-settings.appearance.update'), appearancePayload([
            'sidebar_color' => '#0F1C5C',
        ]));

    $shared = app(PublicSettingsService::class)->shareableSettings();

    expect($shared['appearance.sidebar_color'])->toBe('#0F1C5C');
});

it('rejects a sidebar colour that is not a six-digit hex', function (): void {
    $this->actingAs(appearanceAdmin())
        ->patch(route('system-settings.appearance.update'), appearancePayload([
            'sidebar_color' => 'navy',
        ]))
        ->assertSessionHasErrors('sidebar_color');
});

it('forbids changing appearance without the manage permission', function (): void {
    Permission::findOrCreate('system-settings.view', 'web');
    $user = User::factory()->create();
    $user->givePermissionTo('system-settings.view');

    $this->actingAs($user)
        ->patch(route('system-settings.appearance.update'), appearancePayload())
        ->assertForbidden();
});
