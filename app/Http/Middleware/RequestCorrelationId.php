<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestCorrelationId
{
    public const HEADER = 'X-Request-ID';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header(self::HEADER, '');

        // Accept an incoming safe request ID (alphanumeric + hyphens, max 64 chars).
        $correlationId = (is_string($incoming) && preg_match('/^[a-zA-Z0-9\-]{1,64}$/', $incoming))
            ? $incoming
            : (string) Str::uuid();

        // Share with the request so controllers/services can read it.
        $request->attributes->set('correlation_id', $correlationId);

        // Make it available globally for the current request cycle.
        app()->instance('correlation_id', $correlationId);

        $response = $next($request);

        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
