<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Communication\Application\Services\CommunicationSettings;
use Symfony\Component\HttpFoundation\Response;

/**
 * Communication is closed when switched off (404, nothing deleted) and needs
 * `communication.access` (403). Applied to every Communication route.
 */
class EnsureCommunicationEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app(CommunicationSettings::class)->enabled(), 404);
        abort_unless((bool) $request->user()?->can('communication.access'), 403);

        return $next($request);
    }
}
