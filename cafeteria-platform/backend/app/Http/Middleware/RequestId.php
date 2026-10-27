<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request id on every request (the caller's X-Request-ID when it is safe, or a
 * new UUID): on the response, on every audit row and in every log line, so a
 * call from EUISIS can be followed into the Core.
 */
class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->header('X-Request-ID', '');
        $id = preg_match('/^[A-Za-z0-9\-]{1,64}$/', $incoming) === 1 ? $incoming : (string) Str::uuid();

        $request->attributes->set('request_id', $id);
        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $id);

        return $response;
    }
}
