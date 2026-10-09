<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\SystemSettings\SystemSettingsRegistry;

/*
 * System Settings → Field Work GPS (?tab=field_work_gps) reads fully in
 * Amharic: field labels and help text, the tab name and intro, and the field
 * names inside validation messages.
 */

const ETHIOPIC = '/\p{Ethiopic}/u';

test('every Field Work GPS setting has its own Amharic label and description', function (): void {
    $fields = SystemSettingsRegistry::group(SystemSettingsRegistry::GROUP_FIELD_WORK_GPS);

    expect($fields)->not->toBeEmpty();
    foreach ($fields as $key => $field) {
        expect($field['label_am'])->toMatch(ETHIOPIC, "label_am of {$key}")
            ->and($field['description_am'])->toMatch(ETHIOPIC, "description_am of {$key}")
            ->and($field['description_am'])->not->toBe($field['description_en'], "description_am of {$key} copies the English");
    }
});

test('the Field Work GPS tab name and intro are translated, and every option has an Amharic label', function (): void {
    $am = (string) file_get_contents(resource_path('js/i18n/am/settings.ts'));
    $en = (string) file_get_contents(resource_path('js/i18n/en/settings.ts'));

    preg_match_all("/^\\s*field_work_gps: '([^']*)',/m", $am, $amTexts);
    expect($amTexts[1])->toHaveCount(2);
    foreach ($amTexts[1] as $text) {
        expect($text)->toMatch(ETHIOPIC);
    }

    foreach (SystemSettingsRegistry::group(SystemSettingsRegistry::GROUP_FIELD_WORK_GPS) as $key => $field) {
        foreach ($field['options'] ?? [] as $option) {
            expect($am)->toMatch("/{$key}: \\{[^}]*{$option}: '[^']*\\p{Ethiopic}/u")
                ->and($en)->toMatch("/{$key}: \\{[^}]*{$option}: '/");
        }
    }
});

test('validation messages name Field Work GPS fields in the viewer\'s language', function (string $locale, string $name): void {
    $role = Role::findOrCreate('Super Admin', 'web');
    $role->givePermissionTo(Permission::findOrCreate('system-settings.manageFieldWorkGps', 'web'));
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    $this->actingAs($user)
        ->withHeader('X-Locale', $locale)
        ->from(route('system-settings.index', ['tab' => 'field_work_gps']))
        ->patch(route('system-settings.field-work-gps.update'), [
            'require_check_in' => false, 'require_check_out' => false,
            'max_accuracy_meters' => 20000,
            'low_accuracy_action' => 'not_configured', 'outside_geofence_action' => 'not_configured',
            'offline_capture_policy' => 'not_configured', 'team_capture_policy' => 'not_configured',
        ])
        ->assertSessionHasErrors('max_accuracy_meters');

    expect(session('errors')->first('max_accuracy_meters'))->toContain($name);
})->with([
    'English' => ['en', 'Maximum GPS accuracy (metres)'],
    'Amharic' => ['am', 'ከፍተኛ የጂፒኤስ ትክክለኛነት (ሜትር)'],
]);
