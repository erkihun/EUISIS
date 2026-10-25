<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers on every API answer: no MIME sniffing, no framing, no referrer, and
 * no caching of authenticated data by browsers or proxies; HSTS when served
 * over HTTPS.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        if ($request->is('api/*') && $request->bearerToken() !== null) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        // A content security policy for the screens, reported only for now (ADR 0011): violations show in the
        // browser console without breaking a page; enforce it once a browser pass shows no violation.
        if (! $response->headers->has('Content-Security-Policy') && str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->headers->set('Content-Security-Policy-Report-Only', "default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; "
                ."script-src 'self'; connect-src 'self'; font-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
        }

        return $response;
    }
}
