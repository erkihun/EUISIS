<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\AuditWriter;
use App\Services\MfaPolicy;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FAQRCode\Google2FA;

/**
 * Multi-factor authentication of Core accounts (ADR 0008), with the EUISIS
 * mechanics: a TOTP secret (encrypted at rest) confirmed by a first code,
 * eight one-time recovery codes (hashed, shown once), a code accepted only
 * once (its 30-second step must be newer than the last accepted one), and an
 * audit row for enrolment, failures, recovery-code use, reset and disabling.
 * Secrets and codes never go into an audit row, a log or an API answer.
 * Every verification locks the account row, so a code or a recovery code
 * cannot be used twice by two requests at the same time.
 */
final readonly class MultiFactor
{
    public const RECOVERY_CODES = 8;

    public function __construct(private Google2FA $google2fa, private AuditWriter $audit, private MfaPolicy $policy) {}

    /** The secret the enrolment page shows; kept unconfirmed until a first code proves the device. */
    public function enrolmentSecret(User $user): string
    {
        abort_if($user->hasMfaEnabled(), 409, 'Multi-factor authentication is already set up.');
        if ($user->two_factor_secret === null) {
            $user->forceFill(['two_factor_secret' => $this->google2fa->generateSecretKey(32)])->save();
        }

        return (string) $user->two_factor_secret;
    }

    public function qrCodeUri(User $user, string $issuer): string
    {
        $otpauth = $this->google2fa->getQRCodeUrl($issuer, $user->email, (string) $user->two_factor_secret);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd)))->writeString($otpauth);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** @return list<string> the recovery codes, in clear, to show this once */
    public function confirmEnrolment(User $user, string $code): array
    {
        $codes = DB::transaction(function () use ($user, $code): ?array {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($user->hasMfaEnabled()) {
                throw ValidationException::withMessages(['code' => 'Multi-factor authentication is already set up.']);
            }
            $step = $user->two_factor_secret === null ? null : $this->acceptedStep($user, $code);
            if ($step === null) {
                return null;
            }
            $codes = $user->regenerateMfaRecoveryCodes(self::RECOVERY_CODES);
            $user->forceFill(['two_factor_enabled' => true, 'two_factor_confirmed_at' => now(), 'two_factor_last_used_at' => $this->stepTime($step)])->save();
            $this->audit->writeFor('mfa.enrolled', $user, 'User', (string) $user->id, null);

            return $codes;
        });
        $user->refresh();
        if ($codes === null) {
            // Written after the transaction, so the refused attempt stays on record.
            $this->audit->writeFor('mfa.challenge_failed', $user, 'User', (string) $user->id, null, null, ['stage' => 'enrolment']);
            throw ValidationException::withMessages(['code' => 'The code is not correct. Use the current code from your authenticator app.']);
        }

        return $codes;
    }

    /** A TOTP code or a recovery code; false (audited) when neither is right. */
    public function verify(User $user, ?string $code, ?string $recoveryCode): bool
    {
        $passed = DB::transaction(function () use ($user, $code, $recoveryCode): bool {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! $user->hasMfaEnabled()) {
                return false;
            }
            $step = $code === null || $code === '' ? null : $this->acceptedStep($user, $code);
            if ($step !== null) {
                $user->forceFill(['two_factor_last_used_at' => $this->stepTime($step)])->save();

                return true;
            }
            if ($recoveryCode !== null && $recoveryCode !== '' && $user->consumeMfaRecoveryCode($recoveryCode)) {
                $this->audit->writeFor('mfa.recovery_used', $user, 'User', (string) $user->id, null, null,
                    ['recovery_codes_left' => count(json_decode((string) $user->two_factor_recovery_codes, true) ?: [])]);

                return true;
            }
            $this->audit->writeFor('mfa.challenge_failed', $user, 'User', (string) $user->id, null, null, ['stage' => 'challenge']);

            return false;
        });
        $user->refresh();

        return $passed;
    }

    /** An administrator's reset for a lost device: the holder enrols again; every sign-in of the account ends. */
    public function reset(User $user, ?User $actor, string $reason): void
    {
        DB::transaction(function () use ($user, $actor, $reason): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->clear($user);
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $this->audit->writeFor('mfa.reset', $actor, 'User', (string) $user->id, $user->scope_organization_id, null, null, $reason);
        });
        $user->refresh();
    }

    /** The holder turns a voluntary second factor off; never when their role requires it. */
    public function disable(User $user): void
    {
        if ($this->policy->requires($user)) {
            throw ValidationException::withMessages(['password' => 'Your role requires multi-factor authentication; it cannot be turned off.']);
        }
        DB::transaction(function () use ($user): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->clear($user);
            $this->audit->writeFor('mfa.disabled', $user, 'User', (string) $user->id, null);
        });
        $user->refresh();
    }

    private function clear(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null,
            'two_factor_enabled' => false, 'two_factor_last_used_at' => null])->save();
    }

    /**
     * The time step a code belongs to, or null. A code is accepted once: its
     * step must be newer than the last accepted one, so neither a replayed code
     * nor an older code of the clock-drift window opens another session.
     */
    private function acceptedStep(User $user, string $code): ?int
    {
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }
        $lastStep = $user->two_factor_last_used_at === null ? 0
            : intdiv($user->two_factor_last_used_at->getTimestamp(), $this->google2fa->getKeyRegeneration());
        $step = $this->google2fa->verifyKeyNewer((string) $user->two_factor_secret, $code, $lastStep);

        return is_int($step) ? $step : null;
    }

    /** When a step's code was accepted: never earlier than now, so a code of the next step is not accepted again either. */
    private function stepTime(int $step): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp(max($step * $this->google2fa->getKeyRegeneration(), now()->getTimestamp()));
    }
}
