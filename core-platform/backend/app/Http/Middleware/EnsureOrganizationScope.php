<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An account without an organization scope reaches no organizational data
 * (ADR 0008). Every query already fails closed for it; this says so plainly
 * instead of showing empty pages: on the screens it may only sign out, manage
 * its own password, MFA and profile, and see the dashboard with a warning; any
 * other page or action answers 403 "This user has no organization scope."
 */
class EnsureOrganizationScope
{
    public const MESSAGE = 'This user has no organization scope.';

    private const ALLOWED = [
        'logout', 'password.*', 'session.*', 'notifications.*', 'profile.*', 'mfa.*',
        'dashboard', 'core.dashboard', 'core.exit',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');
        if (! $user instanceof User || $user->hasOrganizationScope()) {
            return $next($request);
        }
        if ($request->routeIs(...self::ALLOWED)) {
            if ($request->routeIs('core.dashboard')) {
                $request->session()->now('warning', __(self::MESSAGE));
            }

            return $next($request);
        }

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json(['message' => __(self::MESSAGE), 'code' => 'NO_ORGANIZATION_SCOPE'], 403)
            : abort(403, __(self::MESSAGE));
    }
}
