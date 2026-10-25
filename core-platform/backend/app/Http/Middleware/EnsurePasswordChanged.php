<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An account given a one-time password replaces it before anything else
 * (the screens' side of EnsureCoreUser's PASSWORD_CHANGE_REQUIRED), on the
 * EUISIS forced-password page.
 */
class EnsurePasswordChanged
{
    private const ALLOWED = ['password.forced', 'password.forced.update', 'logout', 'session.*', 'notifications.*'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user('web')?->must_change_password && ! $request->routeIs(...self::ALLOWED)) {
            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['message' => 'Choose a new password before continuing.', 'code' => 'PASSWORD_CHANGE_REQUIRED'], 403)
                : redirect()->route('password.forced');
        }

        return $next($request);
    }
}
