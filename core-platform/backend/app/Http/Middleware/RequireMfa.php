<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Web\MfaController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An account whose category requires MFA (or that enrolled voluntarily)
 * enrols, then passes the challenge in each session, before any other page,
 * Inertia visit or XHR of the screens; a direct URL, another tab or the
 * browser history cannot skip it, because the check is on every request. Only
 * the MFA pages, sign-out, the forced password change and the session
 * heartbeat pass meanwhile. A refused write is answered 303, so an Inertia
 * PUT or DELETE lands on the MFA page as a GET instead of being replayed there.
 */
class RequireMfa
{
    private const ALLOWED = ['mfa.*', 'logout', 'password.forced', 'password.forced.update', 'session.*'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! MfaController::applies($request) || $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }
        $user = $request->user();
        if ($user->hasMfaEnabled() && MfaController::sessionVerified($request)) {
            return $next($request);
        }
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'message' => $user->hasMfaEnabled() ? 'Multi-factor authentication is required for this session.' : 'MFA enrollment is required for this account.',
                'code' => $user->hasMfaEnabled() ? 'MFA_REQUIRED' : 'MFA_ENROLLMENT_REQUIRED',
            ], 403);
        }

        return redirect()->guest(route($user->hasMfaEnabled() ? 'mfa.challenge' : 'mfa.setup'), $request->isMethod('GET') ? 302 : 303);
    }
}
