<?php

declare(strict_types=1);

namespace Modules\Court\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Court\Application\Services\CaseParties;
use Modules\Court\Application\Support\EuisisParties;
use Modules\Court\Http\Support\Ui\PortalNavigation;
use Symfony\Component\HttpFoundation\Response;

/**
 * The court's own page props on its routes (court-ms HandleInertiaRequests):
 * a portal's sidebar and header links, court flashes, the court's language and
 * the applicant portal's state. Staff pages keep EUISIS's own menu, account,
 * settings and branding. Everything is a closure, read when the page renders.
 */
final class ShareCourtProps
{
    public function handle(Request $request, Closure $next): Response
    {
        $portal = static fn (): ?string => PortalNavigation::portalFor($request);
        $baseAuth = Inertia::getShared('auth');
        $baseFlash = Inertia::getShared('flash');

        Inertia::share([
            // Staff pages keep EUISIS's own account, menu, settings and branding (EUISIS manages them);
            // only the applicant and respondent portals have the court's own.
            'auth' => fn (): array => $portal() !== null ? $this->portalAuth() : (array) value($baseAuth),
            'portal' => fn (): ?array => ($name = $portal()) !== null ? PortalNavigation::routes($name) : null,
            'workspace' => function () use ($portal): ?array {
                if (($name = $portal()) === null) {
                    return null;
                }

                return PortalNavigation::user() ? ['name' => 'court', 'home' => PortalNavigation::routes($name)['home'], 'nav' => PortalNavigation::sections($name)] : null;
            },
            'flash' => fn (): array => [
                ...(array) value($baseFlash),
                // Some court controllers flash `ok` for a success, or a translation key with replacements.
                'success' => $this->flashText(session('success') ?? session('ok')),
                'error' => $this->flashText(session('error')),
                'warning' => $this->flashText(session('warning')),
                'info' => $this->flashText(session('info')),
            ],
            // The court's language (SetLocale, route middleware) is known only when the page renders.
            'locale' => fn (): string => app()->getLocale() === 'am' ? 'am' : 'en',
            'textLocale' => fn (): string => app()->getLocale(),
            // Flow 6: whether the applicant/respondent portal is closed, and where employees go instead.
            'courtPortal' => fn (): array => ['closed' => EuisisParties::portalClosed(), 'url' => EuisisParties::portalUrl()],
        ]);

        return $next($request);
    }

    /** An applicant (or the same person acting as respondent): no roles or staff permissions. @return array<string, mixed> */
    private function portalAuth(): array
    {
        $user = PortalNavigation::user();

        return [
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->full_name,
                'email' => $user->email,
                'status' => $user->is_active ? 'active' : 'inactive',
                'profile_photo_url' => null,
                'initials' => mb_strtoupper(implode('', array_map(fn (string $part): string => mb_substr($part, 0, 1), array_slice(preg_split('/\s+/u', trim((string) $user->full_name)) ?: [], 0, 2)))),
                'is_lawyer' => (bool) $user->is_lawyer,
                'can_file_for_client' => CaseParties::isVerifiedLawyer($user),
            ] : null,
            'roles' => [],
            'activeRoleId' => null,
            'roleOptions' => [],
            'permissions' => [],
            'isSuperAdmin' => false,
            'canViewDashboard' => $user !== null,
            'unrestrictedScope' => false,
        ];
    }

    private function flashText(mixed $value): ?string
    {
        if (is_array($value) && isset($value['key'])) {
            return (string) __((string) $value['key'], (array) ($value['replace'] ?? []));
        }

        return is_string($value) ? __($value) : null;
    }
}
