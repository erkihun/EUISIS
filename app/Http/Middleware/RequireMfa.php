<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Auth\MfaController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces users whose role requires MFA (config('security.mfa_required_roles'))
 * through either the setup flow or the challenge flow before they can access
 * application routes. Users whose role does NOT require MFA pass through
 * untouched, preserving the current dashboard behaviour for everyone else.
 */
class RequireMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        // Allow the test suite (and emergency-disable scenarios) to opt out
        // entirely via config('security.mfa_enforce').
        if (! config('security.mfa_enforce', true)) {
            return $next($request);
        }

        $user = $request->user();
        // Challenged when the role requires MFA, and also when the user enrolled
        // voluntarily: an enrolled factor that is never asked for protects nothing.
        if (! $user || (! $user->requiresMfa() && ! $user->hasMfaEnabled())) {
            return $next($request);
        }

        // Never redirect-loop the MFA pages themselves.
        if ($request->routeIs('mfa.*') || $request->routeIs('logout')) {
            return $next($request);
        }

        if (! $user->hasMfaEnabled()) {
            return redirect()->route('mfa.setup')
                ->with('status', __('security.mfa_setup_required'));
        }

        if (! MfaController::sessionVerified($request)) {
            return redirect()->route('mfa.challenge')
                ->with('status', __('security.mfa_challenge_required'));
        }

        return $next($request);
    }
}
