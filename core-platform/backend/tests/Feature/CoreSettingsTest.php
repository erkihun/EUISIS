<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CoreAuditLog;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** Core settings: defaults, admin-only changes, validation, audit, and that the rules really read them. */
class CoreSettingsTest extends TestCase
{
    use BuildsGoldenPath;
    use RefreshDatabase;

    public function test_the_public_settings_need_no_sign_in_and_hold_no_security_values(): void
    {
        $this->getJson('/api/settings/public')->assertOk()
            ->assertJsonPath('settings.general.application_name', 'Agelgay')
            ->assertJsonPath('settings.appearance.primary_color', '#122170')
            ->assertJsonPath('settings.localization.default_locale', 'en')
            ->assertJsonMissingPath('settings.security.max_login_attempts')
            ->assertJsonMissingPath('settings.workforce');
    }

    public function test_only_an_administrator_reads_or_changes_the_settings(): void
    {
        $this->actingAsCore('core_officer');
        $this->getJson('/api/v1/settings')->assertForbidden();
        $this->putJson('/api/v1/settings/general', ['values' => ['application_name' => 'X']])->assertForbidden();

        $this->actingAsCore('core_admin');
        $this->getJson('/api/v1/settings')->assertOk()
            ->assertJsonPath('groups.0.key', 'general')
            ->assertJsonPath('groups.0.fields.0.key', 'application_name')
            ->assertJsonPath('groups.0.fields.0.value', 'Agelgay');
    }

    public function test_a_change_is_validated_stored_audited_and_public(): void
    {
        $this->actingAsCore('core_admin');

        $this->putJson('/api/v1/settings/appearance', ['values' => ['primary_color' => 'blue']])
            ->assertUnprocessable()->assertJsonValidationErrors('primary_color');
        $this->putJson('/api/v1/settings/appearance', ['values' => ['no_such_setting' => 1]])
            ->assertUnprocessable();

        $this->putJson('/api/v1/settings/appearance', ['values' => ['primary_color' => '#0f766e', 'default_page_size' => '50']])
            ->assertOk()
            ->assertJsonPath('values.primary_color', '#0F766E')
            ->assertJsonPath('values.default_page_size', 50);

        $this->getJson('/api/settings/public')->assertJsonPath('settings.appearance.primary_color', '#0F766E');
        $entry = CoreAuditLog::query()->where('action', 'settings.updated')->sole();
        $this->assertSame('appearance', $entry->subject_id);
        $this->assertSame('#122170', $entry->old_values['primary_color']);
    }

    public function test_an_unchanged_save_writes_no_audit_entry(): void
    {
        $this->actingAsCore('core_admin');

        $this->putJson('/api/v1/settings/general', ['values' => ['application_name' => 'Agelgay']])->assertOk();

        $this->assertSame(0, CoreAuditLog::query()->count());
    }

    public function test_the_page_size_setting_sizes_every_list(): void
    {
        $this->buildGoldenPath();
        $this->actingAsCore('core_admin');
        $this->putJson('/api/v1/settings/appearance', ['values' => ['default_page_size' => 10]])->assertOk();

        $this->getJson('/api/v1/organizations')->assertOk()->assertJsonPath('per_page', 10);
    }

    public function test_the_seat_setting_lets_a_position_without_an_establishment_seat_more(): void
    {
        $this->buildGoldenPath();
        $this->actingAsCore('core_admin');
        $register = fn (string $number) => $this->postJson('/api/v1/employees', [
            'employee_number' => $number, 'first_name' => 'Sara', 'last_name' => 'Alemu',
            'position_id' => $this->positionA->id, 'effective_from' => '2025-01-01',
        ]);

        $register('EMP-0200')->assertUnprocessable();

        $this->putJson('/api/v1/settings/workforce', ['values' => ['default_seat_capacity' => 2]])->assertOk();
        $register('EMP-0200')->assertCreated();
        $this->assertSame(2, Position::query()->findOrFail($this->positionA->id)->holders()->count());
    }

    public function test_the_session_and_lockout_settings_apply_to_sign_in(): void
    {
        $admin = $this->actingAsCore('core_admin');
        $this->putJson('/api/v1/settings/security', ['values' => ['session_timeout_minutes' => 30, 'max_login_attempts' => 2]])->assertOk();
        $this->app['auth']->forgetGuards();

        User::query()->whereKey($admin->id)->update(['email' => 'admin@core.test']);
        $this->postJson('/api/auth/login', ['email' => 'admin@core.test', 'password' => 'secret-password'])->assertOk();
        $this->assertEqualsWithDelta(now()->addMinutes(30)->timestamp, $admin->tokens()->latest('id')->first()->expires_at->timestamp, 5);

        $this->postJson('/api/auth/login', ['email' => 'admin@core.test', 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/auth/login', ['email' => 'admin@core.test', 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/auth/login', ['email' => 'admin@core.test', 'password' => 'secret-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }
}
