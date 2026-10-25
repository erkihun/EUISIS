<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An authenticated Core user who is still active; with `writer`, one whose role
 * may change master data (core_officer or core_admin).
 */
class EnsureCoreUser
{
    public function handle(Request $request, Closure $next, ?string $level = null): Response
    {
        $user = $request->user();
        abort_if($user === null || ! $user->is_active, 403, 'This Core account is not active.');
        abort_if($level === 'writer' && ! $user->canChangeMasterData(), 403, 'Your Core role may not change master data.');

        return $next($request);
    }
}
