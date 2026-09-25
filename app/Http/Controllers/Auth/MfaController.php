<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FAQRCode\Google2FA;

class MfaController extends Controller
{
    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly WriteAuditLogAction $writeAuditLog,
    ) {}

    public const SESSION_VERIFIED_AT = 'mfa_verified_at';

    public const SESSION_RECOVERY_CODES = 'mfa_recovery_codes_once';

    /**
     * GET /mfa/setup — show QR code + manual key so the user can enrol an
     * authenticator app. If MFA is already confirmed, redirect onward.
     */
    public function showSetup(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->hasMfaEnabled()) {
            return redirect()->route('dashboard');
        }

        // Generate (or reuse) a secret. We persist it BEFORE confirmation so
        // the user can refresh the page without losing the QR code, but the
        // `two_factor_enabled` flag stays false until confirmSetup() succeeds.
        if (! $user->two_factor_secret) {
            $user->two_factor_secret = $this->google2fa->generateSecretKey();
            $user->save();

            $this->writeAuditLog->execute(
                AuditEventType::MfaSetupStarted,
                actor: $user,
                auditable: $user,
                request: $request,
            );
        }

        $issuer = (string) config('app.name', 'EUISIS');
        $accountLabel = $user->email ?? ('user-'.$user->getKey());
        $otpauthUri = $this->google2fa->getQRCodeUrl($issuer, $accountLabel, $user->two_factor_secret);

        $renderer = new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd);
        $svg = (new Writer($renderer))->writeString($otpauthUri);
        $qrCodeUri = 'data:image/svg+xml;base64,'.base64_encode($svg);

        return Inertia::render('Auth/MfaSetup', [
            'qrCodeUri' => $qrCodeUri,
            'secretKey' => $user->two_factor_secret,
            'user' => ['name' => $user->name, 'email' => $user->email],
            'issuer' => $issuer,
        ]);
    }

    /**
     * POST /mfa/setup/confirm — verify the first TOTP code, mark MFA enabled,
     * generate one-time recovery codes.
     */
    public function confirmSetup(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);

        if (! $user->two_factor_secret) {
            throw ValidationException::withMessages([
                'code' => __('security.mfa_setup_required'),
            ]);
        }

        $isValid = $this->google2fa->verifyKey($user->two_factor_secret, $data['code']);
        if (! $isValid) {
            $this->writeAuditLog->execute(
                AuditEventType::MfaChallengeFailed,
                actor: $user,
                auditable: $user,
                request: $request,
                reason: 'setup_confirmation',
            );

            throw ValidationException::withMessages([
                'code' => __('security.mfa_challenge_failed'),
            ]);
        }

        $recoveryCodes = $user->regenerateMfaRecoveryCodes(
            (int) config('security.mfa_recovery_code_count', 8),
        );

        $user->two_factor_confirmed_at = now();
        $user->two_factor_enabled = true;
        $user->two_factor_last_used_at = now();
        $user->save();

        $request->session()->put(self::SESSION_VERIFIED_AT, now()->timestamp);
        // Show the recovery codes exactly once on the next page render.
        $request->session()->flash(self::SESSION_RECOVERY_CODES, $recoveryCodes);

        $this->writeAuditLog->execute(
            AuditEventType::MfaEnabled,
            actor: $user,
            auditable: $user,
            request: $request,
        );

        return redirect()->route('dashboard')
            ->with('status', __('security.mfa_enabled'));
    }

    /**
     * GET /mfa/challenge — prompt the user for a TOTP code in an existing
     * session that has not yet been MFA-verified.
     */
    public function showChallenge(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasMfaEnabled()) {
            return redirect()->route('mfa.setup');
        }

        if ($this->sessionVerified($request)) {
            return redirect()->intended(route('dashboard'));
        }

        return Inertia::render('Auth/MfaChallenge', [
            'user' => ['name' => $user->name, 'email' => $user->email],
        ]);
    }

    /**
     * POST /mfa/challenge — verify a TOTP code OR a recovery code, then
     * stamp the session as MFA-verified.
     */
    public function verifyChallenge(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'code' => ['nullable', 'string', 'regex:/^[0-9]{6}$/'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ]);

        if (! $user->hasMfaEnabled()) {
            return redirect()->route('mfa.setup');
        }

        $verified = false;
        $usedRecovery = false;

        if (! empty($data['code'])) {
            $verified = $this->google2fa->verifyKey($user->two_factor_secret, $data['code']);
        }

        if (! $verified && ! empty($data['recovery_code'])) {
            $verified = $user->consumeMfaRecoveryCode((string) $data['recovery_code']);
            $usedRecovery = $verified;
        }

        if (! $verified) {
            $this->writeAuditLog->execute(
                AuditEventType::MfaChallengeFailed,
                actor: $user,
                auditable: $user,
                request: $request,
            );

            throw ValidationException::withMessages([
                'code' => __('security.mfa_challenge_failed'),
            ]);
        }

        $user->two_factor_last_used_at = now();
        $user->save();

        $request->session()->put(self::SESSION_VERIFIED_AT, now()->timestamp);

        $this->writeAuditLog->execute(
            $usedRecovery ? AuditEventType::MfaRecoveryCodeUsed : AuditEventType::MfaChallengeSucceeded,
            actor: $user,
            auditable: $user,
            request: $request,
        );

        return redirect()->intended(route('dashboard'));
    }

    /**
     * POST /mfa/disable — turn off MFA for a user whose role does NOT require
     * MFA. Requires the current password to confirm intent.
     */
    public function disable(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        if ($user->requiresMfa()) {
            $this->writeAuditLog->execute(
                AuditEventType::MfaDisabled,
                actor: $user,
                auditable: $user,
                request: $request,
                reason: 'blocked_role_requires_mfa',
            );

            return back()->withErrors([
                'password' => __('security.mfa_disable_not_allowed'),
            ]);
        }

        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_enabled = false;
        $user->two_factor_last_used_at = null;
        $user->save();

        $request->session()->forget(self::SESSION_VERIFIED_AT);

        $this->writeAuditLog->execute(
            AuditEventType::MfaDisabled,
            actor: $user,
            auditable: $user,
            request: $request,
        );

        return back()->with('status', __('security.mfa_disabled'));
    }

    /**
     * Whether the current session has a valid MFA verification timestamp.
     * Lifetime defaults to the configured session lifetime.
     */
    public static function sessionVerified(Request $request): bool
    {
        $stamp = (int) $request->session()->get(self::SESSION_VERIFIED_AT, 0);
        if ($stamp <= 0) {
            return false;
        }

        $ttlMinutes = (int) config('security.mfa_session_lifetime_minutes', 120);

        return ($stamp + ($ttlMinutes * 60)) > now()->timestamp;
    }

    /**
     * After a successful login, decide whether to push the user into the MFA
     * setup or challenge flow. Returns null if no redirect is required.
     */
    public static function postLoginRedirect(Request $request): ?RedirectResponse
    {
        $user = Auth::user();
        if (! $user) {
            return null;
        }

        if (! $user->requiresMfa()) {
            return null;
        }

        if (! $user->hasMfaEnabled()) {
            return redirect()->route('mfa.setup');
        }

        if (! self::sessionVerified($request)) {
            return redirect()->route('mfa.challenge');
        }

        return null;
    }
}
