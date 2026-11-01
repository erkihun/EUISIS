<?php

namespace Modules\Court\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;

class EnsureApplicantIsVerified
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth('applicant')->user();

        // Not logged in as applicant → send to login
        if (!$user) {
            return redirect()->route('court.applicant.login');
        }

        // If the applicant model supports Laravel's verification contract, use it
        if ($user instanceof MustVerifyEmailContract) {
            if ($user->hasVerifiedEmail()) {
                return $next($request);
            }
        } else {
            // Fallback: check the timestamp column directly
            if (!empty($user->email_verified_at)) {
                return $next($request);
            }
        }

        // JSON callers get 409 like Laravel's default middleware
        if ($request->expectsJson()) {
            abort(409, 'Your email address is not verified.');
        }

        // Redirect to your applicant verify notice page
        return redirect()->route('court.applicant.verification.notice');
    }
}
