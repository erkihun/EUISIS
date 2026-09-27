<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\MfaController;
use App\Models\AuditLog;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FAQRCode\Google2FA;
use Spatie\Permission\Models\Role;

/*
 * The TOTP flow end to end: enrolment, the sign-in challenge, recovery codes,
 * expiry, and switching MFA off. Enforcement is on here (the suite turns it
 * off elsewhere) and Super Admin is the role that requires MFA.
 */
beforeEach(function (): void {
    config([
        'security.mfa_enforce' => true,
        'security.mfa_required_roles' => ['Super Admin'],
        'security.mfa_session_lifetime_minutes' => 120,
    ]);
    Role::findOrCreate('Super Admin', 'web');
    $this->totp = app(Google2FA::class);
});

function mfaUser(bool $enrolled = false): User
{
    $user = User::factory()->create(['status' => 'active', 'must_change_password' => false])->assignRole('Super Admin');
    if ($enrolled) {
        $user->forceFill([
            'two_factor_secret' => app(Google2FA::class)->generateSecretKey(),
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    return $user->fresh();
}

/** A six-digit code that is not the current one (nor, in practice, a neighbour). */
function wrongCode(string $secret): string
{
    return str_pad((string) (((int) app(Google2FA::class)->getCurrentOtp($secret) + 500000) % 1000000), 6, '0', STR_PAD_LEFT);
}

it('enrols: stable secret, a wrong first code refused, a right one enables MFA with one-time recovery codes', function (): void {
    $user = mfaUser();
    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('mfa.setup'));

    $this->get(route('mfa.setup'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/MfaSetup')->where('secretKey', $user->fresh()->two_factor_secret));
    $secret = $user->fresh()->two_factor_secret;
    $this->get(route('mfa.setup'))->assertInertia(fn (Assert $page) => $page->where('secretKey', $secret)); // refresh keeps the QR

    $this->post(route('mfa.setup.confirm'), ['code' => wrongCode($secret)])->assertSessionHasErrors('code');
    expect($user->fresh()->hasMfaEnabled())->toBeFalse()
        ->and(AuditLog::query()->where('event_type', 'mfa.challenge_failed')->exists())->toBeTrue();

    $this->post(route('mfa.setup.confirm'), ['code' => $this->totp->getCurrentOtp($secret)])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas(MfaController::SESSION_RECOVERY_CODES, fn (array $codes) => count($codes) === 8);
    expect($user->fresh()->hasMfaEnabled())->toBeTrue()
        ->and(AuditLog::query()->where('event_type', 'mfa.enabled')->exists())->toBeTrue();
    $this->get(route('dashboard'))->assertOk();
});

it('challenges a returning user; a wrong code keeps them out and a right one lets them in', function (): void {
    $user = mfaUser(enrolled: true);

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('mfa.challenge'));
    $this->get(route('mfa.challenge'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/MfaChallenge'));

    $this->post(route('mfa.challenge.verify'), ['code' => wrongCode($user->two_factor_secret)])->assertSessionHasErrors('code');
    $this->get(route('dashboard'))->assertRedirect(route('mfa.challenge'));

    $this->post(route('mfa.challenge.verify'), ['code' => $this->totp->getCurrentOtp($user->two_factor_secret)])->assertRedirect();
    $this->get(route('dashboard'))->assertOk();
    expect(AuditLog::query()->where('event_type', 'mfa.challenge_succeeded')->exists())->toBeTrue();
});

it('refuses a code that was already used, even while it is still valid', function (): void {
    $user = mfaUser(enrolled: true);
    $code = $this->totp->getCurrentOtp($user->two_factor_secret);

    $this->actingAs($user)->post(route('mfa.challenge.verify'), ['code' => $code])->assertSessionHasNoErrors();

    // Someone who saw that code signs in with the stolen password in a new session.
    $this->flushSession();
    $this->actingAs($user->fresh())->post(route('mfa.challenge.verify'), ['code' => $code])->assertSessionHasErrors('code');
    $this->get(route('dashboard'))->assertRedirect(route('mfa.challenge'));
});

it('accepts a recovery code exactly once', function (): void {
    $user = mfaUser(enrolled: true);
    [$recovery] = $user->regenerateMfaRecoveryCodes(8);
    $user->save();

    $this->actingAs($user->fresh())->post(route('mfa.challenge.verify'), ['recovery_code' => $recovery])->assertSessionHasNoErrors();
    $this->get(route('dashboard'))->assertOk();
    expect(AuditLog::query()->where('event_type', 'mfa.recovery_code_used')->exists())->toBeTrue()
        ->and(json_decode($user->fresh()->two_factor_recovery_codes, true))->toHaveCount(7);

    $this->flushSession();
    $this->actingAs($user->fresh())->post(route('mfa.challenge.verify'), ['recovery_code' => $recovery])->assertSessionHasErrors('code');
});

it('asks again once the verification is older than the configured lifetime', function (): void {
    config(['security.mfa_session_lifetime_minutes' => 10]);
    $user = mfaUser(enrolled: true);
    $this->actingAs($user)->post(route('mfa.challenge.verify'), ['code' => $this->totp->getCurrentOtp($user->two_factor_secret)]);
    $this->get(route('dashboard'))->assertOk();

    $this->travel(11)->minutes();
    $this->get(route('dashboard'))->assertRedirect(route('mfa.challenge'));
});

it('challenges users who enrolled voluntarily, without forcing enrolment on the rest', function (): void {
    config(['security.mfa_required_roles' => []]); // no role requires MFA

    $optedIn = mfaUser(enrolled: true);
    $this->actingAs($optedIn)->get(route('dashboard'))->assertRedirect(route('mfa.challenge'));

    $this->flushSession();
    $this->actingAs(mfaUser())->get(route('dashboard'))->assertOk();
});

it('switches MFA off only with the password, after the challenge, and never for a role that requires it', function (): void {
    config(['security.mfa_required_roles' => []]);
    $user = mfaUser(enrolled: true);

    // The password alone must not remove the second factor.
    $this->actingAs($user)->post(route('mfa.disable'), ['password' => 'password'])->assertSessionHasErrors('password');
    expect($user->fresh()->hasMfaEnabled())->toBeTrue();

    $this->post(route('mfa.challenge.verify'), ['code' => $this->totp->getCurrentOtp($user->two_factor_secret)]);
    $this->post(route('mfa.disable'), ['password' => 'not-my-password'])->assertSessionHasErrors('password');
    expect($user->fresh()->hasMfaEnabled())->toBeTrue();

    $this->post(route('mfa.disable'), ['password' => 'password'])->assertSessionHasNoErrors();
    expect($user->fresh())->hasMfaEnabled()->toBeFalse()->two_factor_secret->toBeNull()->two_factor_recovery_codes->toBeNull();

    // A role that requires MFA cannot switch it off, even after the challenge.
    config(['security.mfa_required_roles' => ['Super Admin']]);
    $this->flushSession();
    $required = mfaUser(enrolled: true);
    $this->actingAs($required)->post(route('mfa.challenge.verify'), ['code' => $this->totp->getCurrentOtp($required->two_factor_secret)]);
    $this->post(route('mfa.disable'), ['password' => 'password'])->assertSessionHasErrors('password');
    expect($required->fresh()->hasMfaEnabled())->toBeTrue()
        ->and(AuditLog::query()->where('event_type', 'mfa.disabled')->where('reason', 'blocked_role_requires_mfa')->exists())->toBeTrue();
});

it('routes between the MFA pages sensibly', function (): void {
    $pending = mfaUser();
    $this->actingAs($pending)->get(route('mfa.challenge'))->assertRedirect(route('mfa.setup'));
    $this->post(route('mfa.challenge.verify'), ['code' => '123456'])->assertRedirect(route('mfa.setup'));
    // Confirming before a secret exists is refused rather than enabling anything.
    $this->post(route('mfa.setup.confirm'), ['code' => '123456'])->assertSessionHasErrors('code');
    expect($pending->fresh()->hasMfaEnabled())->toBeFalse();

    $this->flushSession();
    $enrolled = mfaUser(enrolled: true);
    $this->actingAs($enrolled)->get(route('mfa.setup'))->assertRedirect(route('dashboard'));
    $this->post(route('mfa.challenge.verify'), ['code' => $this->totp->getCurrentOtp($enrolled->two_factor_secret)]);
    $this->get(route('mfa.challenge'))->assertRedirect(route('dashboard'));
});
