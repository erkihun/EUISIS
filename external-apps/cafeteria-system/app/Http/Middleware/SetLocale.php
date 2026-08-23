<?php

declare(strict_types=1);

namespace CafeteriaSystem\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the operator's chosen interface language for the request.
 *
 * The choice lives in the session rather than on the user row: a cafeteria
 * terminal is often shared between operators on one login, and switching
 * language should not rewrite another person's stored preference.
 */
class SetLocale
{
    /** @var array<int, string> */
    public const SUPPORTED = ['en', 'am'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = (string) $request->session()->get('locale', config('app.locale', 'en'));

        // Never trust a session value blindly — an unknown code would make
        // every translation fall back and the UI read as raw keys.
        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = 'en';
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
