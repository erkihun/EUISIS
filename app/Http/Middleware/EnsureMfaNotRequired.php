<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Auth\MfaController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inverse of RequireMfa: lets a request through when the authenticated user
 * does NOT need to complete an MFA flow (either their role doesn't require
 * MFA, or they've already verified this session). Used to bounce users away
 * from /mfa/setup once enrolment is complete.
 */
class EnsureMfaNotRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if (! $user->requiresMfa()) {
            return $next($request);
        }

        if ($user->hasMfaEnabled() && MfaController::sessionVerified($request)) {
            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}
