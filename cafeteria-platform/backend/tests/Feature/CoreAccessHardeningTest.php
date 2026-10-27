<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\SaveUser;
use App\Models\CoreAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** Accounts, passwords and audit rows: the access rules that keep the Core administrable and its trail complete. */
class CoreAccessHardeningTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
    }

    public function test_account_and_settings_changes_are_audited_with_their_non_uuid_subjects(): void
    {
        $this->actingAsCore('core_admin');

        $id = $this->postJson('/api/v1/users', ['name' => 'Hana Tesfaye', 'email' => 'hana@core.test', 'role' => 'core_viewer'])->assertCreated()->json('data.id');
        $this->putJson('/api/v1/settings/security', ['values' => ['lockout_minutes' => 20]])->assertOk();

        $this->assertDatabaseHas('core_audit_logs', ['action' => 'user.created', 'subject_type' => 'User', 'subject_id' => (string) $id, 'source' => 'frontend']);
        $this->assertDatabaseHas('core_audit_logs', ['action' => 'settings.updated', 'subject_id' => 'security']);
    }

    public function test_a_malformed_record_id_is_not_found_instead_of_a_server_error(): void
    {
        $this->actingAsCore('core_officer');

        $this->getJson('/api/v1/employees/not-a-uuid')->assertNotFound();
        $this->getJson('/api/v1/organizations/1')->assertNotFound();
        $this->postJson('/api/v1/id-cards/abc/activate')->assertNotFound();
    }

    public function test_an_administrator_cannot_narrow_their_own_access(): void
    {
        $admin = $this->actingAsCore('core_admin');

        $this->putJson('/api/v1/users/'.$admin->id, ['name' => $admin->name, 'email' => $admin->email, 'role' => 'core_admin', 'scope_organization_id' => $this->bureauA->id])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertNull($admin->fresh()->scope_organization_id);
    }

    public function test_the_last_active_city_wide_administrator_cannot_be_removed(): void
    {
        $other = User::query()->create(['name' => 'Other', 'email' => 'other@core.test', 'password' => 'x', 'role' => 'core_admin', 'scope_type' => User::SCOPE_CITYWIDE, 'is_active' => true]);
        $actor = $this->actingAsCore('core_admin');
        // The acting account itself has just been switched off elsewhere: the other is the last one.
        User::query()->whereKey($actor->id)->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        app(SaveUser::class)->execute($other, ['name' => 'Other', 'email' => 'other@core.test', 'role' => 'core_officer'], $actor);
    }

    public function test_a_one_time_password_must_be_replaced_under_the_password_policy(): void
    {
        $this->actingAsCore('core_admin');
        $oneTime = $this->postJson('/api/v1/users', ['name' => 'Hana Tesfaye', 'email' => 'hana@core.test', 'role' => 'core_officer', 'scope_type' => User::SCOPE_CITYWIDE])->json('one_time_password');
        Auth::forgetGuards();

        $token = $this->postJson('/api/auth/login', ['email' => 'hana@core.test', 'password' => $oneTime])->assertOk()
            ->assertJsonPath('user.must_change_password', true)->json('token');
        $bearer = ['Authorization' => 'Bearer '.$token];

        $this->getJson('/api/v1/dashboard', $bearer)->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->getJson('/api/auth/me', $bearer)->assertOk();

        foreach (['short1A', 'alllowercase123456', 'NoDigitsInThisOne', 'Hana2026Strong!', 'Hana.tesfaye99X'] as $weak) {
            $this->postJson('/api/auth/password', ['current_password' => $oneTime, 'password' => $weak, 'password_confirmation' => $weak], $bearer)
                ->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->postJson('/api/auth/password', ['current_password' => 'wrong', 'password' => 'Blue-Rivers-2026', 'password_confirmation' => 'Blue-Rivers-2026'], $bearer)
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->postJson('/api/auth/password', ['current_password' => $oneTime, 'password' => 'Blue-Rivers-2026', 'password_confirmation' => 'Blue-Rivers-2026'], $bearer)
            ->assertOk()->assertJsonPath('user.must_change_password', false);
        Auth::forgetGuards();
        $this->getJson('/api/v1/dashboard', $bearer)->assertOk();

        // The previous password cannot come back.
        Auth::forgetGuards();
        $this->postJson('/api/auth/password', ['current_password' => 'Blue-Rivers-2026', 'password' => $oneTime, 'password_confirmation' => $oneTime], $bearer)
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(CoreAuditLog::query()->where('action', 'user.password_changed')->exists());
    }
}
