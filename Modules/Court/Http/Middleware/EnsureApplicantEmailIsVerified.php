<?php

namespace Modules\Court\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureApplicantEmailIsVerified
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth('applicant')->user();

        if (!$user) {
            return redirect()->route('court.applicant.login');
        }

        // works because Applicant implements MustVerifyEmail
        if (!$user->hasVerifiedEmail()) {
            return redirect()->route('court.applicant.verification.notice');
        }

        return $next($request);
    }
}
