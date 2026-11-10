<?php

declare(strict_types=1);

use App\Http\Requests\Settings\UpdateAppearanceSettingsRequest;
use App\Services\SystemSettings\SystemSettingsRegistry;

/*
 * The interface style presets (System Settings › Appearance › Interface style)
 * are CSS blocks keyed by `html[data-ui-style="…"]`. A preset that forgets a
 * token in its dark block inherits the base style's value for it and shows a
 * mixed theme, so every preset must restate the full neutral set in both
 * blocks, and the options offered in settings must match the presets the
 * stylesheet knows.
 */

const UI_STYLE_PRESETS = ['classic', 'soft', 'contrast'];

const UI_STYLE_TOKENS = [
    '--app-background',
    '--app-foreground',
    '--app-surface',
    '--app-surface-muted',
    '--app-border',
    '--app-border-strong',
    '--app-muted-foreground',
    '--app-card',
    '--app-card-border',
    '--app-card-hover',
];

function uiStyleBlock(string $css, string $selector): string
{
    $start = strpos($css, $selector.' {');
    expect($start)->not->toBeFalse("missing block {$selector}");
    $end = strpos($css, '}', $start);

    return substr($css, $start, $end - $start);
}

it('defines every neutral token for each interface style in both themes', function (): void {
    $css = file_get_contents(base_path('resources/css/app.css'));

    foreach (UI_STYLE_PRESETS as $preset) {
        $light = uiStyleBlock($css, 'html[data-ui-style="'.$preset.'"]');
        $dark = uiStyleBlock($css, 'html[data-ui-style="'.$preset.'"].dark');
        foreach (UI_STYLE_TOKENS as $token) {
            // Pest's toContain() takes several needles, not a message: assert one needle per call.
            expect(str_contains($light, $token.':'))->toBeTrue("{$preset} light lacks {$token}");
            expect(str_contains($dark, $token.':'))->toBeTrue("{$preset} dark lacks {$token}");
        }
    }
});

it('offers exactly the presets the stylesheet defines', function (): void {
    $field = SystemSettingsRegistry::definitions()[SystemSettingsRegistry::GROUP_APPEARANCE]['ui_style'] ?? null;
    expect($field)->not->toBeNull();
    expect($field['options'])->toBe(array_merge(['swiss'], UI_STYLE_PRESETS));

    $rules = (new UpdateAppearanceSettingsRequest)->rules();
    expect($rules)->toHaveKey('ui_style');

    $blade = file_get_contents(base_path('resources/views/app.blade.php'));
    expect($blade)->toContain('data-ui-style=');
});
