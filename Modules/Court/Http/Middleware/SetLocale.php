<?php

namespace Modules\Court\Http\Middleware;

use Modules\Court\Domain\Models\SystemSetting;
use Closure;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;

class SetLocale
{
    /**
     * The React screens (copied from EUISIS) keep the language in localStorage
     * and mirror it into this unencrypted cookie, as EUISIS SetClientLocale reads it.
     */
    public const CLIENT_COOKIE = 'euisis_locale';

    public function handle($request, Closure $next)
    {
        // Admin-configured default locale (system settings) takes precedence over
        // the static config default. A user's explicit choice (the React client's
        // header or cookie, then the session/cookie the language switch sets)
        // still overrides the admin default.
        $client = $request->header('X-Locale') ?? $request->cookie(self::CLIENT_COOKIE);
        $locale = (in_array($client, ['en', 'am'], true) ? $client : null)
            ?? session('app_locale')
            ?? $request->cookie('app_locale')
            ?? $this->defaultLocale();

        $allowed = config('app.locales', ['en', 'am']);
        if (! in_array($locale, $allowed, true) && ! str_starts_with((string) $locale, 'am')) {
            $locale = config('app.fallback_locale', 'en');
        }

        if (is_string($locale) && str_starts_with($locale, 'am')) {
            $locale = 'am';
        }

        App::setLocale($locale);

        return $next($request);
    }

    /** The default locale configured by an admin, falling back to config. */
    private function defaultLocale(): string
    {
        try {
            if (Schema::hasTable('court_system_settings')) {
                $default = SystemSetting::cached()?->default_locale;
                if (is_string($default) && $default !== '') {
                    return $default;
                }
            }
        } catch (\Throwable) {
            // Fall through to config default.
        }

        return config('app.locale', 'en');
    }
}
