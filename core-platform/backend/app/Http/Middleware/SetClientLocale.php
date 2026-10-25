<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The request's language from the browser (EUISIS SetClientLocale): the
 * X-Locale header or the euisis_locale cookie the language switcher writes,
 * so flash messages and validation errors come back in the page's language.
 */
class SetClientLocale
{
    public const COOKIE = 'euisis_locale';

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
