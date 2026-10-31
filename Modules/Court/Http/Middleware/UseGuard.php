<?php

namespace Modules\Court\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class UseGuard
{
    /**
     * Ensure the desired guard is used for the request lifecycle.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $guard): Response
    {
        Auth::shouldUse($guard);
        $request->session()->put('_auth_guard', $guard);

        return $next($request);
    }
}
