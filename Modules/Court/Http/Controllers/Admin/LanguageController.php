<?php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Http\Middleware\SetLocale;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;

class LanguageController extends Controller
{
    public function switch(Request $request, string $locale)
    {
        $allowed = config('court.locales', ['en', 'am']);
        $allowed = array_unique(array_merge($allowed, ['am_ET']));

        if (!in_array($locale, $allowed, true)) {
            $locale = config('app.fallback_locale', 'en');
        }

        if (str_starts_with($locale, 'am')) {
            $locale = 'am';
        }

        session(['app_locale' => $locale]);
        Cookie::queue('app_locale', $locale, 60 * 24 * 365);
        // The React screens keep the language in this cookie (unencrypted, as the client writes it).
        Cookie::queue(Cookie::make(SetLocale::CLIENT_COOKIE, $locale, 60 * 24 * 365, '/', null, null, false, false, 'lax'));

        App::setLocale($locale);

        // Both candidates are user-controlled (query string, Referer header),
        // so only same-site destinations are honoured.
        $return = $this->sameSiteUrl($request, $request->query('return'))
            ?? $this->sameSiteUrl($request, url()->previous(route('court.root')))
            ?? route('court.root');

        return redirect()->to($return);
    }

    /**
     * Returns the URL when it points into this application, otherwise null.
     * Accepts site-relative paths ("/cases") and absolute http(s) URLs on the
     * application's own host; rejects other hosts, "//host" and "/\host"
     * (both protocol-relative in browsers) and non-http schemes.
     */
    private function sameSiteUrl(Request $request, mixed $candidate): ?string
    {
        if (! is_string($candidate) || $candidate === '') {
            return null;
        }

        if (preg_match('#^/(?![/\\\\])#', $candidate) === 1) {
            return url($candidate);
        }

        $parts = parse_url($candidate);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $allowedHosts = array_filter([
            strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
            strtolower($request->getHost()),
        ]);

        return in_array(strtolower($parts['host']), $allowedHosts, true) ? $candidate : null;
    }
}
