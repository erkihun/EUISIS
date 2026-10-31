<?php

declare(strict_types=1);

namespace Modules\Court\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Modules\Court\Application\Support\EuisisParties;
use Modules\Court\Domain\Models\CourtStaffMember;
use Modules\Court\Http\Support\Ui\CourtNavigation;
use Modules\Court\Http\Support\Ui\PortalNavigation;
use Symfony\Component\HttpFoundation\Response;

/**
 * The court's own page props on its routes (court-ms HandleInertiaRequests):
 * the sidebar workspace (staff menu or a portal's), the header's portal links
 * and active-role switch, court flashes, and the applicant portal's state.
 * Everything is a closure, read when the page renders (after the guard middleware).
 */
final class ShareCourtProps
{
    public function handle(Request $request, Closure $next): Response
    {
        $portal = static fn (): ?string => PortalNavigation::portalFor($request);
        $staff = static function () use ($portal): ?CourtStaffMember {
            $user = $portal() === null ? Auth::guard('court')->user() : null;

            return $user instanceof CourtStaffMember ? $user : null;
        };
        $baseAuth = Inertia::getShared('auth');
        $baseFlash = Inertia::getShared('flash');

        Inertia::share([
            'auth' => function () use ($portal, $staff, $baseAuth): array {
                if ($portal() !== null) {
                    return $this->portalAuth();
                }
                $base = (array) value($baseAuth);
                $user = $staff();
                if ($user === null) {
                    return $base;
                }
                $roles = $user->roles()->orderBy('court_roles.name')->get();

                return [
                    ...$base,
                    'activeRoleId' => $user->activeRole()?->id,
                    'roleOptions' => $roles->count() > 1
                        ? $roles->map(fn ($role): array => ['id' => (int) $role->id, 'label' => Str::headline((string) $role->name)])->values()->all()
                        : [],
                ];
            },
            'portal' => fn (): ?array => ($name = $portal()) !== null ? PortalNavigation::routes($name) : null,
            'workspace' => function () use ($portal, $staff): ?array {
                if (($name = $portal()) !== null) {
                    return PortalNavigation::user() ? ['name' => 'court', 'home' => PortalNavigation::routes($name)['home'], 'nav' => PortalNavigation::sections($name)] : null;
                }
                $user = $staff();

                return $user ? [
                    'name' => 'court',
                    'home' => 'court.admin.dashboard',
                    'exit' => 'dashboard',
                    'nav' => app(CourtNavigation::class)->for($user),
                    // The header bell reads the court's own feed.
                    'bell' => ['feed' => 'court.notifications.feed', 'read' => 'court.notifications.read', 'readAll' => 'court.notifications.read-all', 'viewAll' => 'court.admin.notifications.index'],
                ] : null;
            },
            'flash' => fn (): array => [
                ...(array) value($baseFlash),
                // Some court controllers flash `ok` for a success, or a translation key with replacements.
                'success' => $this->flashText(session('success') ?? session('ok')),
                'error' => $this->flashText(session('error')),
                'warning' => $this->flashText(session('warning')),
                'info' => $this->flashText(session('info')),
            ],
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
                'can_file_for_client' => \Modules\Court\Application\Services\CaseParties::isVerifiedLawyer($user),
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
