<?php

declare(strict_types=1);

namespace App\Services\PublicAssistant;

/**
 * The public pages the assistant knows (docs/public-assistant/architecture.md, P-2)
 * and the only routes it may ever link to. Everything else — admin, portals,
 * Situation Room, diagnostics — simply does not exist here.
 */
final class PublicAssistantPageRegistry
{
    public const UNKNOWN = 'unknown';

    /** @var array<string, string> route name → page kind */
    private const ROUTES = [
        'home' => 'home',
        'public.services' => 'services',
        'public.services.show' => 'service',
        'public.announcements' => 'announcements',
        'public.announcements.show' => 'announcements',
        'public.transfer-announcements' => 'announcements',
        'public.transfer-announcements.show' => 'announcements',
        'public.support' => 'support',
        'public.verify' => 'verify',
        'id-checker.index' => 'id_checker',
        'id-checker.show' => 'id_checker',
        'service-feedback.show' => 'employee_qr',
        'service-feedback.form' => 'feedback_form',
        'public-feedback.organization.show' => 'organization_qr',
        'public-feedback.organization.form' => 'feedback_form',
    ];

    /** @var array<string, string> navigation key → route name (parameter-less public routes only) */
    public const NAVIGATION = [
        'home' => 'home',
        'services' => 'public.services',
        'announcements' => 'public.announcements',
        'support' => 'public.support',
        'verify' => 'public.verify',
        'id_checker' => 'id-checker.index',
    ];

    public static function kind(?string $routeName, string $path = ''): string
    {
        if ($routeName !== null && isset(self::ROUTES[$routeName])) {
            return self::ROUTES[$routeName];
        }

        return match (true) {
            $path === '/' || $path === '' => 'home',
            str_starts_with($path, '/feedback/') => 'organization_qr',
            str_starts_with($path, '/service-feedback/') => 'employee_qr',
            str_starts_with($path, '/services/') => 'service',
            str_starts_with($path, '/services') => 'services',
            str_starts_with($path, '/announcements') => 'announcements',
            str_starts_with($path, '/support') => 'support',
            str_starts_with($path, '/verify') => 'verify',
            str_starts_with($path, '/id-checker') => 'id_checker',
            default => self::UNKNOWN,
        };
    }

    /** The QR token when the path is a QR page; nothing else is ever read from the path. */
    public static function tokenFromPath(string $path): ?string
    {
        return preg_match('#^/(?:service-feedback|feedback)/([A-Za-z0-9_-]{8,128})(?:/|$)#', $path, $m) === 1 ? $m[1] : null;
    }

    /** The form type when the path is a typed QR form. */
    public static function formTypeFromPath(string $path): ?string
    {
        return preg_match('#^/(?:service-feedback|feedback)/[A-Za-z0-9_-]+/(feedback|suggestion|complaint)$#', $path, $m) === 1 ? $m[1] : null;
    }

    /** @return list<array{key: string, label: string, href: string}> */
    public static function navigation(string $locale, array $only = []): array
    {
        $items = [];
        foreach (self::NAVIGATION as $key => $route) {
            if ($only !== [] && ! in_array($key, $only, true)) {
                continue;
            }
            $items[] = ['key' => $key, 'label' => (string) __('public_assistant.navigation.'.$key, [], $locale), 'href' => self::relative(route($route))];
        }

        return $items;
    }

    public static function relative(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);

        return $path.($query ? '?'.$query : '');
    }
}
