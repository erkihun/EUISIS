<?php

declare(strict_types=1);

namespace App\Services\PublicSite;

use Illuminate\Support\Facades\Route;

/**
 * The one allow-list for every link an administrator can put on the public
 * site: navigation, footer, service actions and page CTAs.
 *
 * Two kinds of target are accepted and nothing else:
 *   - an internal PUBLIC route, chosen by name from ROUTES; and
 *   - an external https URL.
 *
 * Everything else is refused — `javascript:`, `data:`, `vbscript:`, plain
 * `http:`, protocol-relative `//host`, and any relative path — which rules out
 * pointing a public link at an admin page such as `/system-settings`.
 */
final class PublicUrlPolicy
{
    /**
     * Public routes a link may target, with the label key the admin UI shows.
     * Only anonymous-accessible pages belong here.
     */
    public const ROUTES = [
        'home' => 'nav.home',
        'public.announcements' => 'nav.announcements',
        'public.verify' => 'nav.verifyIdCard',
        'public.services' => 'nav.services',
        'public.support' => 'nav.support',
        'id-checker.index' => 'publicSite.routes.idChecker',
    ];

    public static function isAllowedRoute(?string $routeName): bool
    {
        return $routeName !== null
            && array_key_exists($routeName, self::ROUTES)
            && Route::has($routeName);
    }

    public static function isAllowedExternalUrl(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        $url = trim($url);

        // Control characters and whitespace are how scheme filters get bypassed
        // ("java\tscript:"); a legitimate URL never contains them.
        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && ! empty($parts['host'])
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Validation rule closure for a link target pair.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $typeField = 'type', string $routeField = 'route_name', string $urlField = 'url'): array
    {
        return [
            $typeField => ['required', 'in:route,external'],
            $routeField => [
                "required_if:{$typeField},route",
                'nullable',
                'string',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null && ! self::isAllowedRoute((string) $value)) {
                        $fail(__('validation.public_site.route_not_allowed'));
                    }
                },
            ],
            $urlField => [
                "required_if:{$typeField},external",
                'nullable',
                'string',
                'max:500',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null && $value !== '' && ! self::isAllowedExternalUrl((string) $value)) {
                        $fail(__('validation.public_site.url_not_allowed'));
                    }
                },
            ],
        ];
    }

    /** Resolves a stored target to an href, or null if it is no longer valid. */
    public static function resolve(string $type, ?string $routeName, ?string $url): ?string
    {
        if ($type === 'route') {
            return self::isAllowedRoute($routeName) ? route($routeName, absolute: false) : null;
        }

        return self::isAllowedExternalUrl($url) ? trim((string) $url) : null;
    }
}
