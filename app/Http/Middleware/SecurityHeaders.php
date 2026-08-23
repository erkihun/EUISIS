<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // ── Anti-clickjacking ────────────────────────────────────────────────
        // DENY prevents framing by any origin, including same-origin.
        // frame-ancestors 'none' in CSP supersedes this in modern browsers;
        // both are set for defence-in-depth with legacy browser support.
        $response->headers->set('X-Frame-Options', 'DENY');

        // ── MIME-type sniffing prevention ────────────────────────────────────
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // ── Referrer control ─────────────────────────────────────────────────
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // ── Browser feature restrictions ─────────────────────────────────────
        $response->headers->set(
            'Permissions-Policy',
            'camera=(self), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()'
        );

        // ── Cross-domain policy files ─────────────────────────────────────────
        // Prevents Adobe Flash / PDF plugins from loading cross-domain policies.
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        // ── Cross-Origin isolation ────────────────────────────────────────────
        // COOP prevents cross-origin windows from accessing this window object.
        // CORP prevents other origins from loading this resource via <img>/<script>.
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        // ── Content Security Policy ───────────────────────────────────────────
        // Key directives:
        //   frame-ancestors 'none' — prevents embedding in any frame (clickjacking)
        //   object-src 'none'      — blocks Flash/ActiveX/plugins
        //   base-uri 'self'        — prevents base-tag injection
        //   form-action 'self'     — prevents cross-origin form submission
        //
        // In local development the Vite dev server runs on a separate origin
        // (typically http://[::1]:5173 or http://localhost:5173). Scripts and
        // WebSocket HMR connections from that origin are explicitly allowed here so
        // the browser does not block hot-module replacement.
        //
        // NOTE: Hardening path — replace 'unsafe-inline' in script-src with
        // nonce-based CSP using Laravel's Vite::useCspNonce() integration.
        $isLocal = app()->environment('local');

        // Include the host the request actually arrived on, so a device on the
        // LAN testing against http://<ip>:8000 can still reach the Vite dev
        // server. A fixed localhost list only works when the browser and the
        // server are the same machine — a phone scanning a QR is not.
        $devHosts = ['localhost', '127.0.0.1', '[::1]'];

        if ($isLocal && ! in_array($request->getHost(), $devHosts, true)) {
            $devHosts[] = $request->getHost();
        }

        $viteHttp = implode(' ', array_map(static fn (string $host): string => "http://{$host}:5173", $devHosts));
        $viteWs = implode(' ', array_map(static fn (string $host): string => "ws://{$host}:5173", $devHosts));

        $scriptSrc = $isLocal
            ? "script-src 'self' 'unsafe-inline' {$viteHttp}"
            : "script-src 'self' 'unsafe-inline'";

        $connectSrc = $isLocal
            ? "connect-src 'self' ws: wss: {$viteWs} {$viteHttp}"
            : "connect-src 'self' ws: wss:";

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            $scriptSrc,
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            $connectSrc,
            "frame-ancestors 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]));

        // ── HSTS (HTTPS only) ─────────────────────────────────────────────────
        // Only sent when the request was served over HTTPS to avoid accidentally
        // pinning a site that later loses its certificate, or breaking plain-HTTP
        // local development.  63 072 000 s = 2 years.
        if ($request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=63072000; includeSubDomains; preload'
            );
        }

        return $response;
    }
}
