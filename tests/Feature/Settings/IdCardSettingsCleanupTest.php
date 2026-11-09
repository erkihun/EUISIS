<?php

declare(strict_types=1);

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\IdCards\IdCardLayoutSettingsService;
use App\Services\SystemSettings\SystemSettingsRegistry;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

/**
 * Card design moved to ID card templates. These settings stay in the database
 * as the fallback for templates that override nothing, but must not appear in
 * System Settings where they would compete with the template editor.
 */
const TEMPLATE_MANAGED = [
    'template', 'front_bg_from', 'front_bg_to', 'front_text_primary', 'front_text_secondary',
    'front_name_font_size', 'front_label_font_size', 'back_bg_from', 'back_bg_to',
    'back_text_color', 'card_padding',
];

function settingsAdmin(array $extra = []): User
{
    foreach (['system-settings.view', 'system-settings.manageIdCards', 'id_card_templates.view', ...$extra] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $admin = User::factory()->create();
    $admin->givePermissionTo(['system-settings.view', 'system-settings.manageIdCards', 'id_card_templates.view', ...$extra]);

    return $admin;
}

function idCardFieldKeys(Assert $page): array
{
    return array_column($page->toArray()['props']['settingGroups']['id_cards']['fields'] ?? [], 'key');
}

it('hides every template-managed design control from id card settings', function (): void {
    $this->actingAs(settingsAdmin())->get(route('system-settings.index'))->assertOk()
        ->assertInertia(function (Assert $page): void {
            $keys = idCardFieldKeys($page);

            foreach (TEMPLATE_MANAGED as $removed) {
                expect($keys)->not->toContain($removed);
            }
        });
});

it('keeps the system-wide id card settings on the page', function (): void {
    $this->actingAs(settingsAdmin())->get(route('system-settings.index'))->assertOk()
        ->assertInertia(function (Assert $page): void {
            $keys = idCardFieldKeys($page);

            foreach (['city_name_en', 'bureau_name_en', 'return_address_en', 'qr_size', 'show_qr', 'show_photo'] as $kept) {
                expect($keys)->toContain($kept);
            }
            expect($keys)->not->toBeEmpty();
        });
});

it('offers the manage templates link to users who may view templates', function (): void {
    $this->actingAs(settingsAdmin())->get(route('system-settings.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.viewIdCardTemplates', true));
});

it('withholds the manage templates link without template permission', function (): void {
    foreach (['system-settings.view', 'system-settings.manageIdCards'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user = User::factory()->create();
    $user->givePermissionTo(['system-settings.view', 'system-settings.manageIdCards']);

    $this->actingAs($user)->get(route('system-settings.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.viewIdCardTemplates', false));
});

it('denies id card settings updates without permission', function (): void {
    Permission::findOrCreate('system-settings.view', 'web');
    $user = User::factory()->create();
    $user->givePermissionTo('system-settings.view');

    $this->actingAs($user)
        ->patch(route('system-settings.id-cards.update'), ['city_name_en' => 'Nope'])
        ->assertForbidden();
});

it('ignores retired colour settings so a card cannot inherit stale values', function (): void {
    // These keys are no longer editable anywhere, so whatever an install left
    // behind must not reach the card. Colour belongs to the template now.
    foreach (['front_bg_from' => '#28292A', 'front_bg_to' => '#131416', 'back_bg_from' => '#88AFFB'] as $key => $value) {
        SystemSetting::query()->updateOrCreate(
            ['group' => 'id_cards', 'key' => $key],
            ['value' => $value, 'type' => 'color'],
        );
    }

    $layout = app(IdCardLayoutSettingsService::class)->get();

    expect($layout->frontBgFrom)->not->toBe('#28292A')
        ->and($layout->frontBgTo)->not->toBe('#131416')
        ->and($layout->backBgFrom)->not->toBe('#88AFFB')
        // Falls back to the neutral card surface instead.
        ->and($layout->frontBgFrom)->toBe('#FFFFFF')
        ->and($layout->frontTextPrimary)->toBe('#0F172A');
});

it('saves the remaining settings without the removed design fields', function (): void {
    $payload = [];
    foreach (SystemSettingsRegistry::group('id_cards') as $key => $definition) {
        if ($definition['template_managed'] ?? false) {
            continue;
        }
        $payload[$key] = $definition['type'] === 'boolean' ? true : ($definition['default'] ?? 'x');
    }

    $this->actingAs(settingsAdmin())
        ->patch(route('system-settings.id-cards.update'), $payload)
        ->assertSessionHasNoErrors();
});
