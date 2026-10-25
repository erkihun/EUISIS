<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\CoreSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a browser session out after Core settings › Security › session length
 * without activity, as EUISIS does. The page's own polling (X-Activity:
 * passive) does not count as activity. An expired XHR gets 401 with the
 * `idle_timeout` reason and the sign-in address (bootstrap.ts follows it).
 */
class TrackSessionActivity
{
    public const KEY = 'core.last_activity';

    public static function timeoutSeconds(): int
    {
        return app(CoreSettings::class)->int('security.session_timeout_minutes') * 60;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('web')->check()) {
            $last = (int) $request->session()->get(self::KEY, time());
            if (time() - $last > self::timeoutSeconds()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $request->expectsJson() && ! $request->header('X-Inertia')
                    ? response()->json(['reason' => 'idle_timeout', 'redirect' => route('login')], 401)
                    : redirect()->route('login')->with('session_notice', 'idle_timeout');
            }
            if ($request->header('X-Activity') !== 'passive') {
                $request->session()->put(self::KEY, time());
            }
        }

        return $next($request);
    }

    public static function remainingSeconds(Request $request): int
    {
        return max(0, self::timeoutSeconds() - (time() - (int) $request->session()->get(self::KEY, time())));
    }
}
