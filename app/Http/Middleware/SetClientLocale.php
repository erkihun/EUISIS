<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server text — validation errors, flash notices, computed problems — in the
 * language the viewer chose in the browser. The UI keeps that choice in
 * localStorage and mirrors it into the `euisis_locale` cookie (unencrypted:
 * it is a display preference, nothing secret); a client may also send an
 * `X-Locale` header. Anything unsupported, or no choice, keeps the default.
 */
class SetClientLocale
{
    public const COOKIE = 'euisis_locale';

    /** @var list<string> */
    public const SUPPORTED = ['en', 'am'];

    public function handle(Request $request, Closure $next): Response
    {
        foreach ([$request->header('X-Locale'), $request->cookie(self::COOKIE)] as $candidate) {
            if (is_string($candidate) && in_array($candidate, self::SUPPORTED, true)) {
                app()->setLocale($candidate);
                break;
            }
        }

        return $next($request);
    }
}
