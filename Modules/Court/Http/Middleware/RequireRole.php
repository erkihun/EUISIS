<?php

namespace Modules\Court\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireRole
{
    public function handle(Request $request, Closure $next, string $role)
    {
        $user = $request->user();

        if (!$user || !method_exists($user, 'hasRole') || !$user->hasRole($role)) {
            return redirect()->route('court.dashboard')
                ->with('error', 'You are not allowed to access that page.');
        }

        return $next($request);
    }
}
