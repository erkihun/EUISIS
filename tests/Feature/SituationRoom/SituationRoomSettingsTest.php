<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\User;
use App\Services\SituationRoom\SituationSettings;
use App\Services\SystemSettings\SystemSettingsService;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The situation_room settings group: shown on System Settings under its own
 * grant, thresholds blank (not configured) until someone decides them.
 */

beforeEach(function (): void {
    foreach (['system-settings.view', 'system-settings.manageSituationRoom'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $this->admin = User::factory()->create();
    $this->admin->givePermissionTo(['system-settings.view', 'system-settings.manageSituationRoom']);
});

function srSettingsPayload(array $overrides = []): array
{
    return $overrides + [
        'default_period' => 'month', 'top_n' => 10, 'cache_seconds' => 300, 'small_cell_threshold' => 5,
        'wall_refresh_seconds' => 300, 'wall_rotation_seconds' => 30,
        'complaint_open_days' => null, 'tip_off_unassigned_days' => null, 'daily_review_backlog_days' => null,
        'field_work_overdue_hours' => null, 'vacancy_rate_percent' => null,
    ];
}

it('starts with every attention threshold unset', function (): void {
    $settings = app(SituationSettings::class);

    foreach (SituationSettings::THRESHOLDS as $rule) {
        expect($settings->threshold($rule))->toBeNull();
    }
});

it('appears on System Settings for its manager and saves thresholds', function (): void {
    $this->actingAs($this->admin)->get(route('system-settings.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('settingGroups.situation_room.fields')->where('can.manageSituationRoom', true));

    $this->actingAs($this->admin)->patch(route('system-settings.situation-room.update'), srSettingsPayload(['complaint_open_days' => 14, 'default_period' => 'week']))
        ->assertSessionHasNoErrors();
    app(SystemSettingsService::class)->clearCache();

    expect(app(SituationSettings::class)->threshold('complaint_open_days'))->toBe(14)
        ->and(app(SituationSettings::class)->threshold('vacancy_rate_percent'))->toBeNull()
        ->and(app(SituationSettings::class)->defaultPeriod())->toBe('week');
});

it('validates the group and refuses users without the grant', function (): void {
    $this->actingAs($this->admin)->patch(route('system-settings.situation-room.update'), srSettingsPayload(['small_cell_threshold' => 0, 'vacancy_rate_percent' => 150, 'default_period' => 'forever']))
        ->assertSessionHasErrors(['small_cell_threshold', 'vacancy_rate_percent', 'default_period']);

    $this->actingAs(User::factory()->create())->patch(route('system-settings.situation-room.update'), srSettingsPayload())->assertForbidden();
});
