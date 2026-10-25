<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
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
        // One nonce per request for the page's own inline script (the theme bootstrap) and Vite's tags (ADR 0012).
        Vite::useCspNonce();
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
        // The screens' content security policy (ADR 0011, ADR 0012). Report-only unless CORE_CSP_ENFORCE=true:
        // the staging browser pass found one violation, the inline theme script, which now carries the nonce.
        if (! $response->headers->has('Content-Security-Policy') && str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->headers->set(config('core.csp_enforce') ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only',
                "default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self' 'nonce-".Vite::cspNonce()."'; "
                ."connect-src 'self'; font-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
        }

        return $response;
    }
}
