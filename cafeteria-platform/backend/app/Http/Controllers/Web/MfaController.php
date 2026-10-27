<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\MultiFactor;
use App\Http\Controllers\Controller;
use App\Services\CoreSettings;
use App\Services\MfaPolicy;
use App\Services\SharedSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * EUISIS Auth/MfaSetup and Auth/MfaChallenge over MultiFactor (ADR 0008).
 * A session passes the second factor once and keeps that for the Core session
 * length; the session id is renewed when it does. RequireMfa sends privileged
 * accounts here before anything else.
 */
class MfaController extends Controller
{
    public const SESSION_VERIFIED_AT = 'mfa_verified_at';

    public const SESSION_RECOVERY_CODES = 'mfa_recovery_codes_once';

    public function __construct(private readonly MultiFactor $mfa) {}

    public function showSetup(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $codes = $request->session()->get(self::SESSION_RECOVERY_CODES);
        if ($user->hasMfaEnabled()) {
            // Right after enrolment the page shows the recovery codes this once, never the key again.
            return is_array($codes) && $codes !== []
                ? Inertia::render('Auth/MfaSetup', ['qrCodeUri' => '', 'secretKey' => '', 'user' => $user->only(['name', 'email']), 'issuer' => $this->issuer()])
                : redirect()->route('dashboard');
        }
        $secret = $this->mfa->enrolmentSecret($user);

        return Inertia::render('Auth/MfaSetup', [
            'qrCodeUri' => $this->mfa->qrCodeUri($user, $this->issuer()),
            'secretKey' => $secret,
            'user' => $user->only(['name', 'email']),
            'issuer' => $this->issuer(),
        ]);
    }

    public function confirmSetup(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^[0-9]{6}$/']]);
        $codes = $this->mfa->confirmEnrolment($request->user(), $data['code']);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_VERIFIED_AT, now()->timestamp);

        return redirect()->route('mfa.setup')->with(self::SESSION_RECOVERY_CODES, $codes);
    }

    public function showChallenge(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user->hasMfaEnabled()) {
            return redirect()->route('mfa.setup');
        }
        if (self::sessionVerified($request)) {
            return redirect()->intended(route('dashboard'));
        }

        return Inertia::render('Auth/MfaChallenge', ['user' => $user->only(['name', 'email'])]);
    }

    public function verifyChallenge(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'regex:/^[0-9]{6}$/'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ]);
        if (! $request->user()->hasMfaEnabled()) {
            return redirect()->route('mfa.setup');
        }
        if (! $this->mfa->verify($request->user(), $data['code'] ?? null, $data['recovery_code'] ?? null)) {
            throw ValidationException::withMessages(['code' => __('security.mfa_challenge_failed')]);
        }
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_VERIFIED_AT, now()->timestamp);

        return redirect()->intended(route('dashboard'));
    }

    /** A voluntary second factor turned off with the password; a required one stays (MultiFactor). */
    public function disable(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        if ($request->user()->hasMfaEnabled() && ! self::sessionVerified($request)) {
            return back()->withErrors(['password' => __('security.mfa_challenge_required')]);
        }
        $this->mfa->disable($request->user());
        $request->session()->forget(self::SESSION_VERIFIED_AT);

        return back()->with('status', __('security.mfa_disabled'));
    }

    /** The second factor was passed in this session, within the Core session length. */
    public static function sessionVerified(Request $request): bool
    {
        $stamp = (int) $request->session()->get(self::SESSION_VERIFIED_AT, 0);

        return $stamp > 0 && $stamp + app(CoreSettings::class)->int('security.session_timeout_minutes') * 60 > now()->timestamp;
    }

    private function issuer(): string
    {
        return (string) (app(SharedSettings::class)->branding()['name'] ?? config('app.name', 'Core'));
    }

    /** Whether MFA applies to this request's account (used by RequireMfa and the profile). */
    public static function applies(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && app(MfaPolicy::class)->applies($user);
    }
}
