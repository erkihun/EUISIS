<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Core\CoreWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks the session as working in Core. Runs after `can:core.access`, so only
 * a person allowed into Core ever gets the flag.
 */
class EnterCoreWorkspace
{
    public function __construct(private readonly CoreWorkspace $workspace) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->workspace->enter($request->session());

        return $next($request);
    }
}
