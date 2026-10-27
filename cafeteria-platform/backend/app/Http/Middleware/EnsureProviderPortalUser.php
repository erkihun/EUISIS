<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ProviderUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProviderPortalUser
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var ProviderUser|null $user */
        $user = auth('provider')->user();

        if ($user === null) {
            return redirect()->route('provider.portal.login');
        }

        if (! $user->canLogin()) {
            auth('provider')->logout();

            return redirect()->route('provider.portal.login')
                ->withErrors(['identifier' => __('provider-portal.access_denied')]);
        }

        /*
         * A password someone else set (temporary / administrator reset) must
         * be replaced before anything else: only the profile page (where the
         * password is changed) and logout stay reachable.
         */
        if ($user->must_change_password && ! $request->routeIs('provider.portal.profile.show', 'provider.portal.profile.password', 'provider.portal.logout')) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                abort(403, __('auth.must_change_password'));
            }

            return redirect()->route('provider.portal.profile.show')
                ->with('flash', ['message' => __('auth.must_change_password'), 'type' => 'warning']);
        }

        return $next($request);
    }
}
