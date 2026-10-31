<?php

namespace Modules\Court\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle($request, \Closure $next, string $perm)
    {
        $user = $request->user();
        abort_if(!$user, 403);
        $perms = array_filter(array_map('trim', explode('|', $perm)));
        foreach ($perms as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        abort(403, 'Insufficient permissions.');
    }
}
