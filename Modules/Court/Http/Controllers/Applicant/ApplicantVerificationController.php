<?php

namespace Modules\Court\Http\Controllers\Applicant;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Application\Notifications\ApplicantEmailOtp;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class ApplicantVerificationController extends Controller
{
    /** Generate a fresh OTP, store hash in session, return the plain code. */
    private function generateOtp(int $ttlMinutes = 10): string
    {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        session([
            'email_otp' => hash('sha256', $otp),
            'email_otp_expires_at' => now()->addMinutes($ttlMinutes)->timestamp,
        ]);

        return $otp;
    }

    /** Show the OTP form, auto-sending a code if none is already pending. */
    public function notice(Request $request)
    {
        /** @var Applicant|null $user */
        $user = $request->user('applicant');

        if (! $user) {
            return redirect()->route('court.applicant.login');
        }
        if ($user->hasVerifiedEmail()) {
            return redirect()->route('court.applicant.dashboard')->with('success', __('court::auth.email_already_verified'));
        }

        // Only send a new OTP when there is no unexpired one already in session
        if (! session('email_otp') || now()->timestamp > session('email_otp_expires_at', 0)) {
            try {
                $otp = $this->generateOtp();
                $user->notifyNow(new ApplicantEmailOtp($otp));
            } catch (\Throwable $e) {
                // The code was never delivered — clear it so the next visit retries
                // instead of silently skipping for the whole TTL window.
                session()->forget(['email_otp', 'email_otp_expires_at']);
                Log::error('[VerifyEmail] OTP send failed: '.$e->getMessage());
            }
        }

        // React, Court/Applicant/VerifyEmail.
        return Inertia::render('Court/Applicant/VerifyEmail');
    }

    /** Resend a fresh OTP code to the authenticated applicant. */
    public function send(Request $request)
    {
        /** @var Applicant|null $user */
        $user = $request->user('applicant');

        if (! $user) {
            return redirect()->route('court.applicant.login');
        }
        if ($user->hasVerifiedEmail()) {
            return back()->with('success', __('court::auth.email_already_verified'));
        }

        try {
            $otp = $this->generateOtp();
            $user->notifyNow(new ApplicantEmailOtp($otp));

            return back()->with('success', __('court::auth.verification_code_sent_to_email'));
        } catch (\Throwable $e) {
            session()->forget(['email_otp', 'email_otp_expires_at']);
            Log::error('[VerifyEmail] OTP resend failed: '.$e->getMessage());

            return back()->withErrors(['code' => __('court::auth.verification_code_send_failed')]);
        }
    }

    /** Verify the OTP submitted from the email-verify form. */
    public function verifyEmailOtp(Request $request)
    {
        $request->validate(['code' => ['required', 'digits:6']]);

        /** @var Applicant|null $user */
        $user = $request->user('applicant');

        if (! $user) {
            return redirect()->route('court.applicant.login');
        }
        if ($user->hasVerifiedEmail()) {
            return redirect()->route('court.applicant.dashboard');
        }

        $storedHash = session('email_otp');
        $expiresAt = session('email_otp_expires_at');

        if (! $storedHash || ! $expiresAt) {
            return redirect()->route('court.applicant.verification.notice')
                ->withErrors(['code' => __('court::auth.verification_code_missing')]);
        }

        if (now()->timestamp > $expiresAt) {
            return back()->withErrors(['code' => __('court::auth.verification_code_expired')]);
        }

        if (! hash_equals($storedHash, hash('sha256', $request->input('code')))) {
            return back()->withErrors(['code' => __('court::auth.verification_code_invalid')]);
        }

        $user->email_verified_at = now();
        $user->save();

        session()->forget(['email_otp', 'email_otp_expires_at']);

        return redirect()->route('court.applicant.dashboard')->with('success', __('court::auth.email_verified_successfully'));
    }

    /** Handle the signed verification link (kept for backward compatibility). */
    public function verify(EmailVerificationRequest $request)
    {
        Auth::shouldUse('applicant');

        if ($request->user() && ! $request->user()->hasVerifiedEmail()) {
            $request->fulfill();
        }

        return redirect()->route('court.applicant.dashboard')->with('success', __('court::auth.email_verified_successfully'));
    }
}
