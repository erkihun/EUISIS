<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\CafeteriaPlatform\CafeteriaPlatformClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the cafeteria runs on its own platform (CAFETERIA_PLATFORM_MODE=platform,
 * ADR 0014), the cafeteria pages of EUISIS send people there: the platform
 * serves the same screens on the same paths, so only the host changes. The
 * EUISIS cafeteria tables and code stay in place for the records they hold.
 */
final class RedirectCafeteriaToPlatform
{
    /** Route-name prefixes the platform serves: the administration module and the cafeteria side of the provider portal. */
    private const PREFIXES = [
        'cafeteria.', 'provider.portal.cafeteria.', 'provider.portal.scan', 'provider.portal.transactions.', 'provider.portal.menus.',
        'provider.portal.orders.', 'provider.portal.ledger.', 'provider.portal.reports.', 'cafe.login',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->route()?->getName();
        if ($name !== null && config('cafeteria_platform.mode') === 'platform' && (string) config('cafeteria_platform.url') !== '' && self::owned($name)) {
            return redirect()->away(app(CafeteriaPlatformClient::class)->url($request->getRequestUri()));
        }

        return $next($request);
    }

    public static function owned(string $routeName): bool
    {
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
