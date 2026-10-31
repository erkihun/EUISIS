<?php

namespace Modules\Court\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        $user = $request->user();

        if (!$user || !method_exists($user, 'hasPermission')) {
            return redirect()->route('court.dashboard')
                ->with('error', 'You are not allowed to access that page.');
        }

        // Admin bypass
        if (method_exists($user, 'hasRole') && $user->hasRole('admin')) {
            return $next($request);
        }

        if (!$user->hasPermission($permission)) {
            return redirect()->route('court.dashboard')
                ->with('error', 'You are not allowed to access that page.');
        }

        return $next($request);
    }
}