<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hold a user on the change-password screen until they choose their own password.
 *
 * An account created or reset by an administrator has a password that
 * administrator knows. Until the holder replaces it the credential is shared,
 * so no protected route may be reached with it.
 *
 * Two things stay reachable, or the user would be trapped:
 *  - the forced change screen itself and the route that submits it,
 *  - logout,
 *
 * This gate is applied only to the primary web guard. MFA enforcement resumes
 * after the shared password has been replaced; separate provider guards pass
 * through their existing authentication flows unchanged.
 */
class ForcePasswordChange
{
    /**
     * Routes a user under a forced password change may still reach.
     *
     * Matched with `routeIs`, so wildcards cover a whole family — `password.*`
     * keeps reset and confirmation working for someone who never received the
     * temporary password at all.
     */
    private const ALLOWED_ROUTES = [
        'password.forced',
        'password.forced.update',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! method_exists($user, 'mustChangePassword') || ! $user->mustChangePassword()) {
            return $next($request);
        }

        foreach (self::ALLOWED_ROUTES as $pattern) {
            if ($request->routeIs($pattern)) {
                return $next($request);
            }
        }

        /*
         * An Inertia visit expects a redirect it can follow; a bare XHR that is
         * not an Inertia request gets a 403 instead, so an API-style caller is
         * not handed an HTML redirect it cannot use.
         */
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(403, __('auth.must_change_password'));
        }

        return redirect()->route('password.forced');
    }
}
