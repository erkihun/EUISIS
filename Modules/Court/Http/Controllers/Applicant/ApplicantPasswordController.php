<?php

namespace Modules\Court\Http\Controllers\Applicant;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Application\Notifications\PasswordResetOtp;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class ApplicantPasswordController extends Controller
{
    // ── Step 1: show email form ────────────────────────────────────────────────

    public function showLinkRequestForm()
    {
        // React, Court/Applicant/ForgotPassword.
        return Inertia::render('Court/Applicant/ForgotPassword', [
            'email' => (string) old('email', ''),
            'status' => session('status'),
        ]);
    }

    // ── Step 2: send OTP to email ──────────────────────────────────────────────

    public function sendResetLinkEmail(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        $applicant = Applicant::where('email', $request->email)->where('is_active', true)->first();

        if (! $applicant) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => __('court::auth.email_not_found')]);
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        session([
            'applicant_pwd_otp_email' => $request->email,
            'applicant_pwd_otp_code' => hash('sha256', $otp),
            'applicant_pwd_otp_expires' => now()->addMinutes(10)->timestamp,
        ]);

        try {
            $applicant->notifyNow(new PasswordResetOtp($otp));
        } catch (\Throwable $e) {
            session()->forget([
                'applicant_pwd_otp_email',
                'applicant_pwd_otp_code',
                'applicant_pwd_otp_expires',
            ]);

            Log::warning('Applicant password reset OTP email failed', [
                'applicant_id' => $applicant->id,
                'email' => $applicant->email,
                'error' => $e->getMessage(),
            ]);

            return back()
                ->withInput($request->only('email'))
                ->with('error', __('court::auth.reset_code_send_failed'));
        }

        return redirect()->route('court.applicant.password.otp.show')
            ->with('info', __('court::auth.reset_code_sent'));
    }

    // ── Step 3: show OTP entry form ────────────────────────────────────────────

    public function showOtpForm(Request $request)
    {
        if (! session('applicant_pwd_otp_email')) {
            return redirect()->route('court.applicant.password.request');
        }

        // React, Court/Applicant/PasswordOtp.
        return Inertia::render('Court/Applicant/PasswordOtp');
    }

    // ── Step 4: verify OTP ─────────────────────────────────────────────────────

    public function verifyOtp(Request $request)
    {
        $request->validate(['code' => ['required', 'digits:6']]);

        $email = session('applicant_pwd_otp_email');
        $storedHash = session('applicant_pwd_otp_code');
        $expiresAt = session('applicant_pwd_otp_expires');

        if (! $email || ! $storedHash || ! $expiresAt) {
            return redirect()->route('court.applicant.password.request')
                ->withErrors(['code' => __('court::auth.password_reset_session_expired')]);
        }

        if (now()->timestamp > $expiresAt) {
            return back()->withErrors(['code' => __('court::auth.password_reset_code_expired')]);
        }

        if (! hash_equals($storedHash, hash('sha256', $request->input('code')))) {
            return back()->withErrors(['code' => __('court::auth.password_reset_code_invalid')]);
        }

        // OTP verified — store the email for the next step, clear OTP data
        session()->forget(['applicant_pwd_otp_code', 'applicant_pwd_otp_expires']);
        session(['applicant_pwd_verified_email' => $email]);
        session()->forget('applicant_pwd_otp_email');

        return redirect()->route('court.applicant.password.new.show');
    }

    // ── Step 4b: resend OTP ────────────────────────────────────────────────────

    public function resendOtp(Request $request)
    {
        $email = session('applicant_pwd_otp_email');

        if (! $email) {
            return redirect()->route('court.applicant.password.request');
        }

        $applicant = Applicant::where('email', $email)->first();

        if ($applicant) {
            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            session([
                'applicant_pwd_otp_code' => hash('sha256', $otp),
                'applicant_pwd_otp_expires' => now()->addMinutes(10)->timestamp,
            ]);

            try {
                $applicant->notifyNow(new PasswordResetOtp($otp));
            } catch (\Throwable $e) {
                session()->forget([
                    'applicant_pwd_otp_email',
                    'applicant_pwd_otp_code',
                    'applicant_pwd_otp_expires',
                ]);

                Log::warning('Applicant password reset OTP resend failed', [
                    'applicant_id' => $applicant->id,
                    'email' => $applicant->email,
                    'error' => $e->getMessage(),
                ]);

                return redirect()->route('court.applicant.password.request')
                    ->with('error', __('court::auth.reset_code_send_failed'));
            }
        }

        return back()->with('success', __('court::auth.reset_code_resent'));
    }

    // ── Step 5: show new password form ────────────────────────────────────────

    public function showNewPasswordForm(Request $request)
    {
        if (! session('applicant_pwd_verified_email')) {
            return redirect()->route('court.applicant.password.request');
        }

        // React, Court/Applicant/NewPassword.
        return Inertia::render('Court/Applicant/NewPassword');
    }

    // ── Step 6: save new password ─────────────────────────────────────────────

    public function updatePassword(Request $request)
    {
        $email = session('applicant_pwd_verified_email');

        if (! $email) {
            return redirect()->route('court.applicant.password.request')
                ->withErrors(['email' => __('court::auth.password_reset_session_expired')]);
        }

        $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $applicant = Applicant::where('email', $email)->where('is_active', true)->first();

        if (! $applicant) {
            session()->forget('applicant_pwd_verified_email');

            return redirect()->route('court.applicant.password.request')
                ->withErrors(['email' => __('court::auth.account_not_found_or_deactivated')]);
        }

        $applicant->forceFill([
            'password' => Hash::make($request->password),
            'remember_token' => Str::random(60),
        ])->save();

        event(new PasswordReset($applicant));

        session()->forget('applicant_pwd_verified_email');

        Auth::guard('applicant')->login($applicant);
        $request->session()->regenerate();

        return redirect()->route('court.applicant.dashboard')
            ->with('success', __('court::auth.password_reset_success_signed_in'));
    }
}
