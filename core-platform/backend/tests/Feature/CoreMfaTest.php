<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\MultiFactor;
use App\Models\CoreAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** P1 (ADR 0008): privileged accounts need a second factor, on the screens and the API, and cannot skip it. */
class CoreMfaTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
        config(['core.mfa_enforce' => true]);
    }

    private function account(string $role): User
    {
        return User::query()->create([
            'name' => 'Person '.Str::random(4), 'email' => Str::random(8).'@core.test', 'password' => 'secret-password-1',
            'role' => $role, 'scope_type' => User::SCOPE_CITYWIDE, 'is_active' => true,
        ]);
    }

    private function otp(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp((string) $user->fresh()->two_factor_secret);
    }

    /** The code that confirmed enrolment, to replay. */
    private string $enrolmentCode = '';

    /** @return list<string> */
    private function enrol(User $user): array
    {
        app(MultiFactor::class)->enrolmentSecret($user);
        $this->enrolmentCode = $this->otp($user);

        return app(MultiFactor::class)->confirmEnrolment($user->fresh(), $this->enrolmentCode);
    }

    /** The authenticator's clock moved on: the last accepted code is two steps old (Google2FA reads the real clock, not travel()). */
    private function nextCodeStep(User $user): void
    {
        $user->fresh()->forceFill(['two_factor_last_used_at' => now()->subMinutes(2)])->save();
    }

    private function newSession(User $user): void
    {
        Auth::forgetGuards();
        $this->flushSession();
        $this->actingAs($user->fresh(), 'web');
    }

    public function test_a_privileged_account_enrols_before_anything_else_and_sees_its_recovery_codes_once(): void
    {
        $officer = $this->account('core_officer');
        $this->actingAs($officer, 'web');

        foreach (['/core', '/employees', '/employees/'.$this->employee->id, '/organizations'] as $path) {
            $this->get($path)->assertRedirect('/mfa/setup');
        }
        $this->get('/employees', ['X-Inertia' => 'true'])->assertRedirect('/mfa/setup');
        $this->post('/employees', [])->assertStatus(303)->assertRedirect('/mfa/setup');
        $this->getJson('/employees')->assertForbidden()->assertJsonPath('code', 'MFA_ENROLLMENT_REQUIRED');
        $this->getJson('/notifications/feed')->assertForbidden();
        $secret = $this->get('/mfa/setup')->assertOk()->assertInertia(fn ($page) => $page->component('Auth/MfaSetup')->whereNot('secretKey', ''))
            ->viewData('page')['props']['secretKey'];

        $this->post('/mfa/setup/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/mfa/setup/confirm', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertSessionHasNoErrors()->assertRedirect('/mfa/setup');
        $this->get('/mfa/setup')->assertOk()->assertInertia(fn ($page) => $page->where('secretKey', '')->where('qrCodeUri', '')
            ->has('flash.mfa_recovery_codes_once', MultiFactor::RECOVERY_CODES));
        $this->get('/mfa/setup')->assertRedirect('/dashboard');
        $page = json_encode($this->get('/core')->assertOk()->viewData('page'));
        $this->assertStringNotContainsString($secret, $page);
        $this->assertStringNotContainsString('two_factor_', $page);

        $this->assertTrue(CoreAuditLog::query()->where('action', 'mfa.enrolled')->where('subject_id', (string) $officer->id)->exists());
        $this->assertTrue(CoreAuditLog::query()->where('action', 'mfa.challenge_failed')->exists());
        $this->assertStringNotContainsString($secret, (string) CoreAuditLog::query()->get()->toJson());
    }

    public function test_each_new_session_passes_the_challenge_and_a_code_works_only_once(): void
    {
        $officer = $this->account('core_officer');
        $this->enrol($officer);
        $this->newSession($officer);

        $this->get('/core')->assertRedirect('/mfa/challenge');
        $this->get('/mfa/challenge')->assertOk()->assertInertia(fn ($page) => $page->component('Auth/MfaChallenge'));
        $this->post('/mfa/challenge', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->get('/core')->assertRedirect('/mfa/challenge');

        // The code that confirmed enrolment was already used: the same time step is never accepted twice.
        $this->post('/mfa/challenge', ['code' => $this->enrolmentCode])->assertSessionHasErrors('code');
        $this->get('/core')->assertRedirect('/mfa/challenge');
        $this->nextCodeStep($officer);
        $this->post('/mfa/challenge', ['code' => $this->otp($officer)])->assertSessionHasNoErrors()->assertRedirect();
        $this->get('/core')->assertOk();
    }

    public function test_a_recovery_code_opens_one_session_only(): void
    {
        $officer = $this->account('core_officer');
        $codes = $this->enrol($officer);

        $this->newSession($officer);
        $this->post('/mfa/challenge', ['recovery_code' => strtolower($codes[0])])->assertRedirect();
        $this->get('/core')->assertOk();

        $this->newSession($officer);
        $this->post('/mfa/challenge', ['recovery_code' => $codes[0]])->assertSessionHasErrors('code');
        $this->get('/core')->assertRedirect('/mfa/challenge');
        $this->assertSame(1, CoreAuditLog::query()->where('action', 'mfa.recovery_used')->count());
    }

    public function test_an_account_whose_category_does_not_require_mfa_is_unaffected(): void
    {
        $this->actingAs($this->account('core_viewer'), 'web');

        $this->get('/core')->assertOk();
        $this->get('/employees')->assertOk();
    }

    public function test_switching_the_requirement_on_stops_existing_tokens_and_sessions(): void
    {
        $viewer = $this->account('core_viewer');
        $token = $this->postJson('/api/auth/login', ['email' => $viewer->email, 'password' => 'secret-password-1'])->assertOk()->json('token');
        $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->actingAs($viewer, 'web');
        $this->get('/core')->assertOk();

        DB::table('core_settings')->insert(['group' => 'security', 'key' => 'mfa_viewers', 'value' => json_encode(true), 'created_at' => now(), 'updated_at' => now()]);
        cache()->forget('core.settings.values');

        Auth::forgetGuards();
        $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$token])->assertForbidden()->assertJsonPath('code', 'MFA_ENROLLMENT_REQUIRED');
        $this->newSession($viewer);
        $this->get('/core')->assertRedirect('/mfa/setup');
    }

    public function test_api_sign_in_asks_for_the_second_factor(): void
    {
        $officer = $this->account('core_officer');
        $login = fn (array $extra = []) => $this->postJson('/api/auth/login', ['email' => $officer->email, 'password' => 'secret-password-1'] + $extra);

        $login()->assertForbidden()->assertJsonPath('code', 'MFA_ENROLLMENT_REQUIRED');
        $this->enrol($officer);
        $login()->assertUnauthorized()->assertJsonPath('code', 'MFA_CODE_REQUIRED');
        $login(['code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $login(['code' => $this->enrolmentCode])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->nextCodeStep($officer);
        $token = $login(['code' => $this->otp($officer)])->assertOk()->assertJsonPath('user.mfa_enabled', true)->json('token');

        Auth::forgetGuards();
        $me = $this->getJson('/api/auth/me', ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->assertArrayNotHasKey('two_factor_secret', $me->json('user'));
        $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$token])->assertOk();
    }

    public function test_an_administrator_resets_a_lost_second_factor_and_the_holder_enrols_again(): void
    {
        config(['core.mfa_enforce' => false]);
        $officer = $this->account('core_officer');
        $this->enrol($officer);
        $this->actingAsCore('core_admin');

        $this->postJson('/api/v1/users/'.$officer->id.'/mfa-reset', [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson('/api/v1/users/'.$officer->id.'/mfa-reset', ['reason' => 'Phone lost on 25 October'])->assertOk();

        $this->assertFalse($officer->fresh()->hasMfaEnabled());
        $this->assertDatabaseHas('core_audit_logs', ['action' => 'mfa.reset', 'subject_id' => (string) $officer->id, 'reason' => 'Phone lost on 25 October']);
        config(['core.mfa_enforce' => true]);
        $this->newSession($officer);
        $this->get('/core')->assertRedirect('/mfa/setup');
    }

    public function test_the_server_command_resets_a_second_factor_only_with_a_reason(): void
    {
        $admin = $this->account('core_admin');
        $this->enrol($admin);

        $this->artisan('core:reset-mfa', ['email' => $admin->email])->assertFailed();
        $this->assertTrue($admin->fresh()->hasMfaEnabled());
        $this->artisan('core:reset-mfa', ['email' => $admin->email, '--reason' => 'Last city administrator lost the phone'])->assertSuccessful();

        $this->assertFalse($admin->fresh()->hasMfaEnabled());
        $this->assertNull($admin->fresh()->two_factor_secret);
        $this->assertDatabaseHas('core_audit_logs', ['action' => 'mfa.reset', 'actor_user_id' => null, 'subject_id' => (string) $admin->id]);
    }
}
