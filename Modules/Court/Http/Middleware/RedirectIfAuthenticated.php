<?php

namespace Modules\Court\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RedirectIfAuthenticated
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ...$guards)
    {
        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                // Applicant guard: redirect to applicant/respondent dashboard
                if ($guard === 'applicant') {
                    if ($request->query('login_as') === 'respondent') {
                        if (!$request->session()->get('acting_as_respondent')) {
                            $request->session()->regenerate();
                        }
                        $request->session()->put('acting_as_respondent', true);
                        return redirect()->route('court.respondent.dashboard');
                    }
                    $request->session()->forget('acting_as_respondent');
                    return redirect()->route('court.applicant.dashboard');
                }

                // Admin (web) guard or default guard: redirect to admin dashboard
                if ($guard === null || $guard === 'web') {
                    return redirect()->route('court.dashboard');
                }

                // Any other guard: go to admin dashboard as fallback
                return redirect()->route('court.dashboard');
            }
        }

        return $next($request);
    }
}
